<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Storage;

use ScriptFUSION\Steam250\Storage\Command\DeleteCommand;
use ScriptFUSION\Steam250\Storage\Command\DownloadCommand;
use ScriptFUSION\Steam250\Storage\Command\DownloadLastTwoCommand;
use ScriptFUSION\Steam250\Storage\Command\MoveCommand;
use ScriptFUSION\Steam250\Storage\Command\UploadCommand;

final class Application
{
    private $app;

    public function __construct()
    {
        $this->app = $app = new \Symfony\Component\Console\Application;

        $app->addCommands([
            new DownloadCommand,
            new UploadCommand,
            new MoveCommand,
            new DeleteCommand,
            new DownloadLastTwoCommand,
        ]);
    }

    public function start(): int
    {
        return $this->app->run();
    }
}
