<?php

declare(strict_types=1);

class LlmParseQueryException extends RuntimeException
{
}

class LlmTimeoutException extends LlmParseQueryException
{
}

class LlmServerErrorException extends LlmParseQueryException
{
}

class LlmTooManyRequestsException extends LlmParseQueryException
{
}

class LlmInvalidDataException extends LlmParseQueryException
{
}

class LlmHttpClientException extends RuntimeException
{
}

class LlmHttpClientTimeoutException extends LlmHttpClientException
{
}

class LlmHttpClientServerErrorException extends LlmHttpClientException
{
}

class LlmHttpClientTooManyRequestsException extends LlmHttpClientException
{
}

interface LlmHttpClientInterface
{
    /**
     * @throws LlmHttpClientTimeoutException
     * @throws LlmHttpClientServerErrorException
     * @throws LlmHttpClientTooManyRequestsException
     */
    public function request(string $prompt, float $timeoutSeconds): string;
}

interface LlmResponseValidatorInterface
{
    /**
     * @throws LlmInvalidDataException
     */
    public function validate(string $rawResponse): SearchCriteria;
}

interface LlmParseQueryServiceInterface
{
    /**
     * @throws LlmParseQueryException
     */
    public function parse(string $userQuery): SearchCriteria;
}

final readonly class SearchCriteria
{
    /**
     * @param string[] $keywords
     * @param array $filters
     * @param string|null $dateFrom
     * @param string|null $dateTo
     */
    public function __construct(
        public array $keywords,
        public array $filters,
        public ?string $dateFrom,
        public ?string $dateTo,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            keywords: array_map(strval(...), (array) ($data['keywords'] ?? [])),
            filters: (array) ($data['filters'] ?? []),
            dateFrom: isset($data['dateFrom']) ? (string) $data['dateFrom'] : null,
            dateTo: isset($data['dateTo']) ? (string) $data['dateTo'] : null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'keywords' => $this->keywords,
            'filters' => $this->filters,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
        ];

        return $this->canonicalizeArray($data);
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @todo Implement sorting array keys and values recursively for stable hashing
     */
    private function canonicalizeArray(array $data): array
    {
        return $data;
    }
}

class LlmResponseValidator implements LlmResponseValidatorInterface
{
    /**
     * @throws LlmInvalidDataException
     */
    public function validate(string $rawResponse): SearchCriteria
    {
        $data = $this->tryDecodeJson($rawResponse);

        if ($this->isEmptyData($data)) {
            throw new LlmInvalidDataException('LLM result is empty: keywords and filters are empty.');
        }

        if (!$this->validateBySchema($data)) {
            $data = $this->tryRepairData($data);

            if (!$this->validateBySchema($data)) {
                throw new LlmInvalidDataException('LLM result is incorrect.');
            }
        }

        return SearchCriteria::fromArray($data);
    }

    /**
     * @throws LlmInvalidDataException
     */
    private function tryDecodeJson(string $raw): array
    {
        $raw = trim($raw);

        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }

        // Remove markdown wrapper
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);

        // Remove trailing commas before } and ]
        $cleaned = preg_replace('/,\s*([\}\]])/m', '$1', $cleaned);

        $data = json_decode($cleaned, true);
        if (is_array($data)) {
            return $data;
        }

        throw new LlmInvalidDataException('Invalid LLM response. Error: ' . json_last_error_msg());
    }

    private function isEmptyData(array $data): bool
    {
        return empty($data['keywords']) && empty($data['filters']);
    }

    /**
     * @todo Implement repairing data
     */
    private function tryRepairData(array $data): array
    {
        return $data;
    }

    /**
     * @todo Implement validation by json schema
     */
    private function validateBySchema(array $data): bool
    {
        return true;
    }
}

/**
 * Coordinates the process of parsing the user's request
 */
final readonly class LlmParseQueryService implements LlmParseQueryServiceInterface
{
    private const TOTAL_TIMEOUT = 10.0;
    private const RETRY_TIMEOUT = 3.0;
    private const MIN_REMAINING_FOR_RETRY = 3.0;

    public function __construct(
        private LlmHttpClientInterface $llm,
        private LlmResponseValidatorInterface $validator,
        private string $promptTemplate,
    )
    {
    }

    /**
     * @throws LlmTimeoutException
     * @throws LlmServerErrorException
     * @throws LlmInvalidDataException
     */
    public function parse(string $userQuery): SearchCriteria
    {
        $prompt = $this->buildPrompt($userQuery);
        $startTs = microtime(true);

        // First attempt
        try {
            $rawResponse = $this->llm->request($prompt, self::TOTAL_TIMEOUT);
        } catch (LlmHttpClientTimeoutException $e) {
            throw new LlmTimeoutException(
                'LLM timeout exceeded',
                previous: $e,
            );
        } catch (LlmHttpClientTooManyRequestsException $e) {
            throw new LlmTooManyRequestsException(
                'LLM too many requests',
                previous: $e,
            );
        } catch (LlmHttpClientServerErrorException $e) {
            // Retry if first attempt was quick
            $elapsed = microtime(true) - $startTs;
            $remaining = self::TOTAL_TIMEOUT - $elapsed;

            if ($remaining < self::MIN_REMAINING_FOR_RETRY) {
                throw new LlmServerErrorException(
                    'LLM server error',
                    previous: $e,
                );
            }

            $rawResponse = $this->retry($prompt, min($remaining, self::RETRY_TIMEOUT));
        }

        return $this->validator->validate($rawResponse);
    }

    /**
     * @throws LlmTimeoutException
     * @throws LlmServerErrorException
     */
    private function retry(string $prompt, float $timeout): string
    {
        try {
            return $this->llm->request($prompt, $timeout);
        } catch (LlmHttpClientTimeoutException $e) {
            throw new LlmTimeoutException(
                'LLM retry timeout exceeded',
                previous: $e,
            );
        } catch (LlmHttpClientTooManyRequestsException $e) {
            throw new LlmTooManyRequestsException(
                'LLM retry too many requests',
                previous: $e,
            );
        } catch (LlmHttpClientServerErrorException $e) {
            throw new LlmServerErrorException(
                'LLM retry server error',
                previous: $e,
            );
        }
    }

    private function buildPrompt(string $userQuery): string
    {
        return str_replace('{{USER_QUERY}}', $userQuery, $this->promptTemplate);
    }
}
