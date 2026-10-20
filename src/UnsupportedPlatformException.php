<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use RuntimeException;

final class UnsupportedPlatformException extends RuntimeException
{
    public static function create(AbstractPlatform $platform): self
    {
        return new self(sprintf(
            'Database platform %s is not supported, supported platforms are MySQL, MariaDB and SQLite',
            $platform::class,
        ));
    }
}
