<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;

interface SearchCriteriaCacheInterface
{
    public function get(string $userQuery): ?SearchCriteria;

    public function set(string $userQuery, SearchCriteria $searchCriteria): void;

    public function delete(string $userQuery): void;
}

final readonly class SearchCriteriaCache implements SearchCriteriaCacheInterface
{
    private const PREFIX = 'llm:';

    public function __construct(
        private CacheInterface $cache,
    )
    {
    }

    public function get(string $userQuery): ?SearchCriteria
    {
        $key = $this->buildKey($userQuery);
        $raw = $this->cache->get($key);

        if ($raw === null) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return SearchCriteria::fromArray($data);
        } catch (\Throwable $e) {
            $this->cache->delete($key);
            return null;
        }
    }

    /**
     * @throws JsonException
     */
    public function set(string $userQuery, SearchCriteria $searchCriteria): void
    {
        $key = $this->buildKey($userQuery);
        $payload = $searchCriteria->toJson();

        $this->cache->set($key, $payload, $this->getTtlUntilEndOfDay());
    }

    public function delete(string $userQuery): void
    {
        $key = $this->buildKey($userQuery);
        $this->cache->delete($key);
    }

    private function buildKey(string $userQuery): string
    {
        $normalized = $this->normalize($userQuery);

        return self::PREFIX . hash('sha256', $normalized);
    }

    private function normalize(string $query): string
    {
        $query = mb_strtolower($query);
        $query = $this->stemming($query);

        return trim($query);
    }

    /**
     * @todo Implement stemming process here
     */
    private function stemming(string $query): string
    {
        return $query;
    }

    private function getTtlUntilEndOfDay(): int
    {
        $now = new \DateTimeImmutable();
        $endOfDay = $now->modify('tomorrow midnight');

        return $endOfDay->getTimestamp() - $now->getTimestamp();
    }
}
