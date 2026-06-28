<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Domain;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final readonly class DomainEvent
{
    public UuidInterface $eventId;

    public DateTimeImmutable $occurredAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public string $eventType, public array $payload = [])
    {
        $this->eventId = Uuid::uuid4();
        $this->occurredAt = new DateTimeImmutable;
    }

    /**
     * @param  array<string, mixed>  $payload
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
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['eventType'],
            $data['payload']
        );
    }
}
