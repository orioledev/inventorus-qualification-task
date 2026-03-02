<?php

declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;

final readonly class SearchPage
{
    public function __construct(
        public SearchCriteria $criteria,
        public int $page,
        public int $perPage,
    )
    {
    }
}

final readonly class SearchResult
{
    public function __construct(
        public int $id,
        public string $title,
        public float $score,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int)($data['id'] ?? 0),
            title: (string)($data['title'] ?? ''),
            score: (float)($data['score'] ?? 0.0),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'score' => $this->score,
        ];
    }
}

final readonly class SearchResults
{
    /** @param SearchResult[] $results */
    public function __construct(
        public array $results,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            results: array_map(
                fn(array $item) => SearchResult::fromArray($item),
                $data['results'] ?? [],
            ),
        );
    }

    public function toArray(): array
    {
        return [
            'results' => array_map(
                fn(SearchResult $item) => $item->toArray(),
                $this->results,
            ),
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


interface FulltextSearchResultsCacheInterface
{
    public function get(SearchPage $searchPage): ?SearchResults;

    public function set(SearchPage $searchPage, SearchResults $results): void;

    public function delete(SearchPage $searchPage): void;
}

final readonly class FulltextSearchResultsCache implements FulltextSearchResultsCacheInterface
{
    private const PREFIX = 'search:';
    private const DEFAULT_TTL = 3600;

    public function __construct(
        private CacheInterface $cache,
        private int $ttl = self::DEFAULT_TTL,
    )
    {
    }

    public function get(SearchPage $searchPage): ?SearchResults
    {
        $key = $this->buildKey($searchPage);
        $raw = $this->cache->get($key);

        if ($raw === null) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return SearchResults::fromArray($data);
        } catch (\Throwable) {
            $this->cache->delete($key);
            return null;
        }
    }

    /**
     * @throws JsonException
     */
    public function set(SearchPage $searchPage, SearchResults $results): void
    {
        $key = $this->buildKey($searchPage);
        $payload = $results->toJson();

        $this->cache->set($key, $payload, $this->ttl);
    }

    public function delete(SearchPage $searchPage): void
    {
        $key = $this->buildKey($searchPage);
        $this->cache->delete($key);
    }

    /**
     * @throws JsonException
     */
    private function buildKey(SearchPage $searchPage): string
    {
        $raw = $searchPage->criteria->toJson() . "|p:$searchPage->page|pp:$searchPage->perPage";

        return self::PREFIX . hash('sha256', $raw);
    }
}
