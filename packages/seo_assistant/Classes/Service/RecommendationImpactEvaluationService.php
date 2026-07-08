<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use DateTimeImmutable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecommendationImpactEvaluationService
{
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';
    private const GSC_TABLE = 'tx_seoassistant_gsc_row';
    private const IMPACT_TABLE = 'tx_seoassistant_impact_evaluation';

    /** @var array<string,bool> */
    private array $syncedWindows = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SearchConsoleService $searchConsoleService,
        private readonly OpenAiRecommendationService $openAiRecommendationService,
        private readonly UrlNormalizer $urlNormalizer,
    ) {}

    /**
     * @return array{total:int,evaluated:int,stored:int,pending:int,skipped:int,aiUsed:int,rows:list<array<string,mixed>>}
     */
    public function evaluate(
        int $minAgeDays = 35,
        int $windowDays = 28,
        int $bufferDays = 7,
        int $minImpressions = 20,
        int $limit = 100,
        bool $sync = false,
        int $rowLimit = 25000,
        string $searchType = 'web',
        bool $useAi = true,
        bool $force = false,
        bool $dryRun = false,
        string $evaluationStage = '',
    ): array {
        $evaluationStage = str_replace('-', '_', strtolower(trim($evaluationStage)));
        $stagePreset = $this->evaluationStagePreset($evaluationStage);
        if ($stagePreset !== null) {
            $minAgeDays = $stagePreset['minAgeDays'];
            $windowDays = $stagePreset['windowDays'];
            $bufferDays = $stagePreset['bufferDays'];
            $evaluationStage = $stagePreset['stage'];
        } elseif ($evaluationStage !== '') {
            $evaluationStage = '';
        }
        $windowDays = max(7, min(90, $windowDays));
        $bufferDays = max(0, min(30, $bufferDays));
        $minAgeDays = max($bufferDays + $windowDays, $minAgeDays);
        $evaluationStage = $evaluationStage !== '' ? $evaluationStage : $this->stageForPlan($windowDays, $bufferDays);
        $minImpressions = max(1, $minImpressions);
        $limit = max(1, min(500, $limit));
        $rowLimit = max(1, min(25000, $rowLimit));
        $searchType = trim($searchType) !== '' ? trim($searchType) : 'web';

        $recommendations = $this->fetchEligibleRecommendations($minAgeDays, $limit);
        $rows = [];
        $evaluated = 0;
        $stored = 0;
        $pending = 0;
        $skipped = 0;
        $aiUsed = 0;

        foreach ($recommendations as $recommendation) {
            $plan = $this->buildWindowPlan((int)($recommendation['applied_at'] ?? 0), $windowDays, $bufferDays, $evaluationStage);
            if ($plan['status'] === 'pending') {
                $pending++;
                $rows[] = $this->summaryRow($recommendation, 'pending', 'Waiting until after-window is complete.', $plan);
                continue;
            }

            $hash = $this->evaluationHash($recommendation, $plan, $searchType);
            if (!$force && $this->evaluationExists($hash)) {
                $skipped++;
                $rows[] = $this->summaryRow($recommendation, 'already_evaluated', 'Impact was already evaluated for this window.', $plan);
                continue;
            }

            if ($sync) {
                $this->syncWindow($plan['beforeStart'], $plan['beforeEnd'], $rowLimit, $searchType, $dryRun);
                $this->syncWindow($plan['afterStart'], $plan['afterEnd'], $rowLimit, $searchType, $dryRun);
            }

            $before = $this->fetchMetrics(
                (string)($recommendation['page_url'] ?? ''),
                (string)($recommendation['query_text'] ?? ''),
                $plan['beforeStartTimestamp'],
                $plan['beforeEndTimestamp'],
                $searchType
            );
            $after = $this->fetchMetrics(
                (string)($recommendation['page_url'] ?? ''),
                (string)($recommendation['query_text'] ?? ''),
                $plan['afterStartTimestamp'],
                $plan['afterEndTimestamp'],
                $searchType
            );

            $deterministic = $this->classifyImpact($before, $after, $minImpressions);
            $context = $this->buildAiContext($recommendation, $plan, $before, $after, $deterministic, $minImpressions);
            $ai = null;
            if ($useAi && !$dryRun && $this->openAiRecommendationService->isConfigured()) {
                $ai = $this->openAiRecommendationService->evaluateImpact($context);
                if ($ai !== null) {
                    $aiUsed++;
                }
            }

            $final = $this->finalEvaluation($deterministic, $ai);
            $evaluated++;
            if (!$dryRun) {
                $stored += $this->storeEvaluation($recommendation, $plan, $before, $after, $deterministic, $final, $ai, $context, $hash);
            }

            $rows[] = $this->summaryRow(
                $recommendation,
                $final['impact_status'],
                $final['summary'],
                $plan,
                [
                    'confidence' => $final['confidence'],
                    'beforeImpressions' => $before['impressions'],
                    'afterImpressions' => $after['impressions'],
                    'beforeClicks' => $before['clicks'],
                    'afterClicks' => $after['clicks'],
                    'positionDelta' => $this->positionDelta($before, $after),
                ]
            );
        }

        return [
            'total' => count($recommendations),
            'evaluated' => $evaluated,
            'stored' => $stored,
            'pending' => $pending,
            'skipped' => $skipped,
            'aiUsed' => $aiUsed,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchRecentEvaluations(int $limit = 20): array
    {
        return $this->connectionPool->getConnectionForTable(self::IMPACT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::IMPACT_TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array{stage:string,minAgeDays:int,windowDays:int,bufferDays:int}|null
     */
    private function evaluationStagePreset(string $stage): ?array
    {
        $stage = strtolower(trim($stage));
        $stage = str_replace('-', '_', $stage);

        return match ($stage) {
            'early', 'early_signal', 'early_signal_14d' => [
                'stage' => 'early_signal_14d',
                'minAgeDays' => 14,
                'windowDays' => 7,
                'bufferDays' => 7,
            ],
            'first', 'first_evaluation', 'first_evaluation_35d' => [
                'stage' => 'first_evaluation_35d',
                'minAgeDays' => 35,
                'windowDays' => 28,
                'bufferDays' => 7,
            ],
            'stronger', 'stronger_evaluation', 'stronger_evaluation_63d' => [
                'stage' => 'stronger_evaluation_63d',
                'minAgeDays' => 63,
                'windowDays' => 56,
                'bufferDays' => 7,
            ],
            'final', 'final_evaluation', 'final_evaluation_90d' => [
                'stage' => 'final_evaluation_90d',
                'minAgeDays' => 90,
                'windowDays' => 83,
                'bufferDays' => 7,
            ],
            '', 'custom' => null,
            default => null,
        };
    }

    private function stageForPlan(int $windowDays, int $bufferDays): string
    {
        $totalDays = $windowDays + $bufferDays;
        if ($totalDays <= 14) {
            return 'early_signal_14d';
        }
        if ($totalDays <= 35) {
            return 'first_evaluation_35d';
        }
        if ($totalDays <= 63) {
            return 'stronger_evaluation_63d';
        }

        return 'final_evaluation_90d';
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{impact_status:string,confidence:string,summary:string,next_action:string} $final
     * @param array<string,mixed> $plan
     */
    private function updateRecommendationStatusFromEvaluation(array $recommendation, array $final, array $plan, int $now): void
    {
        $uid = (int)($recommendation['uid'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $currentStatus = (string)($recommendation['status'] ?? '');
        if (in_array($currentStatus, ['draft', 'approved', 'rejected', 'rolled_back'], true)) {
            return;
        }

        $impactStatus = (string)($final['impact_status'] ?? '');
        $stage = (string)($plan['evaluationStage'] ?? '');
        $status = $this->recommendationStatusForEvaluation($impactStatus, $stage);

        $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->update(
                self::RECOMMENDATION_TABLE,
                [
                    'status' => $status,
                    'tstamp' => $now,
                ],
                ['uid' => $uid],
                ['tstamp' => Connection::PARAM_INT]
            );
    }

    private function recommendationStatusForEvaluation(string $impactStatus, string $stage): string
    {
        if ($stage === 'early_signal_14d' || $impactStatus === 'not_enough_data') {
            return 'evaluating';
        }

        if (in_array($impactStatus, ['improved', 'neutral', 'declined'], true)) {
            return $impactStatus;
        }

        return 'evaluating';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchEligibleRecommendations(int $minAgeDays, int $limit): array
    {
        $cutoff = (new DateTimeImmutable('today'))->modify('-' . $minAgeDays . ' days')->getTimestamp();
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->createQueryBuilder();

        return $queryBuilder
            ->select('*')
            ->from(self::RECOMMENDATION_TABLE)
            ->where($queryBuilder->expr()->in('status', ':statuses'))
            ->andWhere('applied_at > 0')
            ->andWhere('applied_at <= :cutoff')
            ->orderBy('applied_at', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('statuses', ['applied', 'verified', 'evaluating', 'improved', 'neutral', 'declined'], Connection::PARAM_STR_ARRAY)
            ->setParameter('cutoff', $cutoff, Connection::PARAM_INT)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildWindowPlan(int $appliedAt, int $windowDays, int $bufferDays, string $evaluationStage): array
    {
        $appliedDate = new DateTimeImmutable(date('Y-m-d', $appliedAt));
        $beforeStart = $appliedDate->modify('-' . $windowDays . ' days');
        $beforeEnd = $appliedDate->modify('-1 day');
        $afterStart = $appliedDate->modify('+' . $bufferDays . ' days');
        $afterEnd = $afterStart->modify('+' . ($windowDays - 1) . ' days');
        $latestAvailable = new DateTimeImmutable('yesterday');

        return [
            'status' => $afterEnd > $latestAvailable ? 'pending' : 'ready',
            'appliedDate' => $appliedDate->format('Y-m-d'),
            'beforeStart' => $beforeStart->format('Y-m-d'),
            'beforeEnd' => $beforeEnd->format('Y-m-d'),
            'afterStart' => $afterStart->format('Y-m-d'),
            'afterEnd' => $afterEnd->format('Y-m-d'),
            'beforeStartTimestamp' => $beforeStart->getTimestamp(),
            'beforeEndTimestamp' => $beforeEnd->getTimestamp(),
            'afterStartTimestamp' => $afterStart->getTimestamp(),
            'afterEndTimestamp' => $afterEnd->getTimestamp(),
            'bufferDays' => $bufferDays,
            'windowDays' => $windowDays,
            'evaluationStage' => $evaluationStage,
        ];
    }

    private function syncWindow(string $startDate, string $endDate, int $rowLimit, string $searchType, bool $dryRun): void
    {
        $key = $startDate . '|' . $endDate . '|' . $searchType;
        if (isset($this->syncedWindows[$key])) {
            return;
        }

        $this->searchConsoleService->sync($startDate, $endDate, null, ['page', 'query'], $rowLimit, $searchType, $dryRun);
        $this->syncedWindows[$key] = true;
    }

    /**
     * @return array{clicks:float,impressions:float,ctr:float,position:float,row_count:int}
     */
    private function fetchMetrics(string $pageUrl, string $queryText, int $dateFrom, int $dateTo, string $searchType): array
    {
        $targetUrl = $this->urlNormalizer->normalize($pageUrl);
        $targetQuery = $this->normalizeQuery($queryText);
        $rows = $this->connectionPool->getConnectionForTable(self::GSC_TABLE)
            ->createQueryBuilder()
            ->select('page_url', 'query_text', 'clicks', 'impressions', 'position')
            ->from(self::GSC_TABLE)
            ->where('date_from = :dateFrom')
            ->andWhere('date_to = :dateTo')
            ->andWhere('search_type = :searchType')
            ->setParameter('dateFrom', $dateFrom, Connection::PARAM_INT)
            ->setParameter('dateTo', $dateTo, Connection::PARAM_INT)
            ->setParameter('searchType', $searchType)
            ->executeQuery()
            ->fetchAllAssociative();

        $clicks = 0.0;
        $impressions = 0.0;
        $weightedPosition = 0.0;
        $rowCount = 0;

        foreach ($rows as $row) {
            if ($this->urlNormalizer->normalize((string)($row['page_url'] ?? '')) !== $targetUrl) {
                continue;
            }
            if ($targetQuery !== '' && $this->normalizeQuery((string)($row['query_text'] ?? '')) !== $targetQuery) {
                continue;
            }

            $rowClicks = (float)($row['clicks'] ?? 0);
            $rowImpressions = (float)($row['impressions'] ?? 0);
            $clicks += $rowClicks;
            $impressions += $rowImpressions;
            $weightedPosition += (float)($row['position'] ?? 0) * $rowImpressions;
            $rowCount++;
        }

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
            'position' => $impressions > 0 ? $weightedPosition / $impressions : 0.0,
            'row_count' => $rowCount,
        ];
    }

    /**
     * @param array{clicks:float,impressions:float,ctr:float,position:float,row_count:int} $before
     * @param array{clicks:float,impressions:float,ctr:float,position:float,row_count:int} $after
     * @return array{impact_status:string,confidence:string,summary:string,next_action:string,score:int}
     */
    private function classifyImpact(array $before, array $after, int $minImpressions): array
    {
        $maxImpressions = max($before['impressions'], $after['impressions']);
        if ($maxImpressions < $minImpressions) {
            return [
                'impact_status' => 'not_enough_data',
                'confidence' => 'low',
                'summary' => 'Not enough Search Console impressions in either comparison window.',
                'next_action' => 'Wait for more Search Console data before judging this change.',
                'score' => 0,
            ];
        }

        $clicksDelta = $after['clicks'] - $before['clicks'];
        $impressionsDelta = $after['impressions'] - $before['impressions'];
        $ctrDelta = $after['ctr'] - $before['ctr'];
        $positionDelta = $this->positionDelta($before, $after);
        $score = 0;

        if ($clicksDelta >= 2 || $this->percentDelta($after['clicks'], $before['clicks']) >= 0.25) {
            $score += 2;
        } elseif ($clicksDelta <= -2 || $this->percentDelta($after['clicks'], $before['clicks']) <= -0.25) {
            $score -= 2;
        }

        if ($impressionsDelta >= 10 || $this->percentDelta($after['impressions'], $before['impressions']) >= 0.2) {
            $score += 1;
        } elseif ($impressionsDelta <= -10 || $this->percentDelta($after['impressions'], $before['impressions']) <= -0.2) {
            $score -= 1;
        }

        if ($ctrDelta >= 0.005) {
            $score += 1;
        } elseif ($ctrDelta <= -0.005) {
            $score -= 1;
        }

        if ($positionDelta >= 1.0) {
            $score += 2;
        } elseif ($positionDelta <= -1.0) {
            $score -= 2;
        }

        $status = $score >= 2 ? 'improved' : ($score <= -2 ? 'declined' : 'neutral');
        $confidence = $maxImpressions >= 200 ? 'high' : ($maxImpressions >= 50 ? 'medium' : 'low');

        return [
            'impact_status' => $status,
            'confidence' => $confidence,
            'summary' => $this->summaryForStatus($status, $clicksDelta, $impressionsDelta, $ctrDelta, $positionDelta),
            'next_action' => $status === 'declined'
                ? 'Review the recommendation and compare the page with current search intent before changing it again.'
                : 'Keep monitoring this page in the next evaluation window.',
            'score' => $score,
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $deterministic
     * @return array<string,mixed>
     */
    private function buildAiContext(array $recommendation, array $plan, array $before, array $after, array $deterministic, int $minImpressions): array
    {
        return [
            'mode' => 'seo_applied_recommendation_impact_evaluation',
            'business_context' => [
                'brand' => 'WALDBYTE',
                'market' => 'Deutschland',
                'local_focus' => 'Region Karlsruhe',
            ],
            'recommendation' => [
                'uid' => (int)($recommendation['uid'] ?? 0),
                'type' => (string)($recommendation['recommendation_type'] ?? ''),
                'action_type' => (string)($recommendation['action_type'] ?? ''),
                'apply_capability' => (string)($recommendation['apply_capability'] ?? ''),
                'page_url' => (string)($recommendation['page_url'] ?? ''),
                'query_text' => (string)($recommendation['query_text'] ?? ''),
                'issue' => (string)($recommendation['issue'] ?? ''),
                'recommendation' => (string)($recommendation['recommendation'] ?? ''),
                'proposed_seo_title' => (string)($recommendation['proposed_seo_title'] ?? ''),
                'proposed_description' => (string)($recommendation['proposed_description'] ?? ''),
                'applied_at' => date('Y-m-d H:i:s', (int)($recommendation['applied_at'] ?? 0)),
                'verification_status' => (string)($recommendation['verification_status'] ?? ''),
            ],
            'evaluation_windows' => [
                'applied_date' => $plan['appliedDate'],
                'before' => ['from' => $plan['beforeStart'], 'to' => $plan['beforeEnd']],
                'buffer_days_after_apply' => (int)$plan['bufferDays'],
                'after' => ['from' => $plan['afterStart'], 'to' => $plan['afterEnd']],
                'window_days' => (int)$plan['windowDays'],
                'minimum_impressions' => $minImpressions,
            ],
            'metrics' => [
                'before' => $before,
                'after' => $after,
                'delta' => $this->deltaMetrics($before, $after),
            ],
            'rule_based_evaluation' => $deterministic,
            'instruction' => 'Judge impact conservatively. Consider data volume, clicks, impressions, CTR and average position. Do not say a change worked if the data is weak or mixed.',
        ];
    }

    /**
     * @param array{impact_status:string,confidence:string,summary:string,next_action:string,score:int} $deterministic
     * @param array{impact_status:string,confidence:string,summary:string,next_action:string}|null $ai
     * @return array{impact_status:string,confidence:string,summary:string,next_action:string}
     */
    private function finalEvaluation(array $deterministic, ?array $ai): array
    {
        if ($deterministic['impact_status'] === 'not_enough_data') {
            return [
                'impact_status' => 'not_enough_data',
                'confidence' => 'low',
                'summary' => $deterministic['summary'],
                'next_action' => $deterministic['next_action'],
            ];
        }

        if ($ai !== null) {
            return $ai;
        }

        return [
            'impact_status' => $deterministic['impact_status'],
            'confidence' => $deterministic['confidence'],
            'summary' => $deterministic['summary'],
            'next_action' => $deterministic['next_action'],
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array<string,mixed> $plan
     * @param array{clicks:float,impressions:float,ctr:float,position:float,row_count:int} $before
     * @param array{clicks:float,impressions:float,ctr:float,position:float,row_count:int} $after
     * @param array<string,mixed> $deterministic
     * @param array{impact_status:string,confidence:string,summary:string,next_action:string} $final
     * @param array<string,mixed>|null $ai
     * @param array<string,mixed> $context
     */
    private function storeEvaluation(
        array $recommendation,
        array $plan,
        array $before,
        array $after,
        array $deterministic,
        array $final,
        ?array $ai,
        array $context,
        string $hash,
    ): int {
        $now = time();
        $data = [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'recommendation_uid' => (int)($recommendation['uid'] ?? 0),
            'page_uid' => (int)($recommendation['page_uid'] ?? 0),
            'page_url' => (string)($recommendation['page_url'] ?? ''),
            'query_text' => (string)($recommendation['query_text'] ?? ''),
            'applied_at' => (int)($recommendation['applied_at'] ?? 0),
            'before_from' => (int)$plan['beforeStartTimestamp'],
            'before_to' => (int)$plan['beforeEndTimestamp'],
            'after_from' => (int)$plan['afterStartTimestamp'],
            'after_to' => (int)$plan['afterEndTimestamp'],
            'buffer_days' => (int)$plan['bufferDays'],
            'window_days' => (int)$plan['windowDays'],
            'evaluation_stage' => (string)$plan['evaluationStage'],
            'before_clicks' => $before['clicks'],
            'before_impressions' => $before['impressions'],
            'before_ctr' => $before['ctr'],
            'before_position' => $before['position'],
            'after_clicks' => $after['clicks'],
            'after_impressions' => $after['impressions'],
            'after_ctr' => $after['ctr'],
            'after_position' => $after['position'],
            'clicks_delta' => $after['clicks'] - $before['clicks'],
            'impressions_delta' => $after['impressions'] - $before['impressions'],
            'ctr_delta' => $after['ctr'] - $before['ctr'],
            'position_delta' => $this->positionDelta($before, $after),
            'impact_status' => $final['impact_status'],
            'confidence' => $final['confidence'],
            'ai_summary' => $final['summary'],
            'ai_next_action' => $final['next_action'],
            'ai_model' => $ai !== null ? $this->openAiRecommendationService->getModel() : '',
            'evidence_json' => $this->json([
                'deterministic' => $deterministic,
                'ai' => $ai,
                'context' => $context,
            ]),
            'evaluation_hash' => $hash,
        ];

        $connection = $this->connectionPool->getConnectionForTable(self::IMPACT_TABLE);
        $existingUid = (int)$connection->createQueryBuilder()
            ->select('uid')
            ->from(self::IMPACT_TABLE)
            ->where('evaluation_hash = :hash')
            ->setParameter('hash', $hash)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $types = [
            'pid' => Connection::PARAM_INT,
            'tstamp' => Connection::PARAM_INT,
            'crdate' => Connection::PARAM_INT,
            'recommendation_uid' => Connection::PARAM_INT,
            'page_uid' => Connection::PARAM_INT,
            'applied_at' => Connection::PARAM_INT,
            'before_from' => Connection::PARAM_INT,
            'before_to' => Connection::PARAM_INT,
            'after_from' => Connection::PARAM_INT,
            'after_to' => Connection::PARAM_INT,
            'buffer_days' => Connection::PARAM_INT,
            'window_days' => Connection::PARAM_INT,
        ];

        if ($existingUid > 0) {
            unset($data['crdate']);
            unset($types['crdate']);
            $connection->update(self::IMPACT_TABLE, $data, ['uid' => $existingUid], $types);
            $this->updateRecommendationStatusFromEvaluation($recommendation, $final, $plan, $now);
            return 1;
        }

        $connection->insert(self::IMPACT_TABLE, $data, $types);
        $this->updateRecommendationStatusFromEvaluation($recommendation, $final, $plan, $now);
        return 1;
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function summaryRow(array $recommendation, string $status, string $message, array $plan, array $extra = []): array
    {
        return array_replace([
            'uid' => (int)($recommendation['uid'] ?? 0),
            'pageUrl' => (string)($recommendation['page_url'] ?? ''),
            'query' => (string)($recommendation['query_text'] ?? ''),
            'appliedDate' => (string)($plan['appliedDate'] ?? ''),
            'evaluationStage' => (string)($plan['evaluationStage'] ?? ''),
            'before' => (string)($plan['beforeStart'] ?? '') . ' to ' . (string)($plan['beforeEnd'] ?? ''),
            'after' => (string)($plan['afterStart'] ?? '') . ' to ' . (string)($plan['afterEnd'] ?? ''),
            'status' => $status,
            'message' => $message,
        ], $extra);
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array<string,mixed> $plan
     */
    private function evaluationHash(array $recommendation, array $plan, string $searchType): string
    {
        return hash('sha256', implode('|', [
            (int)($recommendation['uid'] ?? 0),
            (string)($recommendation['page_url'] ?? ''),
            (string)($recommendation['query_text'] ?? ''),
            $plan['beforeStart'],
            $plan['beforeEnd'],
            $plan['afterStart'],
            $plan['afterEnd'],
            (string)($plan['evaluationStage'] ?? ''),
            $searchType,
        ]));
    }

    private function evaluationExists(string $hash): bool
    {
        return (int)$this->connectionPool->getConnectionForTable(self::IMPACT_TABLE)
            ->createQueryBuilder()
            ->count('uid')
            ->from(self::IMPACT_TABLE)
            ->where('evaluation_hash = :hash')
            ->setParameter('hash', $hash)
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,float>
     */
    private function deltaMetrics(array $before, array $after): array
    {
        return [
            'clicks' => (float)$after['clicks'] - (float)$before['clicks'],
            'impressions' => (float)$after['impressions'] - (float)$before['impressions'],
            'ctr' => (float)$after['ctr'] - (float)$before['ctr'],
            'position' => $this->positionDelta($before, $after),
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function positionDelta(array $before, array $after): float
    {
        $beforePosition = (float)($before['position'] ?? 0);
        $afterPosition = (float)($after['position'] ?? 0);
        if ($beforePosition <= 0 || $afterPosition <= 0) {
            return 0.0;
        }

        return $beforePosition - $afterPosition;
    }

    private function percentDelta(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 1.0 : 0.0;
        }

        return ($current - $previous) / $previous;
    }

    private function summaryForStatus(string $status, float $clicksDelta, float $impressionsDelta, float $ctrDelta, float $positionDelta): string
    {
        return match ($status) {
            'improved' => sprintf('Performance improved after the change: clicks %+0.1f, impressions %+0.1f, CTR %+0.2f%%, position %+0.1f.', $clicksDelta, $impressionsDelta, $ctrDelta * 100, $positionDelta),
            'declined' => sprintf('Performance declined after the change: clicks %+0.1f, impressions %+0.1f, CTR %+0.2f%%, position %+0.1f.', $clicksDelta, $impressionsDelta, $ctrDelta * 100, $positionDelta),
            default => sprintf('Performance is neutral or mixed after the change: clicks %+0.1f, impressions %+0.1f, CTR %+0.2f%%, position %+0.1f.', $clicksDelta, $impressionsDelta, $ctrDelta * 100, $positionDelta),
        };
    }

    private function normalizeQuery(string $query): string
    {
        $query = preg_replace('/\s+/u', ' ', mb_strtolower(trim($query))) ?? $query;

        return trim($query);
    }

    /**
     * @param mixed $value
     */
    private function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
