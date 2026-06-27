<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

namespace ZeroBoiler\Domain;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final readonly class DomainEvent
{
    public UuidInterface $eventId;

    public string $eventType;

    /** @var array<string, mixed> */
    public array $payload;

    public DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $eventType, array $payload = [])
    {
        $this->eventId = Uuid::uuid4();
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->occurredAt = new DateTimeImmutable;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function occur(string $eventType, array $payload = []): self
    {
        return new self($eventType, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId->toString(),
            'eventType' => $this->eventType,
            'payload' => $this->payload,
            'occurredAt' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $event = new self(
            $data['eventType'],
            $data['payload']
        );

        return $event;
    }
}
