<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use DateTimeImmutable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class AiUsageLogService
{
    private const TABLE = 'tx_seoassistant_ai_call';

    /**
     * Heuristic USD prices per one million tokens. Unknown models still log
     * tokens and receive a zero-dollar estimate instead of a false precision.
     *
     * @var array<string,array{input:float,output:float}>
     */
    private const MODEL_COSTS_PER_MILLION = [
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationService $configuration,
    ) {}

    /**
     * @param array<string,mixed>|null $usage
     * @param array<string,mixed> $context
     */
    public function recordCall(
        string $runType,
        string $model,
        string $status,
        ?array $usage,
        float $startedAt,
        string $requestHash,
        array $context = [],
        string $responseId = '',
        string $errorMessage = '',
    ): void {
        $now = time();
        $tokens = $this->extractTokens($usage);

        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(
            self::TABLE,
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'run_type' => $this->trim($runType, 64),
                'status' => $status === 'success' ? 'success' : 'failed',
                'model' => $this->trim($model, 128),
                'page_url' => $this->trim($this->contextPageUrl($context), 2048),
                'recommendation_uid' => $this->contextRecommendationUid($context),
                'input_tokens' => $tokens['input'],
                'output_tokens' => $tokens['output'],
                'total_tokens' => $tokens['total'],
                'estimated_cost_usd' => $this->estimateCostUsd($model, $tokens['input'], $tokens['output']),
                'duration_ms' => max(0, (int)round((microtime(true) - $startedAt) * 1000)),
                'request_hash' => $this->trim($requestHash, 64),
                'response_id' => $this->trim($responseId, 128),
                'error_message' => $this->trim($errorMessage, 2000),
                'usage_json' => $usage !== null ? $this->json($usage) : '{}',
            ],
            [
                'pid' => Connection::PARAM_INT,
                'tstamp' => Connection::PARAM_INT,
                'crdate' => Connection::PARAM_INT,
                'recommendation_uid' => Connection::PARAM_INT,
                'input_tokens' => Connection::PARAM_INT,
                'output_tokens' => Connection::PARAM_INT,
                'total_tokens' => Connection::PARAM_INT,
                'duration_ms' => Connection::PARAM_INT,
            ]
        );
    }

    /**
     * @return array{month:string,calls:int,successful:int,failed:int,inputTokens:int,outputTokens:int,totalTokens:int,estimatedCostUsd:float}
     */
    public function fetchCurrentMonthSummary(): array
    {
        $start = new DateTimeImmutable('first day of this month 00:00:00');
        $end = $start->modify('+1 month');

        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('status', 'input_tokens', 'output_tokens', 'total_tokens', 'estimated_cost_usd')
            ->from(self::TABLE)
            ->where('crdate >= :start')
            ->andWhere('crdate < :end')
            ->setParameter('start', $start->getTimestamp(), Connection::PARAM_INT)
            ->setParameter('end', $end->getTimestamp(), Connection::PARAM_INT)
            ->executeQuery()
            ->fetchAllAssociative();

        $summary = [
            'month' => $start->format('Y-m'),
            'calls' => 0,
            'successful' => 0,
            'failed' => 0,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'totalTokens' => 0,
            'estimatedCostUsd' => 0.0,
        ];

        foreach ($rows as $row) {
            $summary['calls']++;
            if ((string)($row['status'] ?? '') === 'success') {
                $summary['successful']++;
            } else {
                $summary['failed']++;
            }
            $summary['inputTokens'] += (int)($row['input_tokens'] ?? 0);
            $summary['outputTokens'] += (int)($row['output_tokens'] ?? 0);
            $summary['totalTokens'] += (int)($row['total_tokens'] ?? 0);
            $summary['estimatedCostUsd'] += (float)($row['estimated_cost_usd'] ?? 0);
        }

        return $summary;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchRecentCalls(int $limit = 20): array
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param array<string,mixed>|null $usage
     * @return array{input:int,output:int,total:int}
     */
    private function extractTokens(?array $usage): array
    {
        if ($usage === null) {
            return ['input' => 0, 'output' => 0, 'total' => 0];
        }

        $input = (int)($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int)($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = (int)($usage['total_tokens'] ?? 0);
        if ($total <= 0) {
            $total = $input + $output;
        }

        return [
            'input' => max(0, $input),
            'output' => max(0, $output),
            'total' => max(0, $total),
        ];
    }

    private function estimateCostUsd(string $model, int $inputTokens, int $outputTokens): float
    {
        $configuredInput = $this->configuration->getOpenAiInputCostPerMillion();
        $configuredOutput = $this->configuration->getOpenAiOutputCostPerMillion();
        if ($configuredInput > 0 || $configuredOutput > 0) {
            return round(
                ($inputTokens / 1_000_000 * $configuredInput) + ($outputTokens / 1_000_000 * $configuredOutput),
                6
            );
        }

        $rates = $this->ratesForModel($model);
        if ($rates === null) {
            return 0.0;
        }

        return round(
            ($inputTokens / 1_000_000 * $rates['input']) + ($outputTokens / 1_000_000 * $rates['output']),
            6
        );
    }

    /**
     * @return array{input:float,output:float}|null
     */
    private function ratesForModel(string $model): ?array
    {
        $normalized = strtolower(trim($model));
        if ($normalized === '') {
            return null;
        }

        foreach (self::MODEL_COSTS_PER_MILLION as $prefix => $rates) {
            if (str_starts_with($normalized, $prefix)) {
                return $rates;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function contextPageUrl(array $context): string
    {
        if ((string)($context['page_url'] ?? '') !== '') {
            return (string)$context['page_url'];
        }
        if (is_array($context['recommendation'] ?? null)) {
            return (string)($context['recommendation']['page_url'] ?? '');
        }

        return '';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function contextRecommendationUid(array $context): int
    {
        if ((int)($context['recommendation_uid'] ?? 0) > 0) {
            return (int)$context['recommendation_uid'];
        }
        if (is_array($context['recommendation'] ?? null)) {
            return max(0, (int)($context['recommendation']['uid'] ?? 0));
        }

        return 0;
    }

    private function trim(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }

    /**
     * @param mixed $value
     */
    private function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
