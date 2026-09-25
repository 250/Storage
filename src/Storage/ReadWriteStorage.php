<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Storage\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use Psr\Log\LoggerInterface;

/**
 * Provides read/write storage that always writes to a dedicated write directory (or subdirectory thereof) and always
 * reads from a dedicated read directory. Files can be moved from the write directory to the read directory.
 */
class ReadWriteStorage
{
    private const TYPE_FILE = 'file';
    private const TYPE_DIRECTORY = 'dir';

    public function __construct(private Filesystem $filesystem, private LoggerInterface $logger)
    {
    }

    /**
     * Downloads the specified file or directory contents from the specified root directory.
     *
     * @param string $filespec File or directory path, separated by slashes ('/').
     * @param StorageRoot $root Root directory.
     *
     * @return bool True if all files were downloaded successfully, otherwise false.
     */
    public function download(string $filespec, StorageRoot $root): bool
    {
        $this->logger->info("Downloading: \"$filespec\"...");

        if (!$fileOrDirectoryPath = $this->findLeafObject($filespec, $root)) {
            throw new \RuntimeException("File not found in {$root->getName()} directory: \"$filespec\".");
        }

        if ($this->isDirectory($fileOrDirectoryPath)) {
            $files = $this->list($fileOrDirectoryPath);
        } else {
            $files = [$this->stat($fileOrDirectoryPath)];
        }

        try {
            return \iter\all(
                function (array $file): bool {
                    $this->logger->info("Downloading: \"$file[name]\".");

                    $source = $this->filesystem->readStream($file['path']);
                    $destination = fopen($file['name'], 'wb');

                    try {
                        return (bool)stream_copy_to_stream($source, $destination);
                    } finally {
                        // The adapter may hand back a stream it already closed.
                        if (is_resource($source)) {
                            fclose($source);
                        }

                        if (is_resource($destination)) {
                            fclose($destination);
                        }
                    }
                },
                // Only download files. Recursion not supported yet.
                \iter\filter(self::isFile(...), $files)
            );
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Uploads the specified file or directory to the specified parent directory. Any existing files are overwritten.
     *
     * @param string $fileSpec Local file.
     * @param string $parent Optional. Parent directory.
     *
     * @return bool True if the file was uploaded successfully, otherwise false.
     */
    public function upload(string $fileSpec, string $parent = ''): bool
    {
        $this->logger->info("Uploading: \"$fileSpec\"...");

        $directory = $this->createDirectories($parent);

        if (is_dir($fileSpec)) {
            $files = iterator_to_array(\iter\map(
                fn (\DirectoryIterator $iterator) => $iterator->getPathname(),
                \iter\filter(
                    fn (\DirectoryIterator $iterator) => $iterator->isFile(),
                    new \DirectoryIterator($fileSpec),
                ),
            ));
        } else {
            $files = [$fileSpec];
        }

        try {
            return \iter\all(function ($filespec) use ($directory): bool {
                $filename = basename($filespec);

                $this->logger->info("Uploading: \"$filename\".");

                $stream = fopen($filespec, 'rb');

                try {
                    $this->filesystem->writeStream("$directory/$filename", $stream);
                } finally {
                    // The adapter may have already closed the stream during upload.
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                return true;
            }, $files);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Moves a file or directory from the write root to the read root.
     *
     * @param string $filespec File or directory path, separated by slashes ('/').
     *
     * @return bool True if all files were moved successfully, otherwise false.
     */
    public function moveUploadedFile(string $filespec): bool
    {
        $this->logger->info("Moving: \"$filespec\"...");

        if (!$fileOrDirectoryPath = $this->findLeafObject($filespec, StorageRoot::WRITE_DIR)) {
            throw new \RuntimeException("Cannot move file \"$filespec\": not found.");
        }

        $directories = self::filespecToDirectoryList($filespec);

        $isDirectory = $this->isDirectory($fileOrDirectoryPath);

        if ($isDirectory) {
            $files = $this->list($fileOrDirectoryPath);
        } else {
            $files = [$this->stat($fileOrDirectoryPath)];

            // Discard file name.
            array_pop($directories);
        }

        // Mirror directory structure at destination.
        $destinationId = $this->createDirectoriesArray($directories, StorageRoot::READ_DIR);

        try {
            // Move files.
            if (!\iter\all(
                function (array $file) use ($destinationId): bool {
                    // Find any existing file and delete it.
                    if ($destinationFile = $this->findFile($file['name'], $destinationId)) {
                        // We have to delete because moving onto an existing file just deletes the source file.
                        $this->filesystem->delete($destinationFile['basename']);
                    }

                    $this->logger->info("Moving: \"$file[name]\".");

                    $this->filesystem->move($file['path'], "$destinationId/$file[name]");

                    return true;
                },
                \iter\filter(self::isFile(...), $files),
            )) {
                return false;
            }

            // Remove empty write directories, from the deepest upwards, stopping at the first non-empty one and
            // never removing the write root itself.
            $segments = self::filespecToDirectoryList($fileOrDirectoryPath);

            if (!$isDirectory) {
                array_pop($segments);
            }

            $rootSegments = \count(self::filespecToDirectoryList(StorageRoot::WRITE_DIR->getDirectory()));

            while (\count($segments) > $rootSegments) {
                $directory = implode('/', $segments);

                // Stop descending when hitting a file or a non-empty directory.
                if ($this->filesystem->fileExists($directory) || [] !== $this->list($directory)) {
                    break;
                }

                $this->logger->info("Removing empty directory: \"$directory\".");

                $this->filesystem->deleteDirectory($directory);

                array_pop($segments);
            }
        } catch (FilesystemException) {
            return false;
        }

        return true;
    }

    public function delete(string $file): bool
    {
        $this->logger->info("Deleting: \"$file\"...");

        if (!$filePath = $this->findLeafObject($file, StorageRoot::WRITE_DIR)) {
            throw new \RuntimeException("Cannot delete file: \"$file\": not found.");
        }

        try {
            $this->filesystem->delete($filePath);
        } catch (FilesystemException) {
            return false;
        }

        return true;
    }

    public function deletePattern(string $parent, string $pattern): bool
    {
        $this->logger->info("Deleting all files matching pattern: \"$pattern\" in \"$parent\"...");

        if (!$directoryPath = $this->findLeafObject($parent, StorageRoot::WRITE_DIR)) {
            throw new \RuntimeException("Cannot delete from directory: \"$parent\": no such directory.");
        }

        try {
            return \iter\all(
                function (array $file): bool {
                    $this->logger->info("Deleting: \"$file[name]\".");

                    $this->filesystem->delete($file['basename']);

                    return true;
                },
                \iter\filter(
                    fn (array $file) => preg_match("[$pattern]", $file['name']),
                    \iter\filter(
                        self::isFile(...),
                        $this->list($directoryPath)
                    )
                )
            );
        } catch (FilesystemException) {
            return false;
        }
    }

    public function downloadLastTwoSnapshots(): void
    {
        $today = $this->fetchLatestDatabaseSnapshot();
        $yesterday = $this->fetchPreviousDatabaseSnapshot();

        $todayFilename = "$today[vdir].$today[name]";
        $yesterdayFilename = "$yesterday[vdir].$yesterday[name]";

        foreach ([&$todayFilename, &$yesterdayFilename] as &$filename) {
            $filename = str_replace('/', '_', $filename);
        }

        self::copyToLocal($this->filesystem, $today['basename'], $todayFilename);
        self::copyToLocal($this->filesystem, $yesterday['basename'], $yesterdayFilename);

        echo
            " 0:\t$today[vdir]\t$todayFilename\n",
            "-1:\t$yesterday[vdir]\t$yesterdayFilename\n"
        ;
    }

    /**
     * Creates one or more directories, separated by a slash ('/'), as required.
     * If directories already exist, no new directories will be created.
     *
     * @param string $directories
     *
     * @return string Leaf directory identifier.
     */
    public function createDirectories(string $directories): string
    {
        return $this->createDirectoriesArray(self::filespecToDirectoryList($directories));
    }

    private function createDirectoriesArray(array $directories, StorageRoot $root = StorageRoot::WRITE_DIR): string
    {
        $directories = array_merge(
            self::filespecToDirectoryList($root->getDirectory()),
            $directories
        );

        $parent = '';

        do {
            $directory = array_shift($directories);

            if ($response = $this->findDirectory($directory, $parent)) {
                $parent = $response['basename'];

                continue;
            }

            $make = $parent === '' ? $directory : "$parent/$directory";

            try {
                $this->filesystem->createDirectory($make);
            } catch (FilesystemException $exception) {
                throw new \RuntimeException("Failed to create directory: \"$make\".", previous: $exception);
            }

            $parent = $this->findDirectory($directory, $parent)['basename'];
        } while ($directories);

        return $parent;
    }

    private function isDirectory(string $path): bool
    {
        return $this->filesystem->directoryExists($path);
    }

    /**
     * Lists the contents of a directory as normalized file information arrays.
     *
     * @return array<array{path: string, name: string, basename: string, type: string}>
     */
    private function list(string $directory): array
    {
        $files = [];

        foreach ($this->filesystem->listContents($directory) as $item) {
            $files[] = self::normalize($item);
        }

        return $files;
    }

    /**
     * Describes a single file or directory as a file information array.
     *
     * @return array{path: string, name: string, basename: string, type: string}
     */
    private function stat(string $path): array
    {
        return [
            'path' => $path,
            'name' => basename($path),
            'basename' => $path,
            'type' => $this->isDirectory($path) ? self::TYPE_DIRECTORY : self::TYPE_FILE,
        ];
    }

    /**
     * Normalizes storage attributes to a file information array.
     *
     * @return array{path: string, name: string, basename: string, type: string}
     */
    private static function normalize(StorageAttributes $item): array
    {
        return [
            'path' => $item->path(),
            'name' => basename($item->path()),
            'basename' => $item->path(),
            'type' => $item->isFile() ? self::TYPE_FILE : self::TYPE_DIRECTORY,
        ];
    }

    private static function copyToLocal(Filesystem $filesystem, string $source, string $destination): void
    {
        $stream = $filesystem->readStream($source);
        $file = fopen($destination, 'wb');

        try {
            stream_copy_to_stream($stream, $file);
        } finally {
            // The adapter may hand back a stream it already closed.
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    /**
     * Finds a file with the specified name within the specified parent of the specified type.
     * If type is not specified, any type will match.
     *
     * @param string $filename File name.
     * @param string $parent Optional. Parent directory identifier.
     * @param string|null $type Optional. File type.
     *
     * @return array|null File metadata if found, otherwise null.
     */
    private function find(string $filename, string $parent = '', ?string $type = null): ?array
    {
        $files = $this->list($parent);

        return \iter\search(static function (array $v) use ($filename, $type): bool {
            if ($type !== null && $v['type'] !== $type) {
                return false;
            }

            return $v['name'] === $filename;
        }, $files);
    }

    private function findFile(string $dirName, string $parent = ''): ?array
    {
        return $this->find($dirName, $parent, self::TYPE_FILE);
    }

    private function findDirectory(string $dirName, string $parent = ''): ?array
    {
        return $this->find($dirName, $parent, self::TYPE_DIRECTORY);
    }

    private function findLeafObject(string $filespec, StorageRoot $root = StorageRoot::READ_DIR): ?string
    {
        $directories = array_merge(
            self::filespecToDirectoryList($root->getDirectory()),
            self::filespecToDirectoryList($filespec)
        );

        $parent = '';

        do {
            $directory = array_shift($directories);

            if (!$response = $this->find($directory, $parent)) {
                return null;
            }

            $parent = $response['path'];
        } while ($directories);

        return $parent;
    }

    private function fetchLatestDatabaseSnapshot(): array
    {
        [$dayDir, $yearMonthDir] = $this->findLatestDayDir();

        $fileInfo = $this->findLatestBuildDatabaseSnapshot($dayDir['basename']);
        $fileInfo['vdir'] = "$yearMonthDir[name]/$dayDir[name]/$fileInfo[vdir]";

        return $fileInfo;
    }

    /**
     * Fetches the previous database snapshot by searching the previous seven days' snapshot folders for the latest
     * snapshot in each. Typically, it will find the latest snapshot from yesterday, unless the build was missed.
     *
     * @return array Database snapshot file information.
     */
    private function fetchPreviousDatabaseSnapshot(): array
    {
        $dataDir = $this->findRootDir();

        [$dayDir, $yearMonthData] = $this->findLatestDayDir();
        $day = $dayDir['name'];

        $tries = 1;
        retry:

        $yesterday = new \DateTimeImmutable("$yearMonthData[name]$day -$tries day");
        $yesterdayYearMonth = $yesterday->format('Ym');
        $yesterdayDay = $yesterday->format('d');

        $files = $this->list($dataDir);
        $yearMonthDir = \iter\search(fn (array $v) => $v['name'] === $yesterdayYearMonth, $files)['basename'];

        $files = $this->list($yearMonthDir);
        if (!$dayDir = \iter\search(fn (array $v) => $v['name'] === $yesterdayDay, $files)) {
            if ($tries++ <= 7) {
                fwrite(STDERR, "No match for $yesterdayYearMonth/$yesterdayDay...\n");

                goto retry;
            }

            throw new \RuntimeException('Cannot fetch previous database snapshot: none built in the last 7 days!');
        }

        $fileInfo = $this->findLatestBuildDatabaseSnapshot($dayDir['basename']);
        $fileInfo['vdir'] = "$yesterdayYearMonth/$yesterdayDay/$fileInfo[vdir]";

        return $fileInfo;
    }

    private function findLatestDayDir(): array
    {
        $dataDir = $this->findRootDir();

        $files = $this->list($dataDir);

        $yearMonthDir = array_filter($files, fn(array $v) => str_starts_with($v['name'], '20'));
        usort($yearMonthDir, self::sortByName());
        $yearMonthDir = end($yearMonthDir);

        $files = $this->list($yearMonthDir['basename']);
        usort($files, self::sortByName());

        return [end($files), $yearMonthDir];
    }

    private function findRootDir(): string
    {
        return $this->findDirectory(StorageRoot::READ_DIR->getDirectory())['basename'];
    }

    /**
     * Downloads the latest build from the specified day directory.
     *
     * @param string $dayDir Directory name for the day of the month.
     *
     * @return array
     */
    private function findLatestBuildDatabaseSnapshot(string $dayDir): array
    {
        $files = $this->list($dayDir);
        usort($files, self::sortByName());
        $buildDir = end($files);

        $files = $this->list($buildDir['basename']);

        return \iter\search(fn (array $v) => $v['name'] === 'steam.sqlite', $files)
            + ['vdir' => $buildDir['name']]
        ;
    }

    private static function filespecToDirectoryList(string $filespec): array
    {
        if ('' === $filespec) {
            return [];
        }

        return explode('/', $filespec);
    }

    private static function isFile(array $file): bool
    {
        return $file['type'] === self::TYPE_FILE;
    }

    private static function sortByName(): \Closure
    {
        return static fn ($a, $b) => $a['name'] <=> $b['name'];
    }
}
