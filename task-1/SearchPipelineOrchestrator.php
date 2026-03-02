<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;

interface QueryNormalizerInterface
{
    public function normalize(string $rawQuery): string;
}

interface FallbackParserInterface
{
    public function parse(string $normalizedQuery): SearchCriteria;
}

interface FulltextSearchInterface
{
    public function search(SearchPage $searchPage, float $timeoutSeconds): SearchResults;
}

interface ResponseBuilderInterface
{
    public function build(
        SearchResults $results,
        Pagination $pagination,
        SearchMeta $meta,
    ): array;
}

enum SearchSource: string
{
    case Llm = 'llm';
    case LlmCache = 'llm_cache';
    case Fallback = 'fallback';
}

final readonly class SearchMeta
{
    public function __construct(
        public SearchSource $source,
        public bool $searchCacheHit,
    )
    {
    }
}

final readonly class Pagination
{
    public function __construct(
        public int $page,
        public int $perPage,
    ) {
    }
}

final readonly class SearchPipelineOrchestrator
{
    private const ES_TIMEOUT = 1.0;

    public function __construct(
        private QueryNormalizerInterface $normalizer,
        private SearchCriteriaCacheInterface $llmCache,
        private LlmParseQueryServiceInterface $llmService,
        private FallbackParserInterface $fallbackParser,
        private FulltextSearchResultsCacheInterface $searchCache,
        private FulltextSearchInterface $search,
        private ResponseBuilderInterface $responseBuilder,
        private LoggerInterface $logger,
    )
    {
    }

    /**
     * @throws Throwable
     */
    public function handle(string $rawQuery, int $page = 1, int $perPage = 20): array
    {
        // Normalization
        $normalizedQuery = $this->normalizer->normalize($rawQuery);

        // Resolve structured search criteria
        [$criteria, $criteriaSource] = $this->resolveSearchCriteria($normalizedQuery);

        $searchPage = new SearchPage($criteria, $page, $perPage);

        // Check search results cache
        $searchResults = $this->searchCache->get($searchPage);
        $searchCacheHit = $searchResults !== null;

        if ($searchResults === null) {
            // Search in Elasticsearch
            try {
                $searchResults = $this->search->search($searchPage, self::ES_TIMEOUT);

                $this->searchCache->set($searchPage, $searchResults);
            } catch (\Throwable $e) {
                $this->logger->error('Elasticsearch search error ', [
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Build and return response
        $meta = new SearchMeta($criteriaSource, $searchCacheHit);
        $pagination = new Pagination($page, $perPage);

        return $this->responseBuilder->build($searchResults, $pagination, $meta);
    }

    /**
     * @return array{SearchCriteria, SearchSource}
     */
    private function resolveSearchCriteria(string $normalizedQuery): array
    {
        // Check LLM cache
        $cached = $this->llmCache->get($normalizedQuery);

        if ($cached !== null) {
            return [$cached, SearchSource::LlmCache];
        }

        try {
            // Parse query to search criteria through LLM
            $criteria = $this->llmService->parse($normalizedQuery);

            // Cache valid LLM result
            $this->llmCache->set($normalizedQuery, $criteria);

            return [$criteria, SearchSource::Llm];
        } catch (LlmParseQueryException $e) {
            $this->logger->error('LLM parsing query error', [
                'message' => $e->getMessage(),
            ]);

            // Fallback parser
            $criteria = $this->fallbackParser->parse($normalizedQuery);

            return [$criteria, SearchSource::Fallback];
        }
    }
}
