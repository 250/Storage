<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Storage\Storage;

enum StorageRoot
{
    case READ_DIR;
    case WRITE_DIR;

    public function getName(): string
    {
        return match ($this) {
            self::READ_DIR => 'read',
            self::WRITE_DIR => 'write',
        };
    }

    public function getDirectory(): string
    {
        return match ($this) {
            self::READ_DIR => 'data',
            self::WRITE_DIR => 'building...',
        };
    }
}
