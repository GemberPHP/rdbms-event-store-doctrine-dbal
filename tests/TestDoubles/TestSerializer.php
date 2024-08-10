<?php

declare(strict_types=1);

namespace Gember\EventStoreDoctrineDbal\Test\TestDoubles;

use Gember\EventSourcing\Util\Serialization\Serializer\Serializer;
use stdClass;

final readonly class TestSerializer implements Serializer
{
    public function __construct(
        private ?string $serialized = null,
        private ?object $deserialized = null,
    ) {}

    public function serialize(object $object): string
    {
        return $this->serialized ?? 'serialized';
    }

    public function deserialize(string $payload, string $className): object
    {
        return $this->deserialized ?? new stdClass();
    }
}
