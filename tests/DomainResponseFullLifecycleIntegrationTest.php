<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace Tests\Domain\Response;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Domain\AggregateRoot;
use ZeroBoiler\Domain\AggregateRootId;
use ZeroBoiler\Domain\Concerns\EventSourced;
use ZeroBoiler\Domain\Contracts\UnitOfWork;
use ZeroBoiler\Domain\Identifiers\UuidIdentifier;
use ZeroBoiler\Domain\InMemoryUnitOfWork;
use ZeroBoiler\Domain\Exceptions\InvalidStateDomainException;
use ZeroBoiler\Domain\Exceptions\NotFoundDomainException;
use ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore;
use ZeroBoiler\Domain\Snapshots\SnapshottingRepository;
use ZeroBoiler\Domain\ValueObject;
use ZeroBoiler\Response\ApiResponse;
use ZeroBoiler\Response\Concerns\ExtractsDomainId;
use ZeroBoiler\Response\Exceptions\TransformationException;
use ZeroBoiler\Response\Transformers\DomainResponseFactory;
use ZeroBoiler\Response\Transformers\DomainTransformer;
use ZeroBoiler\Response\ViewModel;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Comprehensive cross-package integration test.
 *
 * Validates the complete data flow from domain aggregate roots through
 * the response layer: creation → mutation → event sourcing → snapshotting
 * → transformation → API response → JSON serialization.
 *
 * This test covers the following integration points:
 *
 * 1. AggregateRoot → toArray() → DomainTransformer → ApiResponse
 * 2. DomainException → DomainResponseFactory::fromException() → RFC 9457
 * 3. AggregateRootId → JSON serialization → round-trip
 * 4. UuidIdentifier → domain identity → response ID extraction
 * 5. ValueObject → toArray() → ViewModel → ApiResponse
 * 6. Unit of Work → commit → event dispatch → response
 * 7. SnapshottingRepository → reconstitution → response consistency
 * 8. DomainTransformer → collection transformation → paginated response
 * 9. ExtractsDomainId trait → duck-typed ID resolution
 * 10. DomainResponseFactory CRUD responses (created/updated/deleted)
 *
 * @covers \ZeroBoiler\Domain\AggregateRoot
 * @covers \ZeroBoiler\Domain\AggregateRootId
 * @covers \ZeroBoiler\Domain\Identifiers\UuidIdentifier
 * @covers \ZeroBoiler\Domain\InMemoryUnitOfWork
 * @covers \ZeroBoiler\Domain\ValueObject
 * @covers \ZeroBoiler\Domain\Exceptions\NotFoundDomainException
 * @covers \ZeroBoiler\Domain\Exceptions\InvalidStateDomainException
 * @covers \ZeroBoiler\Domain\Snapshots\SnapshottingRepository
 * @covers \ZeroBoiler\Domain\Snapshots\InMemorySnapshotStore
 * @covers \ZeroBoiler\Domain\Concerns\EventSourced
 * @covers \ZeroBoiler\Response\ApiResponse
 * @covers \ZeroBoiler\Response\Transformers\DomainResponseFactory
 * @covers \ZeroBoiler\Response\Transformers\DomainTransformer
 * @covers \ZeroBoiler\Response\ViewModel
 * @covers \ZeroBoiler\Response\Concerns\ExtractsDomainId
 */
final class DomainResponseFullLifecycleIntegrationTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────
    // Fixtures
    // ──────────────────────────────────────────────────────────────────

    /** Test aggregate with event sourcing support. */
    private TestOrder $order;

    /** Unit of work for transaction management. */
    private InMemoryUnitOfWork $uow;

    protected function setUp(): void
    {
        $this->order = TestOrder::create(AggregateRootId::generate());
        $this->uow = new InMemoryUnitOfWork;
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. AggregateRoot → toArray() → DomainTransformer → ApiResponse
    // ──────────────────────────────────────────────────────────────────

    public function testAggregateToArrayContainsIdVersionAndType(): void
    {
        $array = $this->order->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertSame('TestOrder', $array['type']);
        $this->assertSame(1, $array['version']);
        $this->assertNotEmpty($array['id']);
    }

    public function testDomainTransformerMapsAggregateFields(): void
    {
        $transformer = new TestOrderTransformer;

        $result = $transformer->transform($this->order);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame('pending', $result['status']);
        $this->assertSame(0.0, $result['total']);
    }

    public function testDomainTransformerExtractsMeta(): void
    {
        $transformer = new TestOrderTransformer;

        $result = $transformer->transform($this->order);

        $this->assertArrayHasKey('_meta', $result);
        $this->assertArrayHasKey('version', $result['_meta']);
        $this->assertArrayHasKey('type', $result['_meta']);
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. DomainException → RFC 9457 Response
    // ──────────────────────────────────────────────────────────────────

    public function testNotFoundDomainExceptionSerializesToRfc9457(): void
    {
        $exception = NotFoundDomainException::forAggregate(TestOrder::class, 'abc-123');
        $errorArray = $exception->toErrorArray();

        $this->assertSame('NOT_FOUND', $errorArray['code']);
        $this->assertStringContainsString('TestOrder', $errorArray['title']);
        $this->assertStringContainsString('abc-123', $errorArray['detail']);
    }

    public function testInvalidStateDomainExceptionSerializesToRfc9457(): void
    {
        $exception = InvalidStateDomainException::because('Order is already paid');
        $errorArray = $exception->toErrorArray();

        $this->assertSame('INVALID_STATE', $errorArray['code']);
        $this->assertStringContainsString('already paid', $errorArray['detail']);
    }

    public function testDomainResponseFactoryFromException(): void
    {
        $exception = NotFoundDomainException::forAggregate(TestOrder::class, 'xyz');
        $response = DomainResponseFactory::fromException($exception);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame(404, $response->getStatus());

        $errors = $response->getErrors();
        $this->assertNotEmpty($errors);
        $this->assertSame('NOT_FOUND', $errors[0]['code']);
    }

    public function testApiResponseFromDomainException(): void
    {
        $exception = InvalidStateDomainException::because('Cannot modify completed order');
        $response = ApiResponse::fromException($exception);

        $this->assertSame(400, $response->getStatus());
        $errors = $response->getErrors();
        $this->assertSame('INVALID_STATE', $errors[0]['code']);
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. AggregateRootId → JSON Round-Trip
    // ──────────────────────────────────────────────────────────────────

    public function testAggregateRootIdJsonRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $json = json_encode($id);
        $array = json_decode($json, true);

        $restored = AggregateRootId::fromString($array);

        $this->assertTrue($id->equals($restored));
        $this->assertSame($id->toString(), $restored->toString());
    }

    public function testAggregateRootIdArrayRoundTrip(): void
    {
        $id = AggregateRootId::generate();
        $array = $id->toArray();

        $this->assertArrayHasKey('uuid', $array);

        $restored = AggregateRootId::fromArray($array);

        $this->assertTrue($id->equals($restored));
    }

    public function testAggregateRootIdValidation(): void
    {
        $this->assertFalse(AggregateRootId::isValid(''));
        $this->assertFalse(AggregateRootId::isValid('not-a-uuid'));
        $this->assertTrue(AggregateRootId::isValid('9b1e7c3f-4f5a-4e6b-8d7c-9a0b1c2d3e4f'));
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. UuidIdentifier → Domain Identity → Response ID
    // ──────────────────────────────────────────────────────────────────

    public function testUuidIdentifierImmutability(): void
    {
        $id = TestOrderId::generate();

        $this->assertNotEmpty($id->toString());
        $this->assertNotEmpty($id->toArray()['uuid']);

        $json = json_encode($id);
        $restored = TestOrderId::fromString(json_decode($json, true));

        $this->assertTrue($id->equals($restored));
    }

    public function testUuidIdentifierFromRestoredArray(): void
    {
        $id = TestOrderId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $array = $id->toArray();
        $restored = TestOrderId::fromArray($array);

        $this->assertSame($id->toString(), $restored->toString());
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. ValueObject → toArray() → ViewModel → ApiResponse
    // ──────────────────────────────────────────────────────────────────

    public function testValueObjectArrayRoundTrip(): void
    {
        $money = TestMoney::fromArray(['amount' => 99.99, 'currency' => 'USD']);
        $array = $money->toArray();

        $restored = TestMoney::fromArray($array);

        $this->assertTrue($money->equals($restored));
    }

    public function testValueObjectJsonSerialization(): void
    {
        $money = TestMoney::fromArray(['amount' => 49.50, 'currency' => 'EUR']);
        $json = json_encode($money);

        $data = json_decode($json, true);

        $this->assertSame(49.50, $data['amount']);
        $this->assertSame('EUR', $data['currency']);
    }

    public function testValueObjectEquality(): void
    {
        $a = TestMoney::fromArray(['amount' => 10.0, 'currency' => 'USD']);
        $b = TestMoney::fromArray(['amount' => 10.0, 'currency' => 'USD']);
        $c = TestMoney::fromArray(['amount' => 20.0, 'currency' => 'USD']);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. Unit of Work → Commit → Event Dispatch
    // ──────────────────────────────────────────────────────────────────

    public function testUnitOfWorkTracksAggregate(): void
    {
        $this->uow->track($this->order);

        $this->assertTrue($this->uow->isTracking($this->order));
    }

    public function testUnitOfWorkCommitDispatchesEvents(): void
    {
        $dispatched = [];
        $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $this->uow->track($this->order);
        $this->uow->begin();

        // Record events via aggregate mutation
        $this->order->applyDomainEvent(DomainEvent::occur('order.test_event', ['key' => 'value']));

        $this->uow->commit();

        $this->assertContains('order.test_event', $dispatched);
    }

    public function testUnitOfWorkRunAutoCommit(): void
    {
        $dispatched = [];
        $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $result = $this->uow->run(function () use (&$dispatched): string {
            $this->order->applyDomainEvent(DomainEvent::occur('order.auto_event', []));
            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertContains('order.auto_event', $dispatched);
    }

    public function testUnitOfWorkRollbackDiscardsEvents(): void
    {
        $dispatched = [];
        $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $this->uow->track($this->order);
        $this->uow->begin();
        $this->order->applyDomainEvent(DomainEvent::occur('order.rollback_event', []));
        $this->uow->rollback();

        $this->assertEmpty($dispatched);
        $this->assertFalse($this->order->hasUncommittedEvents());
    }

    public function testUnitOfWorkCommittedReturnsTrackedAggregates(): void
    {
        $dispatched = [];
        $this->uow->setEventDispatcher(function (DomainEvent $event): void {});

        $this->uow->track($this->order);
        $this->uow->begin();
        $this->uow->commit();

        $committed = $this->uow->getCommitted();
        $this->assertArrayHasKey($this->order->id(), $committed);
        $this->assertSame($this->order, $committed[$this->order->id()]);
    }

    // ──────────────────────────────────────────────────────────────────
    // 7. Snapshotting → Reconstitution → Response Consistency
    // ──────────────────────────────────────────────────────────────────

    public function testSnapshotRestoresAggregateState(): void
    {
        $store = new InMemorySnapshotStore;

        // Create snapshot from aggregate
        $snapshot = $this->order->createSnapshot($store);

        $this->assertNotNull($snapshot);
        $this->assertSame($this->order->id(), $snapshot->aggregateId);
        $this->assertSame(1, $snapshot->version);

        // Verify store persistence
        $loaded = $store->load(TestOrder::class, $this->order->id());
        $this->assertNotNull($loaded);
        $this->assertSame($this->order->id(), $loaded->aggregateId);
    }

    public function testSnapshotToArrayRoundTrip(): void
    {
        $store = new InMemorySnapshotStore;
        $snapshot = $this->order->createSnapshot($store);
        $array = $snapshot->toArray();

        $this->assertArrayHasKey('aggregate_type', $array);
        $this->assertArrayHasKey('aggregate_id', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('state', $array);
        $this->assertArrayHasKey('created_at', $array);

        $restored = \ZeroBoiler\Domain\Snapshots\Snapshot::fromArray($array);

        $this->assertSame($snapshot->aggregateType, $restored->aggregateType);
        $this->assertSame($snapshot->aggregateId, $restored->aggregateId);
        $this->assertSame($snapshot->version, $restored->version);
    }

    public function testSnapshotStoreStatsAndCount(): void
    {
        $store = new InMemorySnapshotStore;

        $this->assertSame(0, $store->count());

        $this->order->createSnapshot($store);

        $this->assertSame(1, $store->count());
        $this->assertSame(1, $store->count(TestOrder::class));
        $this->assertSame(0, $store->count('NonExistent'));

        $stats = $store->stats();
        $this->assertSame(1, $stats['total']);
        $this->assertArrayHasKey(TestOrder::class, $stats['by_type']);
    }

    public function testSnapshotStorePurgeByType(): void
    {
        $store = new InMemorySnapshotStore;

        $this->order->createSnapshot($store);
        $this->assertSame(1, $store->count(TestOrder::class));

        $removed = $store->purge(TestOrder::class);
        $this->assertSame(1, $removed);
        $this->assertSame(0, $store->count(TestOrder::class));
    }

    public function testSnapshotStorePurgeAll(): void
    {
        $store = new InMemorySnapshotStore;

        $this->order->createSnapshot($store);
        $this->assertSame(1, $store->count());

        $removed = $store->purge();
        $this->assertSame(1, $removed);
        $this->assertSame(0, $store->count());
    }

    public function testSnapshotStoreDeleteOlderThan(): void
    {
        $store = new InMemorySnapshotStore;

        // Create initial snapshot at version 1
        $this->order->createSnapshot($store);

        // Simulate a newer snapshot at a higher version
        $newerSnapshot = \ZeroBoiler\Domain\Snapshots\Snapshot::create(
            aggregateType: TestOrder::class,
            aggregateId: $this->order->id(),
            version: 10,
            state: ['status' => 'completed', 'total' => 100.0],
        );
        $store->save($newerSnapshot);

        // Delete versions older than 5
        $store->deleteOlderThan(TestOrder::class, $this->order->id(), 5);

        $loaded = $store->load(TestOrder::class, $this->order->id());
        $this->assertNotNull($loaded);
        $this->assertSame(10, $loaded->version);
    }

    // ──────────────────────────────────────────────────────────────────
    // 8. DomainTransformer → Collection → Paginated Response
    // ──────────────────────────────────────────────────────────────────

    public function testDomainTransformerCollectionTransformation(): void
    {
        $orders = [
            TestOrder::create(AggregateRootId::generate()),
            TestOrder::create(AggregateRootId::generate()),
            TestOrder::create(AggregateRootId::generate()),
        ];

        $transformer = new TestOrderTransformer;
        $results = $transformer->transformCollection($orders);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertArrayHasKey('id', $result);
            $this->assertArrayHasKey('status', $result);
            $this->assertArrayHasKey('_meta', $result);
        }
    }

    public function testDomainResponseFactoryCollectionResponse(): void
    {
        $orders = [
            TestOrder::create(AggregateRootId::generate()),
            TestOrder::create(AggregateRootId::generate()),
        ];

        $data = array_map(fn (TestOrder $o): array => $o->toArray(), $orders);
        $response = DomainResponseFactory::collection($data);

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame(200, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('items', $responseData);
        $this->assertCount(2, $responseData['items']);
    }

    public function testDomainResponseFactoryPaginatedCollection(): void
    {
        $orders = [];
        for ($i = 0; $i < 25; $i++) {
            $order = TestOrder::create(AggregateRootId::generate());
            $orders[] = $order->toArray();
        }

        $response = DomainResponseFactory::paginatedCollection(
            data: $orders,
            meta: ['total' => 100, 'per_page' => 25, 'current_page' => 1],
            links: ['self' => '/api/orders?page=1', 'next' => '/api/orders?page=2'],
        );

        $this->assertSame(200, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(25, $responseData['data']);
        $this->assertArrayHasKey('meta', $responseData);
        $this->assertArrayHasKey('links', $responseData);
    }

    // ──────────────────────────────────────────────────────────────────
    // 9. ExtractsDomainId Trait
    // ──────────────────────────────────────────────────────────────────

    public function testExtractsDomainIdFromAggregateRoot(): void
    {
        $id = ExtractsDomainId::extractId($this->order);

        $this->assertSame($this->order->id(), $id);
    }

    public function testExtractsDomainIdFromAggregateRootId(): void
    {
        $aggregateId = AggregateRootId::generate();
        $id = ExtractsDomainId::extractId($aggregateId);

        $this->assertSame($aggregateId->toString(), $id);
    }

    public function testExtractsDomainIdFromUuidIdentifier(): void
    {
        $uuidId = TestOrderId::generate();
        $id = ExtractsDomainId::extractId($uuidId);

        $this->assertSame($uuidId->toString(), $id);
    }

    public function testExtractsDomainIdFromStringableObject(): void
    {
        $stringable = new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'stringable-id-123';
            }
        };

        $id = ExtractsDomainId::extractId($stringable);

        $this->assertSame('stringable-id-123', $id);
    }

    public function testExtractsDomainIdFallbackToSplObjectId(): void
    {
        $object = new \stdClass;
        $id = ExtractsDomainId::extractId($object);

        $this->assertNotEmpty($id);
        $this->assertIsNumeric($id);
    }

    // ──────────────────────────────────────────────────────────────────
    // 10. DomainResponseFactory CRUD Responses
    // ──────────────────────────────────────────────────────────────────

    public function testDomainResponseFactoryCreatedResponse(): void
    {
        $response = DomainResponseFactory::created(
            $this->order,
            $this->order->toArray(),
        );

        $this->assertSame(201, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('_id', $responseData);
        $this->assertArrayHasKey('_version', $responseData);
        $this->assertArrayHasKey('links', $responseData);
        $this->assertArrayHasKey('self', $responseData['links']);
    }

    public function testDomainResponseFactoryUpdatedResponse(): void
    {
        $response = DomainResponseFactory::updated(
            $this->order,
            $this->order->toArray(),
        );

        $this->assertSame(200, $response->getStatus());

        $responseData = $response->getData();
        $this->assertArrayHasKey('_id', $responseData);
        $this->assertArrayHasKey('_version', $responseData);
    }

    public function testDomainResponseFactoryDeletedResponse(): void
    {
        $response = DomainResponseFactory::deleted();

        $this->assertSame(204, $response->getStatus());
    }

    public function testDomainResponseFactoryAcceptedResponse(): void
    {
        $response = DomainResponseFactory::accepted(['message' => 'Processing']);

        $this->assertSame(202, $response->getStatus());

        $responseData = $response->getData();
        $this->assertSame('Processing', $responseData['message']);
    }

    public function testDomainResponseFactoryErrorResponse(): void
    {
        $response = DomainResponseFactory::error(
            errors: [['title' => 'Validation Error', 'code' => 'VALIDATION_FAILED']],
            status: 422,
        );

        $this->assertSame(422, $response->getStatus());
        $this->assertNotEmpty($response->getErrors());
    }

    // ──────────────────────────────────────────────────────────────────
    // Full Lifecycle: Aggregate Creation → Mutation → Event → Response
    // ──────────────────────────────────────────────────────────────────

    public function testFullLifecycleCreateMutateRespond(): void
    {
        // 1. Create aggregate
        $order = TestOrder::create(AggregateRootId::generate());
        $this->assertSame(1, $order->version());
        $this->assertSame('pending', $order->status);

        // 2. Mutate aggregate (raises events)
        $order->setStatus('processing');
        $order->setTotal(150.50);
        $this->assertSame(2, $order->version());

        // 3. Transform to response via DomainResponseFactory
        $response = DomainResponseFactory::entity(
            $order,
            $order->toArray(),
        );

        $this->assertSame(200, $response->getStatus());

        // 4. Verify response data
        $data = $response->getData();
        $this->assertSame($order->id(), $data['id']);
        $this->assertSame(2, $data['version']);
        $this->assertSame('processing', $data['status']);
        $this->assertSame(150.50, $data['total']);
        $this->assertArrayHasKey('_id', $data);
        $this->assertArrayHasKey('_version', $data);

        // 5. Verify JSON serialization
        $json = json_encode($response->toArray());
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('data', $decoded);
        $this->assertSame($order->id(), $decoded['data']['id']);
    }

    public function testFullLifecycleWithUnitOfWork(): void
    {
        $dispatched = [];
        $this->uow->setEventDispatcher(function (DomainEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->eventType;
        });

        $this->uow->setPersistenceCallback(function (AggregateRoot $aggregate): void {
            // Simulate persistence
        });

        $result = $this->uow->run(function (): ApiResponse {
            // Track and mutate aggregate
            $this->uow->track($this->order);
            $this->order->setStatus('paid');
            $this->order->setTotal(99.99);

            // Build response
            return DomainResponseFactory::created(
                $this->order,
                $this->order->toArray(),
            );
        });

        $this->assertInstanceOf(ApiResponse::class, $result);
        $this->assertSame(201, $result->getStatus());
        $this->assertNotEmpty($dispatched);
    }

    public function testFullLifecycleEventSourcingReconstitution(): void
    {
        $events = $this->order->pullDomainEvents();

        $this->assertNotEmpty($events);

        // Reconstitute from events
        $reconstituted = TestOrder::fromHistory(...$events->all());

        $this->assertSame($this->order->id(), $reconstituted->id());
        $this->assertSame(1, $reconstituted->version());
    }

    public function testDomainEventCollectionSerdeRoundTrip(): void
    {
        $events = $this->order->pullDomainEvents();
        $array = $events->toArray();

        $this->assertNotEmpty($array);
        $this->assertIsArray($array[0]);
        $this->assertArrayHasKey('event_type', $array[0]);
        $this->assertArrayHasKey('payload', $array[0]);

        $restored = \ZeroBoiler\Domain\DomainEventCollection::fromArray($array);

        $this->assertCount($events->count(), $restored);
        $this->assertSame($events->count(), $restored->count());
    }

    public function testDomainEventCollectionJsonSerialize(): void
    {
        $events = $this->order->pullDomainEvents();
        $json = json_encode($events);

        $this->assertNotEmpty($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount($events->count(), $decoded);
    }

    public function testDomainEventCollectionFilterAndMap(): void
    {
        $e1 = DomainEvent::occur('order.created', ['id' => '1']);
        $e2 = DomainEvent::occur('order.updated', ['id' => '1']);
        $e3 = DomainEvent::occur('order.created', ['id' => '2']);

        $collection = \ZeroBoiler\Domain\DomainEventCollection::fromEvents($e1, $e2, $e3);

        $created = $collection->filter(
            fn (DomainEvent $e): bool => $e->eventType === 'order.created'
        );

        $this->assertSame(2, $created->count());

        $types = $collection->map(
            fn (DomainEvent $e): string => $e->eventType
        );

        $this->assertSame(['order.created', 'order.updated', 'order.created'], $types);
    }

    public function testDomainEventCollectionFirstLastGet(): void
    {
        $e1 = DomainEvent::occur('first', []);
        $e2 = DomainEvent::occur('second', []);
        $e3 = DomainEvent::occur('third', []);

        $collection = \ZeroBoiler\Domain\DomainEventCollection::fromEvents($e1, $e2, $e3);

        $this->assertSame('first', $collection->first()?->eventType);
        $this->assertSame('third', $collection->last()?->eventType);
        $this->assertSame('second', $collection->get(1)?->eventType);
        $this->assertNull($collection->get(99));
    }

    // ──────────────────────────────────────────────────────────────────
    // Entity Equality and Immutability
    // ──────────────────────────────────────────────────────────────────

    public function testEntityEqualitySameId(): void
    {
        $id = AggregateRootId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $order1 = TestOrder::create($id);
        $order2 = TestOrder::create($id);

        $this->assertTrue($order1->equals($order2));
    }

    public function testEntityEqualityDifferentId(): void
    {
        $order1 = TestOrder::create(AggregateRootId::generate());
        $order2 = TestOrder::create(AggregateRootId::generate());

        $this->assertFalse($order1->equals($order2));
    }

    public function testEntityJsonSerialize(): void
    {
        $json = json_encode($this->order);
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('version', $decoded);
        $this->assertArrayHasKey('type', $decoded);
    }

    // ──────────────────────────────────────────────────────────────────
    // ApiResponse Round-Trip Serialization
    // ──────────────────────────────────────────────────────────────────

    public function testApiResponseArrayRoundTrip(): void
    {
        $original = ApiResponse::from(['key' => 'value'])
            ->withMeta(['total' => 100])
            ->withLinks(['self' => '/api/test'])
            ->status(200);

        $array = $original->toArray();
        $restored = ApiResponse::fromArray($array);

        $this->assertSame($original->getData(), $restored->getData());
        $this->assertSame($original->getMeta(), $restored->getMeta());
        $this->assertSame($original->getLinks(), $restored->getLinks());
        $this->assertSame($original->getStatus(), $restored->getStatus());
    }

    public function testApiResponseJsonRoundTrip(): void
    {
        $original = ApiResponse::from(['message' => 'hello'])
            ->withMeta(['version' => '1.0']);

        $json = json_encode($original);
        $restored = ApiResponse::fromJson($json);

        $this->assertSame($original->getData(), $restored->getData());
        $this->assertSame($original->getMeta(), $restored->getMeta());
    }

    public function testApiResponseFluentBuilderInterface(): void
    {
        $response = ApiResponse::from(['data' => 'test'])
            ->withMeta(['page' => 1])
            ->withLinks(['next' => '/page/2'])
            ->withErrors([['title' => 'Warning']])
            ->contentType('application/json')
            ->status(200)
            ->header('X-Custom', 'value')
            ->wrap('response')
            ->pretty();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('response', $response->getWrapKey());
        $this->assertSame('application/json', $response->getContentType());
        $this->assertSame(['X-Custom' => 'value'], $response->getHeaders());
    }

    // ──────────────────────────────────────────────────────────────────
    // TransformationException
    // ──────────────────────────────────────────────────────────────────

    public function testTransformationExceptionArrayRoundTrip(): void
    {
        $original = TransformationException::transformerNotFound('MissingTransformer');
        $array = $original->toArray();

        $this->assertSame('TRANSFORMATION_ERROR', $array['code']);
        $this->assertStringContainsString('MissingTransformer', $array['detail']);

        $restored = TransformationException::fromArray($array);
        $this->assertSame($original->getMessage(), $restored->getMessage());
    }

    public function testTransformationExceptionJsonSerialize(): void
    {
        $exception = TransformationException::invalidDataType('string', 'paginated');

        $json = json_encode($exception);
        $decoded = json_decode($json, true);

        $this->assertSame('TRANSFORMATION_ERROR', $decoded['code']);
        $this->assertStringContainsString('string', $decoded['detail']);
    }
}

// ──────────────────────────────────────────────────────────────────────
// Test Fixtures
// ──────────────────────────────────────────────────────────────────────

/**
 * Test aggregate root for integration testing.
 *
 * Simulates a simple Order aggregate with status tracking.
 */
final class TestOrder extends AggregateRoot
{
    use EventSourced;

    public string $status = 'pending';
    public float $total = 0.0;

    public static function create(AggregateRootId $id): self
    {
        $order = new self($id);
        $order->applyDomainEvent(DomainEvent::occur('order.created', [
            'id' => $id->toString(),
        ]));

        return $order;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->applyDomainEvent(DomainEvent::occur('order.status_changed', [
            'id' => $this->id(),
            'status' => $status,
        ]));
    }

    public function setTotal(float $total): void
    {
        $this->total = $total;
        $this->applyDomainEvent(DomainEvent::occur('order.total_updated', [
            'id' => $this->id(),
            'total' => $total,
        ]));
    }

    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'status' => $this->status,
            'total' => $this->total,
        ];
    }

    private function applyOrderCreated(DomainEvent $event): void
    {
        $this->status = 'pending';
    }

    private function applyOrderStatusChanged(DomainEvent $event): void
    {
        $this->status = $event->payload['status'] ?? $this->status;
    }

    private function applyOrderTotalUpdated(DomainEvent $event): void
    {
        $this->total = $event->payload['total'] ?? $this->total;
    }
}

/**
 * Test transformer for TestOrder aggregates.
 */
final class TestOrderTransformer extends DomainTransformer
{
    protected function mapDomainFields(object $entity, array $context = []): array
    {
        return $this->extractBaseArray($entity);
    }

    protected function mapMeta(object $entity, array $context = []): array
    {
        return [
            'version' => $this->extractVersion($entity),
            'type' => $this->extractType($entity),
        ];
    }
}

/**
 * Test UUID identifier for orders.
 */
final class TestOrderId extends UuidIdentifier {}

/**
 * Test value object for money amounts.
 */
final class TestMoney extends ValueObject
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            amount: $data['amount'],
            currency: $data['currency'],
        );
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
