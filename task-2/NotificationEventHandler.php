<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;

/**
 * MongoDB collection:
 *
 *  processed_events {
 *      _id:        ObjectId,
 *      event_id:   string,
 *      event_type: string,
 *      payload:    object,
 *      recipients: [
 *          {
 *              subscriber_id: string,
 *              email:         string,
 *              status:        "pending" | "sent" | "failed",
 *              sent_at:       ISODate | null
 *          }
 *      ],
 *      created_at: ISODate
 *  }
 *
 *  Indexes:
 *      { event_id: 1 }                                   — unique
 *      { "recipients.status": 1, created_at: 1 }         — for relay polling
 */

final readonly class IncomingEvent
{
    public function __construct(
        public string $eventId, // From X-Event-Id
        public string $eventType, // From X-Event-Type
        public string $aggregateId,
        public string $payload,
    ) {}
}

final readonly class Subscriber
{
    public function __construct(
        public string $id,
        public string $email,
    ) {}
}

interface MessageConsumerInterface
{
    public function consume(int $timeoutMs): ?IncomingEvent;
    public function commitOffset(): void;
}

interface SubscriberRepositoryInterface
{
    /**
     * @return Subscriber[]
     */
    public function findByEvent(IncomingEvent $event): array;
}

interface ProcessedEventRepositoryInterface
{
    /**
     * @param IncomingEvent $event
     * @param Subscriber[] $subscribers
     * @return void
     */
    public function insert(IncomingEvent $event, array $subscribers): void;

    public function exists(string $eventId): bool;
}

final class DuplicateEventException extends \RuntimeException
{
    public function __construct(string $eventId)
    {
        parent::__construct("Event already processed: $eventId");
    }
}

final readonly class NotificationEventHandler
{
    public function __construct(
        private MessageConsumerInterface $consumer,
        private SubscriberRepositoryInterface $subscribers,
        private ProcessedEventRepositoryInterface $processedEvents,
        private LoggerInterface $logger,
        private int $pollIntervalMs = 1_000,
    ) {}

    public function run(): never
    {
        while (true) {
            try {
                $this->handleNext();
            } catch (\Throwable $e) {
                $this->logger->error('Handler error', [
                    'message' => $e->getMessage(),
                ]);

                // @todo implement exponential backoff
                usleep($this->pollIntervalMs * 1_000);
            }
        }
    }

    private function handleNext(): void
    {
        $event = $this->consumer->consume(timeoutMs: 1000);

        if ($event === null) {
            return;
        }

        // Quick check to avoid selecting subscribers if the event has already been processed
        if ($this->processedEvents->exists($event->eventId)) {
            $this->logger->info('The event is being duplicated, skipping it', [
                'event_id' => $event->eventId,
            ]);
            $this->consumer->commitOffset();
            return;
        }

        $subscribers = $this->subscribers->findByEvent($event);

        try {
            $this->processedEvents->insert($event, $subscribers);
        } catch (DuplicateEventException $e) {
            $this->logger->warning('The event is being duplicated on insert, skipping it', [
                'event_id' => $event->eventId,
            ]);
            $this->consumer->commitOffset();
            return;
        }

        $this->consumer->commitOffset();

        $this->logger->info('Event processed', [
            'event_id'   => $event->eventId,
            'event_type' => $event->eventType,
            'recipients' => count($subscribers),
        ]);
    }
}
