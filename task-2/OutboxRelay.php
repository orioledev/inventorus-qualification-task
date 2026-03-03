<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;

/**
 * Required table:
 *
 *  CREATE TABLE outbox_events (
 *      id               UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
 *      sequence_number  BIGSERIAL,
 *      aggregate_id     UUID          NOT NULL,
 *      event_type       VARCHAR(255)  NOT NULL,
 *      topic            VARCHAR(255)  NOT NULL,
 *      payload          JSONB         NOT NULL,
 *      created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
 *      published_at     TIMESTAMPTZ   NULL
 *  );
 *
 *  CREATE INDEX idx_outbox_unpublished
 *      ON outbox_events (sequence_number)
 *      WHERE published_at IS NULL;
 */

// ---------------------------------------------------------------------------
// Contracts
// ---------------------------------------------------------------------------

interface MessageBrokerInterface
{
    /**
     * Publish a message to the broker.
     *
     * @param string $topic
     * @param string $key Partition key (aggregate id)
     * @param string $payload
     * @param array<string,string> $headers
     *
     * @throws \RuntimeException on delivery failure
     */
    public function publish(
        string $topic,
        string $key,
        string $payload,
        array  $headers = [],
    ): void;
}

final readonly class OutboxRelay
{
    public function __construct(
        private PDO $db,
        private MessageBrokerInterface $broker,
        private LoggerInterface $logger,
        private int $batchSize = 100,
        private int $pollIntervalMs = 1_000,
    )
    {
    }

    /**
     * @throws Throwable
     */
    public function run(): never
    {
        while (true) {
            try {
                $published = $this->pollAndPublish();
            } catch (\PDOException $e) {
                $this->logger->error('DB error', [
                    'message' => $e->getMessage(),
                ]);
                $published = 0;
            } catch (\RuntimeException $e) {
                $this->logger->error('Broker error', [
                    'message' => $e->getMessage(),
                ]);
                $published = 0;
            } catch (\Throwable $e) {
                $this->logger->critical('Unexpected relay error', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $published = 0;
            }

            if ($published === 0) {
                // @todo implement exponential backoff
                usleep($this->pollIntervalMs * 1_000);
            }
        }
    }

    /**
     * @return int Number of successfully published events
     * @throws Throwable
     */
    public function pollAndPublish(): int
    {
        $this->db->beginTransaction();

        try {
            $events = $this->fetchForUpdate();

            if (empty($events)) {
                $this->db->commit();
                return 0;
            }

            $publishedIds = [];

            foreach ($events as $event) {
                try {
                    $this->broker->publish(
                        topic: $event['topic'],
                        key: $event['aggregate_id'],
                        payload: $event['payload'],
                        headers: [
                            'X-Event-Id'   => $event['id'],
                            'X-Event-Type' => $event['event_type'],
                            'X-Created-At' => $event['created_at'],
                        ],
                    );

                    $publishedIds[] = $event['id'];
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to publish event', [
                        'event_id' => $event['id'],
                        'message' => $e->getMessage(),
                    ]);
                    break;
                }
            }

            if (!empty($publishedIds)) {
                $this->markAsPublished($publishedIds);
            }

            $this->db->commit();

            return count($publishedIds);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function fetchForUpdate(): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT id, sequence_number, aggregate_id, event_type, topic, payload, created_at
            FROM outbox_events
            WHERE published_at IS NULL
            ORDER BY sequence_number ASC
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
        SQL);

        $stmt->bindValue(':limit', $this->batchSize, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function markAsPublished(array $ids): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?::uuid'));

        $stmt = $this->db->prepare(<<<SQL
            UPDATE outbox_events
            SET published_at = NOW()
            WHERE id IN ({$placeholders})
        SQL);

        $stmt->execute($ids);
    }
}
