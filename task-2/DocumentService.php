<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;

/**
 * Required tables:
 *
 *  CREATE TABLE documents (
 *      id               UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
 *      title            VARCHAR(255)  NOT NULL,
 *      created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
 *  );

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

final readonly class DocumentMetadata
{
    public function __construct(
        public string $title,
    ) {}
}

final readonly class DocumentCreatedEventPayload
{
    public function __construct(
        public string $documentId,
        public string $title,
        public DateTimeImmutable $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'title'       => $this->title,
            'created_at'  => $this->createdAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}

final readonly class DocumentService
{
    private const EVENT_TYPE = 'DocumentCreated';
    private const TOPIC = 'documents';

    public function __construct(
        private PDO $db,
        private LoggerInterface $logger,
    )
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @throws Throwable
     * @throws JsonException
     */
    public function create(DocumentMetadata $documentMetadata): string
    {
        $this->db->beginTransaction();

        try {
            $documentRow = $this->insertDocument($documentMetadata);
            $documentId = $documentRow['id'];

            $payload = new DocumentCreatedEventPayload(
                documentId: $documentId,
                title: $documentMetadata->title,
                createdAt: new DateTimeImmutable($documentRow['created_at']),
            );

            $this->insertOutboxEvent($documentId, $payload);

            $this->db->commit();

            return $documentId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @return array{id: string, created_at: string}
     */
    private function insertDocument(DocumentMetadata $documentMetadata): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO documents (title) 
            VALUES (:title)
            RETURNING id, created_at
        SQL
        );

        $stmt->execute([
            ':title' => $documentMetadata->title,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Failed to retrieve document data');
        }

        $this->logger->info('Document created', [
            'id' => $row['id'],
            'created_at' => $row['created_at'],
        ]);

        return $row;
    }

    /**
     * @throws JsonException
     */
    private function insertOutboxEvent(
        string $aggregateId,
        DocumentCreatedEventPayload $payload,
    ): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO outbox_events (aggregate_id, event_type, topic, payload)
            VALUES (:aggregate_id, :event_type, :topic, :payload)
        SQL
        );

        $stmt->execute([
            ':aggregate_id' => $aggregateId,
            ':event_type' => self::EVENT_TYPE,
            ':topic' => self::TOPIC,
            ':payload' => $payload->toJson(),
        ]);

        $this->logger->info('Outbox event created', [
            'aggregate_id' => $aggregateId,
            'event_type' => self::EVENT_TYPE,
            'topic' => self::TOPIC,
        ]);
    }
}
