<?php

declare(strict_types=1);

namespace Gember\RdbmsEventStoreDoctrineDbal\Test\TestDoubles;

use Gember\DependencyContracts\Util\Generator\Identity\IdentityGenerator;
use Override;

final class TestIdentityGenerator implements IdentityGenerator
{
    /**
     * @var list<string>
     */
    public array $ids = [];

    #[Override]
    public function generate(): string
    {
        return (string) array_shift($this->ids);
    }
}
