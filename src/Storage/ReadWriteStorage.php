<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Storage\Storage;

use League\Flysystem\Filesystem;
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
            $files = $this->filesystem->listContents($fileOrDirectoryPath);
        } else {
            $files = [$this->filesystem->getMetadata($fileOrDirectoryPath)];
        }

        return \iter\all(
            function (array $file): bool {
                $this->logger->info("Downloading: \"$file[name]\".");

                return (bool)file_put_contents($file['name'], $this->filesystem->read($file['path']));
            },
            // Only download files. Recursion not supported yet.
            \iter\filter(self::isFile(...), $files)
        );
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

        return \iter\all(function ($filespec) use ($directory): bool {
            $filename = basename($filespec);

            // Find any existing file.
            $file = $this->findFile($filename, $directory);

            $this->logger->info("Uploading: \"$filename\".");

            return $this->filesystem->put(
                $file['basename'] ?? "$directory/$filename",
                file_get_contents($filespec)
            );
        }, $files);
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

        if ($this->isDirectory($fileOrDirectoryPath)) {
            $files = $this->filesystem->listContents($fileOrDirectoryPath);
        } else {
            $files = [$this->filesystem->getMetadata($fileOrDirectoryPath)];

            // Discard file name.
            array_pop($directories);
        }

        // Mirror directory structure at destination.
        $destinationId = $this->createDirectoriesArray($directories, StorageRoot::READ_DIR);

        // Move files.
        if (!\iter\all(
            function (array $file) use ($destinationId): bool {
                // Find any existing file and delete it.
                if ($destinationFile = $this->findFile($file['name'], $destinationId)) {
                    // We have to delete because renaming to existing file ID just deletes the source file.
                    $this->filesystem->delete($destinationFile['basename']);
                }

                $this->logger->info("Moving: \"$file[name]\".");

                return $this->filesystem->rename($file['path'], "$destinationId/$file[name]");
            },
            \iter\filter(self::isFile(...), $files),
        )) {
            return false;
        }

        // Remove empty write directories.

        return \iter\all(
            function (string $directory): bool {
                $this->logger->info("Removing empty directory: \"$directory\".");

                return $this->filesystem->delete($directory);
            },
            \iter\takeWhile(
                // Directory is empty.
                fn ($directory) => !$this->filesystem->listContents($directory),
                \iter\filter(
                    // Must skip files otherwise the file we just moved is deleted.
                    fn ($fileOrDirectory) => !self::isFile($this->filesystem->getMetadata($fileOrDirectory)),
                    array_reverse(
                        // Skip root directory.
                        array_slice(self::filespecToDirectoryList($fileOrDirectoryPath), 1)
                    )
                )
            )
        );
    }

    public function delete(string $file): bool
    {
        $this->logger->info("Deleting: \"$file\"...");

        if (!$filePath = $this->findLeafObject($file, StorageRoot::WRITE_DIR)) {
            throw new \RuntimeException("Cannot delete file: \"$file\": not found.");
        }

        return $this->filesystem->delete($filePath);
    }

    public function deletePattern(string $parent, string $pattern): bool
    {
        $this->logger->info("Deleting all files matching pattern: \"$pattern\" in \"$parent\"...");

        if (!$directoryPath = $this->findLeafObject($parent, StorageRoot::WRITE_DIR)) {
            throw new \RuntimeException("Cannot delete from directory: \"$parent\": no such directory.");
        }

        return \iter\all(
            function (array $file): bool {
                $this->logger->info("Deleting: \"$file[name]\".");

                return $this->filesystem->delete($file['basename']);
            },
            \iter\filter(
                fn (array $file) => preg_match("[$pattern]", $file['name']),
                \iter\filter(
                    self::isFile(...),
                    $this->filesystem->listContents($directoryPath)
                )
            )
        );
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

        file_put_contents($todayFilename, $this->filesystem->read($today['basename']));
        file_put_contents($yesterdayFilename, $this->filesystem->read($yesterday['basename']));

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

            if (!$this->filesystem->createDir($make = "$parent/$directory")) {
                throw new \RuntimeException("Failed to create directory: \"$make\".");
            }

            $parent = $this->findDirectory($directory, $parent)['basename'];
        } while ($directories);

        return $parent;
    }

    private function isDirectory(string $path): bool
    {
        return $this->filesystem->getMetadata($path)['type'] === self::TYPE_DIRECTORY;
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
    private function find(string $filename, string $parent = '', string $type = null): ?array
    {
        $files = $this->filesystem->listContents($parent);

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
        $fileInfo['vdir'] = "$yearMonthDir[filename]/$dayDir[filename]/$fileInfo[vdir]";

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
        $day = $dayDir['filename'];

        $tries = 1;
        retry:

        $yesterday = new \DateTimeImmutable("$yearMonthData[filename]$day -$tries day");
        $yesterdayYearMonth = $yesterday->format('Ym');
        $yesterdayDay = $yesterday->format('d');

        $files = $this->filesystem->listContents($dataDir);
        $yearMonthDir = \iter\search(fn (array $v) => $v['filename'] === $yesterdayYearMonth, $files)['basename'];

        $files = $this->filesystem->listContents($yearMonthDir);
        if (!$dayDir = \iter\search(fn (array $v) => $v['filename'] === $yesterdayDay, $files)) {
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

        $files = $this->filesystem->listContents($dataDir);

        $yearMonthDir = array_filter($files, fn(array $v) => str_starts_with($v['filename'], '20'));
        usort($yearMonthDir, self::sortByFilename());
        $yearMonthDir = end($yearMonthDir);

        $files = $this->filesystem->listContents($yearMonthDir['basename']);
        usort($files, self::sortByFilename());

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
        $files = $this->filesystem->listContents($dayDir);
        usort($files, self::sortByFilename());
        $buildDir = reset($files);

        $files = $this->filesystem->listContents($buildDir['basename']);

        return \iter\search(fn (array $v) => $v['name'] === 'steam.sqlite', $files)
            + ['vdir' => $buildDir['filename']]
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

    private static function sortByFilename(): \Closure
    {
        return static fn ($a, $b) => $a['filename'] <=> $b['filename'];
    }
}
