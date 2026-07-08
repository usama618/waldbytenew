<?php

declare(strict_types=1);

namespace App\SeoAssistant\Controller;

use App\SeoAssistant\Service\RecommendationApplyService;
use App\SeoAssistant\Service\RecommendationRollbackService;
use App\SeoAssistant\Service\AiUsageLogService;
use App\SeoAssistant\Service\ApplyHistoryService;
use App\SeoAssistant\Service\ConfigurationService;
use App\SeoAssistant\Service\RecommendationImpactEvaluationService;
use App\SeoAssistant\Service\SeoAssistantAlertService;
use App\SeoAssistant\Service\SeoAssistantJobService;
use App\SeoAssistant\Service\UrlNormalizer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use Throwable;

final class SeoAssistantModuleController
{
    private const GSC_TABLE = 'tx_seoassistant_gsc_row';
    private const GSC_INSIGHT_TABLE = 'tx_seoassistant_gsc_insight';
    private const PAGE_SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RENDERED_SNAPSHOT_TABLE = 'tx_seoassistant_rendered_snapshot';
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';
    private const ROLLBACK_TABLE = 'tx_seoassistant_recommendation_rollback';
    private const AI_RUN_TABLE = 'tx_seoassistant_ai_run';
    private const AI_CALL_TABLE = 'tx_seoassistant_ai_call';
    private const ALERT_TABLE = 'tx_seoassistant_alert';
    private const JOB_TABLE = 'tx_seoassistant_job';
    private const APPLY_HISTORY_TABLE = 'tx_seoassistant_apply_history';
    private const IMPACT_EVALUATION_TABLE = 'tx_seoassistant_impact_evaluation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly RecommendationApplyService $recommendationApplyService,
        private readonly RecommendationRollbackService $rollbackService,
        private readonly AiUsageLogService $aiUsageLogService,
        private readonly SeoAssistantAlertService $alertService,
        private readonly SeoAssistantJobService $jobService,
        private readonly ApplyHistoryService $applyHistoryService,
        private readonly RecommendationImpactEvaluationService $impactEvaluationService,
        private readonly ConfigurationService $configuration,
        private readonly FormProtectionFactory $formProtectionFactory,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $downloadRunUid = (int)($request->getQueryParams()['downloadAiRun'] ?? 0);
        if ($downloadRunUid > 0) {
            return $this->downloadAiRunDocument($downloadRunUid);
        }

        $downloadHistoryUid = (int)($request->getQueryParams()['downloadApplyHistory'] ?? 0);
        if ($downloadHistoryUid > 0) {
            return $this->downloadApplyHistoryDocument($downloadHistoryUid);
        }

        $notice = null;
        if (strtoupper($request->getMethod()) === 'POST') {
            $notice = $this->handlePost($request);
        }

        return new HtmlResponse($this->render($request, $notice));
    }

    /**
     * @return array{type:string,message:string}|null
     */
    private function handlePost(ServerRequestInterface $request): ?array
    {
        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            $parsedBody = [];
        }

        $formProtection = $this->formProtectionFactory->createFromRequest($request);
        if (!$formProtection->validateToken((string)($parsedBody['formToken'] ?? ''), 'seo_assistant', 'recommendation_action')) {
            return [
                'type' => 'error',
                'message' => 'Invalid request token. Reload the module and try again.',
            ];
        }

        try {
            $action = (string)($parsedBody['action'] ?? '');
            if ($action === 'applyRecommendation') {
                $uid = (int)($parsedBody['uid'] ?? 0);
                if ($uid <= 0) {
                    return ['type' => 'error', 'message' => 'No recommendation uid was provided.'];
                }

                $result = $this->recommendationApplyService->apply($uid, false, false, false, 'seo_text');
                $alreadyImplemented = ($result['alreadyImplemented'] ?? false) === true;
                $historyUid = $this->applyHistoryService->record(
                    'applyRecommendation',
                    'Apply recommendation #' . $uid,
                    'backend',
                    'success',
                    [
                        'total' => 1,
                        'applied' => $alreadyImplemented ? 0 : 1,
                        'alreadyImplemented' => $alreadyImplemented ? 1 : 0,
                        'skipped' => 0,
                        'failed' => 0,
                        'message' => $alreadyImplemented
                            ? 'Recommendation #' . $uid . ' was already implemented and has been hidden.'
                            : 'Recommendation #' . $uid . ' applied.',
                    ],
                    [$this->applyResultHistoryRow($uid, $result, $alreadyImplemented ? 'already_implemented' : 'applied')]
                );
                if (($result['alreadyImplemented'] ?? false) === true) {
                    return [
                        'type' => 'success',
                        'message' => 'Recommendation #' . $uid . ' was already implemented and has been hidden. History #' . $historyUid . '.',
                    ];
                }

                return [
                    'type' => 'success',
                    'message' => 'Recommendation #' . $uid . ' applied. History #' . $historyUid . '.',
                ];
            }

            if ($action === 'rejectRecommendation') {
                $uid = (int)($parsedBody['uid'] ?? 0);
                if ($uid <= 0) {
                    return ['type' => 'error', 'message' => 'No recommendation uid was provided.'];
                }

                $this->recommendationApplyService->reject($uid);
                $historyUid = $this->applyHistoryService->record(
                    'rejectRecommendation',
                    'Reject recommendation #' . $uid,
                    'backend',
                    'success',
                    [
                        'total' => 1,
                        'applied' => 0,
                        'alreadyImplemented' => 0,
                        'skipped' => 1,
                        'failed' => 0,
                        'message' => 'Recommendation #' . $uid . ' rejected. It will not be included in Apply all automatic.',
                    ],
                    [[
                        'uid' => $uid,
                        'pageUid' => 0,
                        'status' => 'rejected',
                        'action' => 'reject',
                        'capability' => 'manual_review',
                        'message' => 'Rejected in the backend module.',
                    ]]
                );

                return [
                    'type' => 'success',
                    'message' => 'Recommendation #' . $uid . ' rejected. It will not be included in Apply all automatic. History #' . $historyUid . '.',
                ];
            }

            if ($action === 'applyAllRecommendations') {
                $limit = max(1, min(500, (int)($parsedBody['limit'] ?? 100)));
                $jobUid = $this->jobService->enqueue('apply_all_recommendations', [
                    'limit' => $limit,
                    'contentCType' => 'seo_text',
                ]);

                return [
                    'type' => 'success',
                    'message' => 'Bulk apply queued as job #' . $jobUid . '. Run vendor/bin/typo3 seo:jobs:run to process queued jobs.',
                ];
            }

            if ($action === 'rollbackRecommendation') {
                $uid = (int)($parsedBody['uid'] ?? 0);
                if ($uid <= 0) {
                    return ['type' => 'error', 'message' => 'No recommendation uid was provided.'];
                }

                $result = $this->rollbackService->rollbackRecommendation($uid, 'backend');
                $historyUid = $this->applyHistoryService->record(
                    'rollbackRecommendation',
                    'Roll back recommendation #' . $uid,
                    'backend',
                    $result['failed'] > 0 ? 'partial' : 'success',
                    [
                        'total' => count($result['rows']),
                        'applied' => $result['rolledBack'],
                        'alreadyImplemented' => 0,
                        'skipped' => $result['skipped'],
                        'failed' => $result['failed'],
                        'message' => 'Rollback complete: rolled back ' . $result['rolledBack'] . ', failed ' . $result['failed'] . '.',
                    ],
                    $result['rows']
                );

                return [
                    'type' => $result['failed'] > 0 ? 'error' : 'success',
                    'message' => 'Rollback complete for recommendation #' . $uid . ': rolled back '
                        . $result['rolledBack'] . ', failed ' . $result['failed'] . '. History #' . $historyUid . '.',
                ];
            }

            if ($action === 'rollbackApplyHistory') {
                $historyUid = (int)($parsedBody['historyUid'] ?? 0);
                if ($historyUid <= 0) {
                    return ['type' => 'error', 'message' => 'No apply history uid was provided.'];
                }
                $history = $this->applyHistoryService->fetchByUid($historyUid);
                if ($history === null) {
                    return ['type' => 'error', 'message' => 'Apply history #' . $historyUid . ' was not found.'];
                }

                $result = $this->rollbackService->rollbackRecommendations($this->recommendationUidsFromApplyHistory($history), 'backend');
                $rollbackHistoryUid = $this->applyHistoryService->record(
                    'rollbackApplyHistory',
                    'Roll back apply history #' . $historyUid,
                    'backend',
                    $result['failed'] > 0 ? 'partial' : 'success',
                    [
                        'total' => count($result['rows']),
                        'applied' => $result['rolledBack'],
                        'alreadyImplemented' => 0,
                        'skipped' => $result['skipped'],
                        'failed' => $result['failed'],
                        'message' => 'Bulk rollback complete: rolled back ' . $result['rolledBack'] . ', failed ' . $result['failed'] . '.',
                    ],
                    $result['rows']
                );

                return [
                    'type' => $result['failed'] > 0 ? 'error' : 'success',
                    'message' => 'Bulk rollback complete for history #' . $historyUid . ': rolled back '
                        . $result['rolledBack'] . ', failed ' . $result['failed'] . '. History #' . $rollbackHistoryUid . '.',
                ];
            }

            if ($action === 'resolveAlert') {
                $uid = (int)($parsedBody['uid'] ?? 0);
                if ($uid <= 0) {
                    return ['type' => 'error', 'message' => 'No alert uid was provided.'];
                }
                $this->alertService->resolve($uid);

                return ['type' => 'success', 'message' => 'Alert #' . $uid . ' resolved.'];
            }

            if ($action === 'generateRecommendations') {
                $renderedLimit = max(1, min(500, (int)($parsedBody['renderedLimit'] ?? $this->configuration->getRenderedSnapshotLimit())));
                $recommendationLimit = max(1, min(500, (int)($parsedBody['recommendationLimit'] ?? $this->configuration->getRecommendationLimit())));
                $aiLimit = max(1, min(100, (int)($parsedBody['aiLimit'] ?? $this->configuration->getAiLimit())));

                $jobUid = $this->jobService->enqueue('generate_recommendations', [
                    'renderedLimit' => $renderedLimit,
                    'recommendationLimit' => $recommendationLimit,
                    'aiLimit' => $aiLimit,
                    'minImpressions' => $this->configuration->getMinImpressions(),
                ]);

                return [
                    'type' => 'success',
                    'message' => 'Fresh recommendation generation queued as job #' . $jobUid . '. Run vendor/bin/typo3 seo:jobs:run to process queued jobs.',
                ];
            }

            return ['type' => 'error', 'message' => 'Unknown SEO Assistant action.'];
        } catch (Throwable $exception) {
            return [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function applyResultHistoryRow(int $uid, array $result, string $status): array
    {
        $changedFields = array_values(array_filter(array_map('strval', (array)($result['changedFields'] ?? []))));
        $message = implode(', ', $changedFields);
        if ($message === '') {
            $message = (string)($result['message'] ?? '');
        }

        return [
            'uid' => $uid,
            'pageUid' => (int)($result['pageUid'] ?? 0),
            'status' => $status,
            'action' => (string)($result['actionType'] ?? ''),
            'capability' => (string)($result['applyCapability'] ?? ''),
            'message' => $message,
        ];
    }

    /**
     * @param array<string,mixed> $history
     * @return list<int>
     */
    private function recommendationUidsFromApplyHistory(array $history): array
    {
        $result = $this->decodeJson((string)($history['result_json'] ?? '{}'));
        $uids = [];
        foreach ((array)($result['rows'] ?? []) as $row) {
            if (is_array($row) && (int)($row['uid'] ?? 0) > 0) {
                $uids[] = (int)$row['uid'];
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * @param array{type:string,message:string}|null $notice
     */
    private function render(ServerRequestInterface $request, ?array $notice = null): string
    {
        $missingTables = $this->findMissingTables();
        if ($missingTables !== []) {
            return $this->renderMissingTables($missingTables);
        }

        $recommendations = $this->fetchRecommendations();
        $gscInsights = $this->fetchGscInsights();
        $aiRuns = $this->fetchAiRuns();
        $aiUsageSummary = $this->aiUsageLogService->fetchCurrentMonthSummary();
        $aiCalls = $this->aiUsageLogService->fetchRecentCalls(20);
        $alerts = $this->alertService->fetchOpen(20);
        $jobs = $this->jobService->fetchRecent(20);
        $applyHistory = $this->applyHistoryService->fetchRecent(20);
        $rollbacks = $this->rollbackService->fetchRecent(20);
        $impactEvaluations = $this->impactEvaluationService->fetchRecentEvaluations(20);
        $renderedSnapshots = $this->fetchRenderedSnapshots();
        $pageSnapshots = $this->fetchPageSnapshots();
        $stats = $this->fetchStats();
        $formToken = $this->formProtectionFactory
            ->createFromRequest($request)
            ->generateToken('seo_assistant', 'recommendation_action');

        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>SEO Assistant</title>'
            . '<style>'
            . 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:24px;background:#f6f7f9;color:#1f2933;}'
            . 'h1{font-size:24px;margin:0 0 6px;}'
            . 'h2{font-size:18px;margin:28px 0 12px;}'
            . 'p{margin:0 0 18px;}'
            . '.muted{color:#5d6875;}'
            . '.stats{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin:18px 0 22px;}'
            . '.stat{background:#fff;border:1px solid #d9dde3;border-radius:6px;padding:14px;}'
            . '.stat strong{display:block;font-size:22px;margin-top:4px;}'
            . '.panel{background:#fff;border:1px solid #d9dde3;border-radius:6px;overflow:hidden;margin-bottom:24px;}'
            . '.table-scroll{max-width:100%;overflow-x:auto;}'
            . '.recommendations-table{min-width:1500px;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{text-align:left;vertical-align:top;padding:10px;border-bottom:1px solid #e3e6ea;font-size:13px;}'
            . 'th{font-weight:700;background:#eef1f4;}'
            . 'tr:last-child td{border-bottom:0;}'
            . '.url{max-width:300px;word-break:break-word;}'
            . '.priority{font-weight:700;}'
            . '.pill{display:inline-block;padding:2px 7px;border-radius:999px;background:#e8edf3;color:#334155;font-size:12px;white-space:nowrap;}'
            . '.pill-critical{background:#fee2e2;color:#991b1b;}'
            . '.pill-warning{background:#fef3c7;color:#92400e;}'
            . '.pill-notice{background:#e0f2fe;color:#075985;}'
            . '.button{display:inline-block;border:1px solid #cbd5e1;border-radius:4px;background:#fff;color:#1f2933;padding:5px 8px;text-decoration:none;font-size:12px;}'
            . '.button:hover{background:#eef1f4;}'
            . '.button-reject{border-color:#fecaca;color:#991b1b;}'
            . '.button-reject:hover{background:#fef2f2;}'
            . '.inline-form{display:inline;margin:0;}'
            . '.row-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}'
            . '.actions{display:flex;align-items:center;gap:8px;margin:0 0 10px;flex-wrap:wrap;}'
            . '.actions input{width:70px;border:1px solid #cbd5e1;border-radius:4px;padding:5px 7px;}'
            . '.notice{border:1px solid #d9dde3;border-radius:6px;padding:10px 12px;margin:14px 0;background:#fff;}'
            . '.notice-success{border-color:#bbf7d0;background:#f0fdf4;color:#166534;}'
            . '.notice-error{border-color:#fecaca;background:#fef2f2;color:#991b1b;}'
            . '.issues{display:flex;gap:5px;flex-wrap:wrap;}'
            . '.busy-overlay{position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.42);backdrop-filter:blur(2px);}'
            . '.busy-overlay.is-active{display:flex;}'
            . '.busy-box{width:min(520px,calc(100vw - 48px));background:#fff;border:1px solid #d9dde3;border-radius:6px;padding:18px;box-shadow:0 18px 60px rgba(15,23,42,.22);}'
            . '.busy-title{font-weight:700;margin:0 0 6px;}'
            . '.busy-text{margin:0 0 14px;color:#5d6875;}'
            . '.busy-bar{height:8px;border-radius:999px;background:#e5e7eb;overflow:hidden;}'
            . '.busy-bar span{display:block;width:42%;height:100%;border-radius:999px;background:#2563eb;animation:seoBusy 1.25s ease-in-out infinite;}'
            . '@keyframes seoBusy{0%{transform:translateX(-110%);}50%{transform:translateX(65%);}100%{transform:translateX(250%);}}'
            . 'body.is-busy .button,body.is-busy input{pointer-events:none;opacity:.72;}'
            . 'code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;white-space:normal;}'
            . '</style></head><body>'
            . '<h1>SEO Assistant</h1>'
            . '<p class="muted">Central overview for Search Console, rendered frontend audits, CMS content snapshots and reviewable recommendations.</p>'
            . $this->renderNotice($notice)
            . $this->renderStats($stats)
            . '<h2>Open Alerts</h2>'
            . '<div class="panel">' . $this->renderAlertsTable($alerts, $formToken) . '</div>'
            . '<h2>Queued Jobs</h2>'
            . '<div class="panel">' . $this->renderJobsTable($jobs) . '</div>'
            . '<h2>GSC Trend Insights</h2>'
            . '<div class="panel">' . $this->renderGscInsightsTable($gscInsights) . '</div>'
            . '<h2>AI Run Memory</h2>'
            . '<div class="panel">' . $this->renderAiRunsTable($aiRuns) . '</div>'
            . '<h2>AI Usage</h2>'
            . '<div class="panel">' . $this->renderAiUsagePanel($aiUsageSummary, $aiCalls) . '</div>'
            . '<h2>Apply History</h2>'
            . '<div class="panel">' . $this->renderApplyHistoryTable($applyHistory, $formToken) . '</div>'
            . '<h2>Rollback Snapshots</h2>'
            . '<div class="panel">' . $this->renderRollbackTable($rollbacks, $formToken) . '</div>'
            . '<h2>Impact Evaluations</h2>'
            . '<div class="panel">' . $this->renderImpactEvaluationsTable($impactEvaluations) . '</div>'
            . '<h2>Recommendations</h2>'
            . $this->renderRecommendationActions($formToken)
            . '<div class="panel table-scroll">' . $this->renderRecommendationsTable($recommendations, $formToken) . '</div>'
            . '<h2>Rendered URL Audit</h2>'
            . '<div class="panel">' . $this->renderRenderedSnapshotsTable($renderedSnapshots) . '</div>'
            . '<h2>CMS Content Snapshots</h2>'
            . '<div class="panel">' . $this->renderPageSnapshotsTable($pageSnapshots) . '</div>'
            . $this->renderBusyOverlay()
            . '</body></html>';
    }

    /**
     * @return list<string>
     */
    private function findMissingTables(): array
    {
        $tableNames = $this->connectionPool
            ->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->getSchemaInformation()
            ->listTableNames();

        $requiredTables = [
            self::GSC_TABLE,
            self::GSC_INSIGHT_TABLE,
            self::PAGE_SNAPSHOT_TABLE,
            self::RENDERED_SNAPSHOT_TABLE,
            self::RECOMMENDATION_TABLE,
            self::ROLLBACK_TABLE,
            self::AI_RUN_TABLE,
            self::AI_CALL_TABLE,
            self::ALERT_TABLE,
            self::JOB_TABLE,
            'tx_seoassistant_structured_data',
            self::APPLY_HISTORY_TABLE,
            self::IMPACT_EVALUATION_TABLE,
        ];

        return array_values(array_diff($requiredTables, $tableNames));
    }

    /**
     * @param list<string> $missingTables
     */
    private function renderMissingTables(array $missingTables): string
    {
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>SEO Assistant Setup Required</title>'
            . '<style>'
            . 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:24px;background:#f6f7f9;color:#1f2933;}'
            . '.box{max-width:860px;background:#fff;border:1px solid #d9dde3;border-radius:6px;padding:18px;}'
            . 'h1{font-size:24px;margin:0 0 12px;}'
            . 'p{line-height:1.5;}'
            . 'code{display:block;background:#111827;color:#f8fafc;border-radius:4px;padding:12px;margin:10px 0;white-space:pre-wrap;}'
            . '.missing{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#991b1b;}'
            . '</style></head><body><div class="box">'
            . '<h1>SEO Assistant database setup required</h1>'
            . '<p>The extension is loaded, but its database tables are missing. Run TYPO3 extension setup on the live release.</p>'
            . '<code>cd /var/www/waldbytenew/current' . "\n" . 'php vendor/bin/typo3 extension:setup' . "\n" . 'php vendor/bin/typo3 cache:flush</code>'
            . '<p>Missing tables:</p><ul>'
            . implode('', array_map(fn(string $table): string => '<li class="missing">' . $this->escape($table) . '</li>', $missingTables))
            . '</ul></div></body></html>';
    }

    private function downloadAiRunDocument(int $runUid): ResponseInterface
    {
        $missingTables = $this->findMissingTables();
        if ($missingTables !== []) {
            return new HtmlResponse($this->renderMissingTables($missingTables), 503);
        }

        $run = $this->fetchAiRunByUid($runUid);
        if ($run === null) {
            return new HtmlResponse('AI run not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $recommendations = $this->fetchRecommendationsForAiRun($run);
        $document = $this->buildAiRunSuggestionsDocument($run, $recommendations);
        $filename = 'seo-assistant-run-' . $runUid . '-' . date('Ymd-His', (int)($run['crdate'] ?? time())) . '.md';

        return new HtmlResponse($document, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function downloadApplyHistoryDocument(int $historyUid): ResponseInterface
    {
        $missingTables = $this->findMissingTables();
        if ($missingTables !== []) {
            return new HtmlResponse($this->renderMissingTables($missingTables), 503);
        }

        $history = $this->applyHistoryService->fetchByUid($historyUid);
        if ($history === null) {
            return new HtmlResponse('Apply history not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $document = $this->applyHistoryService->buildMarkdown($history);
        $filename = 'seo-assistant-apply-history-' . $historyUid . '-' . date('Ymd-His', (int)($history['crdate'] ?? time())) . '.md';

        return new HtmlResponse($document, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchAiRunByUid(int $runUid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::AI_RUN_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::AI_RUN_TABLE)
            ->where('uid = :uid')
            ->setParameter('uid', $runUid, Connection::PARAM_INT)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $run
     * @return list<array<string,mixed>>
     */
    private function fetchRecommendationsForAiRun(array $run): array
    {
        $context = $this->decodeJson((string)($run['context_json'] ?? '{}'));
        $contextRecommendations = array_values(array_filter((array)($context['recommendations'] ?? []), 'is_array'));
        $urls = $this->extractRunUrls($context);
        if ($urls === []) {
            return $contextRecommendations;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from(self::RECOMMENDATION_TABLE)
            ->where($queryBuilder->expr()->in('page_url', ':pageUrls'))
            ->setParameter('pageUrls', $urls, Connection::PARAM_STR_ARRAY)
            ->orderBy('priority', 'DESC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(250);

        $model = (string)($run['model'] ?? '');
        if ($model !== '') {
            $queryBuilder
                ->andWhere('ai_model = :aiModel')
                ->setParameter('aiModel', $model);
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        $matchingKeys = $this->buildRunRecommendationKeys($contextRecommendations);
        if ($matchingKeys === []) {
            return $rows;
        }

        $matchingRows = [];
        foreach ($rows as $row) {
            if (isset($matchingKeys[$this->recommendationMatchKey($row)])) {
                $matchingRows[] = $row;
            }
        }

        return $matchingRows !== [] ? $matchingRows : $contextRecommendations;
    }

    /**
     * @param array<string,mixed> $context
     * @return list<string>
     */
    private function extractRunUrls(array $context): array
    {
        $urls = [];
        foreach ((array)($context['pages'] ?? []) as $page) {
            if (is_array($page) && (string)($page['page_url'] ?? '') !== '') {
                $urls[] = (string)$page['page_url'];
            }
        }
        foreach ((array)($context['recommendations'] ?? []) as $recommendation) {
            if (is_array($recommendation) && (string)($recommendation['page_url'] ?? '') !== '') {
                $urls[] = (string)$recommendation['page_url'];
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param list<array<string,mixed>> $recommendations
     * @return array<string,bool>
     */
    private function buildRunRecommendationKeys(array $recommendations): array
    {
        $keys = [];
        foreach ($recommendations as $recommendation) {
            $keys[$this->recommendationMatchKey($recommendation)] = true;
        }

        return $keys;
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function recommendationMatchKey(array $recommendation): string
    {
        return implode('|', [
            $this->urlNormalizer->normalize((string)($recommendation['page_url'] ?? '')),
            (string)($recommendation['recommendation_type'] ?? $recommendation['type'] ?? ''),
            (string)($recommendation['query_text'] ?? $recommendation['query'] ?? ''),
        ]);
    }

    /**
     * @param array<string,mixed> $run
     * @param list<array<string,mixed>> $recommendations
     */
    private function buildAiRunSuggestionsDocument(array $run, array $recommendations): string
    {
        $context = $this->decodeJson((string)($run['context_json'] ?? '{}'));
        $runUid = (int)($run['uid'] ?? 0);
        $lines = [
            '# SEO Assistant Suggestions - Run ' . $runUid,
            '',
            '- Date: ' . date('Y-m-d H:i', (int)($run['crdate'] ?? 0)),
            '- Model: ' . (string)($run['model'] ?? ''),
            '- Mode: ' . (string)($run['mode'] ?? ''),
            '- Pages analyzed: ' . (int)($run['pages_analyzed'] ?? 0),
            '- Recommendations generated: ' . (int)($run['recommendations_generated'] ?? 0),
            '- Recommendations stored: ' . (int)($run['recommendations_stored'] ?? 0),
            '- Focus: ' . (string)($run['focus_summary'] ?? ''),
            '',
            '## Local Workflow',
            '',
            '1. Use this document in the local TYPO3/DDEV installation.',
            '2. Apply safe metadata/content draft recommendations locally first when possible.',
            '3. For template, JSON-LD, image alt and internal link suggestions, make code or content changes locally.',
            '4. Run local checks and frontend review.',
            '5. Commit the tested changes and deploy through the CI/CD pipeline.',
            '',
            'Useful local commands:',
            '',
            '```bash',
            'ddev typo3 cache:flush',
            'ddev typo3 seo:pages:snapshot --base-url=https://newhobby.ddev.site/',
            'ddev typo3 seo:rendered:snapshot --base-url=https://newhobby.ddev.site/',
            'ddev typo3 seo:recommendations:verify --all --refresh',
            '```',
            '',
            '## Pages Analyzed',
            '',
        ];

        foreach ((array)($context['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $lines[] = '- ' . (string)($page['page_url'] ?? '');
            $lines[] = '  - Page UID: ' . (int)($page['page_uid'] ?? 0);
            $issueCodes = array_values(array_filter((array)($page['rendered_issue_codes'] ?? [])));
            if ($issueCodes !== []) {
                $lines[] = '  - Rendered issues: ' . implode(', ', array_map('strval', $issueCodes));
            }
            $queries = array_values(array_filter((array)($page['top_queries'] ?? []), 'is_array'));
            if ($queries !== []) {
                $lines[] = '  - Top queries:';
                foreach ($queries as $query) {
                    $lines[] = '    - ' . (string)($query['query'] ?? '') . ' | impressions '
                        . $this->formatNumber((float)($query['impressions'] ?? 0)) . ' | position '
                        . $this->formatNumber((float)($query['position'] ?? 0), 1);
                }
            }
        }

        $lines[] = '';
        $lines[] = '## Recommendations';
        $lines[] = '';

        if ($recommendations === []) {
            $lines[] = 'No recommendations were stored for this run.';
        }

        foreach ($recommendations as $recommendation) {
            $lines = array_merge($lines, $this->renderRecommendationMarkdown($recommendation));
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return list<string>
     */
    private function renderRecommendationMarkdown(array $recommendation): array
    {
        $payload = $this->decodeJson((string)($recommendation['action_payload_json'] ?? '{}'));
        $uid = (int)($recommendation['uid'] ?? 0);
        $type = (string)($recommendation['recommendation_type'] ?? $recommendation['type'] ?? 'recommendation');
        $actionType = (string)($recommendation['action_type'] ?? $recommendation['action'] ?? '');
        $capability = (string)($recommendation['apply_capability'] ?? '');
        $pageUrl = (string)($recommendation['page_url'] ?? '');
        $lines = [
            '### ' . ($uid > 0 ? 'UID ' . $uid . ' - ' : '') . $type,
            '',
            '- Page: ' . $pageUrl,
            '- Page UID: ' . (int)($recommendation['page_uid'] ?? 0),
            '- Query: ' . ((string)($recommendation['query_text'] ?? $recommendation['query'] ?? '') ?: '-'),
            '- Priority: ' . (int)($recommendation['priority'] ?? 0),
            '- Status: ' . ((string)($recommendation['status'] ?? '') ?: '-'),
            '- Action type: ' . ($actionType !== '' ? $actionType : '-'),
            '- Apply capability: ' . ($capability !== '' ? $capability : '-'),
            '',
            '**Issue**',
            '',
            (string)($recommendation['issue'] ?? ''),
            '',
            '**Recommendation**',
            '',
            (string)($recommendation['recommendation'] ?? ''),
            '',
        ];

        if ((string)($recommendation['proposed_seo_title'] ?? '') !== '' || (string)($recommendation['proposed_description'] ?? '') !== '') {
            $lines[] = '**Proposed Metadata**';
            $lines[] = '';
            if ((string)($recommendation['proposed_seo_title'] ?? '') !== '') {
                $lines[] = '- SEO title: ' . (string)$recommendation['proposed_seo_title'];
            }
            if ((string)($recommendation['proposed_description'] ?? '') !== '') {
                $lines[] = '- Meta description: ' . (string)$recommendation['proposed_description'];
            }
            $lines[] = '';
        }

        $lines = array_merge($lines, $this->renderActionPayloadMarkdown($payload));

        if ($uid > 0 && in_array($capability, ['safe_metadata', 'content_draft', 'image_alt'], true)) {
            $lines[] = '**Apply Locally**';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = 'ddev typo3 seo:recommendations:apply --uid=' . $uid . ' --yes';
            if ($capability === 'content_draft') {
                $lines[] = '# Optional direct publish after review:';
                $lines[] = 'ddev typo3 seo:recommendations:apply --uid=' . $uid . ' --yes --publish-content';
            }
            $lines[] = 'ddev typo3 seo:recommendations:verify --uid=' . $uid . ' --refresh';
            $lines[] = '```';
            $lines[] = '';
        } else {
            $lines[] = '**Manual Implementation Notes**';
            $lines[] = '';
            $lines[] = '- Test this locally before deployment.';
            $lines[] = '- Template/schema work usually belongs in `packages/site_package/Resources/Private` or `packages/site_package/Classes/Seo/StructuredDataRenderer.php`.';
            $lines[] = '- Content-only edits can be made in the TYPO3 backend on the local DB and then reproduced on live, or converted into code/template changes when they are reusable.';
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function renderActionPayloadMarkdown(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        $lines = [
            '**Action Payload**',
            '',
        ];

        foreach (['target_table', 'target_uid', 'structured_data_type'] as $field) {
            if ((string)($payload[$field] ?? '') !== '') {
                $lines[] = '- ' . $field . ': ' . (string)$payload[$field];
            }
        }

        if ((string)($payload['content_brief'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = 'Content brief:';
            $lines[] = '';
            $lines[] = (string)$payload['content_brief'];
        }

        if ((string)($payload['content_element_header'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = 'Content element header:';
            $lines[] = '';
            $lines[] = (string)$payload['content_element_header'];
        }

        if ((string)($payload['content_body_html'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = 'Content body HTML:';
            $lines[] = '';
            $lines[] = '```html';
            $lines[] = (string)$payload['content_body_html'];
            $lines[] = '```';
        }

        $lines = array_merge($lines, $this->renderStringListMarkdown('Suggested headings', $payload['suggested_headings'] ?? []));
        $lines = array_merge($lines, $this->renderRowsMarkdown('Suggested links', $payload['suggested_links'] ?? [], ['source_url', 'target_url', 'anchor_text', 'reason']));
        $lines = array_merge($lines, $this->renderRowsMarkdown('Image alt suggestions', $payload['image_alt_suggestions'] ?? [], ['src', 'alt_text', 'reason']));
        $lines = array_merge($lines, $this->renderStringListMarkdown('Technical steps', $payload['technical_steps'] ?? []));

        if ((string)($payload['structured_data_preview'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = 'Structured data preview or implementation note:';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = (string)$payload['structured_data_preview'];
            $lines[] = '```';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param mixed $items
     * @return list<string>
     */
    private function renderStringListMarkdown(string $title, $items): array
    {
        if (!is_array($items) || $items === []) {
            return [];
        }

        $lines = ['', $title . ':'];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $lines[] = '- ' . $item;
            }
        }

        return count($lines) > 2 ? $lines : [];
    }

    /**
     * @param mixed $rows
     * @param list<string> $columns
     * @return list<string>
     */
    private function renderRowsMarkdown(string $title, $rows, array $columns): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $lines = ['', $title . ':'];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parts = [];
            foreach ($columns as $column) {
                $value = trim((string)($row[$column] ?? ''));
                if ($value !== '') {
                    $parts[] = $column . ': ' . $value;
                }
            }
            if ($parts !== []) {
                $lines[] = '- ' . implode(' | ', $parts);
            }
        }

        return count($lines) > 2 ? $lines : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAiRuns(): array
    {
        return $this->connectionPool->getConnectionForTable(self::AI_RUN_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::AI_RUN_TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(10)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchGscInsights(): array
    {
        $currentPageUrls = $this->fetchCurrentPageUrlKeys();
        $rows = $this->connectionPool->getConnectionForTable(self::GSC_INSIGHT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::GSC_INSIGHT_TABLE)
            ->orderBy('priority', 'DESC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(500)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_slice($this->filterRowsByCurrentUrl($rows, 'page_url', $currentPageUrls), 0, 100);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchRecommendations(): array
    {
        $currentPageUrls = $this->fetchCurrentPageUrlKeys();
        $connection = $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::RECOMMENDATION_TABLE)
            ->where($queryBuilder->expr()->notIn('status', ':hiddenStatuses'))
            ->orderBy('priority', 'DESC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(500)
            ->setParameter('hiddenStatuses', ['applied', 'verified', 'evaluating', 'improved', 'neutral', 'declined', 'rejected', 'rolled_back', 'implemented', 'dismissed'], Connection::PARAM_STR_ARRAY)
            ->executeQuery()
            ->fetchAllAssociative();

        $rows = $this->filterRowsByCurrentUrl($rows, 'page_url', $currentPageUrls);
        $visible = [];
        foreach ($rows as $row) {
            if (!$this->recommendationApplyService->isAlreadyImplemented($row)) {
                $visible[] = $row;
            }
        }

        return array_slice($visible, 0, 100);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchRenderedSnapshots(): array
    {
        $currentPageUrls = $this->fetchCurrentPageUrlKeys();
        $rows = $this->connectionPool->getConnectionForTable(self::RENDERED_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::RENDERED_SNAPSHOT_TABLE)
            ->orderBy('missing_alt_count', 'DESC')
            ->addOrderBy('word_count', 'ASC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(500)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_slice($this->filterRowsByCurrentUrl($rows, 'url', $currentPageUrls), 0, 100);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchPageSnapshots(): array
    {
        return $this->connectionPool->getConnectionForTable(self::PAGE_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::PAGE_SNAPSHOT_TABLE)
            ->orderBy('word_count', 'ASC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults(100)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array{gsc:int,gscInsights:int,pages:int,rendered:int,recommendations:int,aiRuns:int,aiCalls:int,alerts:int,jobs:int,applyHistory:int,rollbacks:int,impactEvaluations:int}
     */
    private function fetchStats(): array
    {
        return [
            'gsc' => $this->countRows(self::GSC_TABLE),
            'gscInsights' => $this->countRows(self::GSC_INSIGHT_TABLE),
            'pages' => $this->countRows(self::PAGE_SNAPSHOT_TABLE),
            'rendered' => $this->countRows(self::RENDERED_SNAPSHOT_TABLE),
            'recommendations' => $this->countRows(self::RECOMMENDATION_TABLE),
            'aiRuns' => $this->countRows(self::AI_RUN_TABLE),
            'aiCalls' => $this->countRows(self::AI_CALL_TABLE),
            'alerts' => $this->countRows(self::ALERT_TABLE),
            'jobs' => $this->countRows(self::JOB_TABLE),
            'applyHistory' => $this->countRows(self::APPLY_HISTORY_TABLE),
            'rollbacks' => $this->countRows(self::ROLLBACK_TABLE),
            'impactEvaluations' => $this->countRows(self::IMPACT_EVALUATION_TABLE),
        ];
    }

    private function countRows(string $table): int
    {
        return (int)$this->connectionPool->getConnectionForTable($table)
            ->createQueryBuilder()
            ->count('uid')
            ->from($table)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array<string,bool>
     */
    private function fetchCurrentPageUrlKeys(): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::PAGE_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('page_url')
            ->from(self::PAGE_SNAPSHOT_TABLE)
            ->where('page_url <> :empty')
            ->setParameter('empty', '')
            ->executeQuery()
            ->fetchFirstColumn();

        $keys = [];
        foreach ($rows as $url) {
            $normalizedUrl = $this->urlNormalizer->normalize((string)$url);
            if ($normalizedUrl !== '') {
                $keys[$normalizedUrl] = true;
            }
        }

        return $keys;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,bool> $currentPageUrls
     * @return list<array<string,mixed>>
     */
    private function filterRowsByCurrentUrl(array $rows, string $urlColumn, array $currentPageUrls): array
    {
        if ($currentPageUrls === []) {
            return [];
        }

        $filtered = [];
        foreach ($rows as $row) {
            $normalizedUrl = $this->urlNormalizer->normalize((string)($row[$urlColumn] ?? ''));
            if (isset($currentPageUrls[$normalizedUrl])) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * @param array{gsc:int,gscInsights:int,pages:int,rendered:int,recommendations:int,aiRuns:int,aiCalls:int,alerts:int,jobs:int,applyHistory:int,rollbacks:int,impactEvaluations:int} $stats
     */
    private function renderStats(array $stats): string
    {
        return '<div class="stats">'
            . '<div class="stat"><span class="muted">GSC rows</span><strong>' . $stats['gsc'] . '</strong></div>'
            . '<div class="stat"><span class="muted">GSC insights</span><strong>' . $stats['gscInsights'] . '</strong></div>'
            . '<div class="stat"><span class="muted">CMS pages</span><strong>' . $stats['pages'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Rendered URLs</span><strong>' . $stats['rendered'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Recommendations</span><strong>' . $stats['recommendations'] . '</strong></div>'
            . '<div class="stat"><span class="muted">AI memory runs</span><strong>' . $stats['aiRuns'] . '</strong></div>'
            . '<div class="stat"><span class="muted">AI calls logged</span><strong>' . $stats['aiCalls'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Open/job alerts</span><strong>' . $stats['alerts'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Queued jobs</span><strong>' . $stats['jobs'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Apply history</span><strong>' . $stats['applyHistory'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Rollback snapshots</span><strong>' . $stats['rollbacks'] . '</strong></div>'
            . '<div class="stat"><span class="muted">Impact evaluations</span><strong>' . $stats['impactEvaluations'] . '</strong></div>'
            . '</div>';
    }

    /**
     * @param array{type:string,message:string}|null $notice
     */
    private function renderNotice(?array $notice): string
    {
        if ($notice === null || (string)($notice['message'] ?? '') === '') {
            return '';
        }

        $type = (string)($notice['type'] ?? 'success') === 'error' ? 'error' : 'success';
        return '<div class="notice notice-' . $type . '">' . $this->escape((string)$notice['message']) . '</div>';
    }

    private function renderBusyOverlay(): string
    {
        return '<div class="busy-overlay" id="seoBusyOverlay" aria-live="polite" aria-busy="true">'
            . '<div class="busy-box">'
            . '<p class="busy-title" id="seoBusyTitle">SEO Assistant is working</p>'
            . '<p class="busy-text" id="seoBusyText">Please keep this tab open until the action finishes.</p>'
            . '<div class="busy-bar"><span></span></div>'
            . '</div>'
            . '</div>'
            . '<script>'
            . '(function(){'
            . 'var overlay=document.getElementById("seoBusyOverlay");'
            . 'var title=document.getElementById("seoBusyTitle");'
            . 'var text=document.getElementById("seoBusyText");'
            . 'if(!overlay||!title||!text){return;}'
            . 'var labels={'
            . 'generateRecommendations:["Queueing recommendation job","Creating a backend job. The queued worker will process snapshots and AI generation outside this web request."],'
            . 'applyAllRecommendations:["Queueing bulk apply job","Creating a backend job. The queued worker will apply safe TYPO3 database changes outside this web request."],'
            . 'applyRecommendation:["Applying recommendation","Writing this recommendation and refreshing the module."],'
            . 'rejectRecommendation:["Rejecting recommendation","Marking this suggestion as rejected."]'
            . '};'
            . 'document.querySelectorAll("form[method=post]").forEach(function(form){'
            . 'form.addEventListener("submit",function(){'
            . 'var actionInput=form.querySelector("input[name=action]");'
            . 'var action=actionInput?actionInput.value:"";'
            . 'var label=labels[action]||labels.generateRecommendations;'
            . 'title.textContent=label[0];text.textContent=label[1];'
            . 'document.body.classList.add("is-busy");overlay.classList.add("is-active");'
            . 'form.querySelectorAll("button").forEach(function(button){button.disabled=true;});'
            . '});'
            . '});'
            . '})();'
            . '</script>';
    }

    private function renderRecommendationActions(string $formToken): string
    {
        return '<form method="post" class="actions">'
            . '<input type="hidden" name="formToken" value="' . $this->escape($formToken) . '">'
            . '<input type="hidden" name="action" value="generateRecommendations">'
            . '<button class="button" type="submit">Generate fresh recommendations</button>'
            . '<label class="muted">Render limit <input type="number" name="renderedLimit" value="' . $this->configuration->getRenderedSnapshotLimit() . '" min="1" max="500"></label>'
            . '<label class="muted">Recommendation limit <input type="number" name="recommendationLimit" value="' . $this->configuration->getRecommendationLimit() . '" min="1" max="500"></label>'
            . '<label class="muted">AI limit <input type="number" name="aiLimit" value="' . $this->configuration->getAiLimit() . '" min="1" max="100"></label>'
            . '<span class="muted">Runs page snapshot, rendered snapshot and AI recommendation generation.</span>'
            . '</form>'
            . '<form method="post" class="actions">'
            . '<input type="hidden" name="formToken" value="' . $this->escape($formToken) . '">'
            . '<input type="hidden" name="action" value="applyAllRecommendations">'
            . '<button class="button" type="submit">Apply all automatic</button>'
            . '<label class="muted">Limit <input type="number" name="limit" value="100" min="1" max="500"></label>'
            . '<span class="muted">Applies metadata, published content sections, image alt text, indexing fields and DB-backed schema. File/template changes are skipped.</span>'
            . '</form>';
    }

    /**
     * @param list<array<string,mixed>> $insights
     */
    private function renderGscInsightsTable(array $insights): string
    {
        return '<table><thead><tr>'
            . '<th>Priority</th><th>Trend</th><th>Page</th><th>Query</th><th>Current</th><th>Previous</th><th>Change</th><th>Meaning</th>'
            . '</tr></thead><tbody>'
            . ($insights === [] ? '<tr><td colspan="8" class="muted">No trend insights yet. Run <code>vendor/bin/typo3 seo:gsc:analyze-trends --sync</code>.</td></tr>' : '')
            . implode('', array_map($this->renderGscInsightRow(...), $insights))
            . '</tbody></table>';
    }

    /**
     * @param list<array<string,mixed>> $runs
     */
    private function renderAiRunsTable(array $runs): string
    {
        return '<table><thead><tr>'
            . '<th>Date</th><th>Model</th><th>Mode</th><th>Pages</th><th>Generated</th><th>Stored</th><th>Focus</th><th>Document</th>'
            . '</tr></thead><tbody>'
            . ($runs === [] ? '<tr><td colspan="8" class="muted">No AI runs recorded yet.</td></tr>' : '')
            . implode('', array_map($this->renderAiRunRow(...), $runs))
            . '</tbody></table>';
    }

    /**
     * @param list<array<string,mixed>> $alerts
     */
    private function renderAlertsTable(array $alerts, string $formToken): string
    {
        return '<table><thead><tr>'
            . '<th>Date</th><th>Severity</th><th>Source</th><th>Title</th><th>Message</th><th>Actions</th>'
            . '</tr></thead><tbody>'
            . ($alerts === [] ? '<tr><td colspan="6" class="muted">No open SEO Assistant alerts.</td></tr>' : '')
            . implode('', array_map(fn(array $row): string => $this->renderAlertRow($row, $formToken), $alerts))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderAlertRow(array $row, string $formToken): string
    {
        return '<tr>'
            . '<td>' . $this->escape(date('Y-m-d H:i', (int)($row['crdate'] ?? 0))) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['severity'] ?? '')) . '</span></td>'
            . '<td>' . $this->escape((string)($row['source'] ?? '')) . '</td>'
            . '<td>' . $this->escape((string)($row['title'] ?? '')) . '</td>'
            . '<td>' . nl2br($this->escape($this->shorten((string)($row['message'] ?? ''), 240))) . '</td>'
            . '<td><form method="post" class="inline-form">'
            . '<input type="hidden" name="formToken" value="' . $this->escape($formToken) . '">'
            . '<input type="hidden" name="action" value="resolveAlert">'
            . '<input type="hidden" name="uid" value="' . (int)$row['uid'] . '">'
            . '<button class="button" type="submit">Resolve</button>'
            . '</form></td>'
            . '</tr>';
    }

    /**
     * @param list<array<string,mixed>> $jobs
     */
    private function renderJobsTable(array $jobs): string
    {
        return '<table><thead><tr>'
            . '<th>Date</th><th>Type</th><th>Status</th><th>Attempts</th><th>Started</th><th>Finished</th><th>Result/Error</th>'
            . '</tr></thead><tbody>'
            . ($jobs === [] ? '<tr><td colspan="7" class="muted">No queued SEO Assistant jobs yet.</td></tr>' : '')
            . implode('', array_map($this->renderJobRow(...), $jobs))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderJobRow(array $row): string
    {
        $result = (string)($row['error_message'] ?? '');
        if ($result === '') {
            $resultData = $this->decodeJson((string)($row['result_json'] ?? '{}'));
            if ($resultData !== []) {
                $recommendations = is_array($resultData['recommendations'] ?? null) ? $resultData['recommendations'] : [];
                if ($recommendations !== []) {
                    $result = 'Stored recommendations: ' . (int)($recommendations['stored'] ?? 0);
                }
                $applyAll = is_array($resultData['applyAll'] ?? null) ? $resultData['applyAll'] : [];
                if ($applyAll !== []) {
                    $result = 'Applied ' . (int)($applyAll['applied'] ?? 0)
                        . ', already implemented ' . (int)($applyAll['alreadyImplemented'] ?? 0)
                        . ', skipped ' . (int)($applyAll['skipped'] ?? 0)
                        . ', failed ' . (int)($applyAll['failed'] ?? 0)
                        . '. History #' . (int)($resultData['historyUid'] ?? 0);
                }
            }
        }

        return '<tr>'
            . '<td>' . $this->escape(date('Y-m-d H:i', (int)($row['crdate'] ?? 0))) . '</td>'
            . '<td>' . $this->escape((string)($row['job_type'] ?? '')) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['status'] ?? '')) . '</span></td>'
            . '<td>' . (int)($row['attempts'] ?? 0) . '</td>'
            . '<td>' . $this->escape($this->formatDateTime((int)($row['started_at'] ?? 0))) . '</td>'
            . '<td>' . $this->escape($this->formatDateTime((int)($row['finished_at'] ?? 0))) . '</td>'
            . '<td>' . nl2br($this->escape($this->shorten($result, 240))) . '</td>'
            . '</tr>';
    }

    /**
     * @param array{month:string,calls:int,successful:int,failed:int,inputTokens:int,outputTokens:int,totalTokens:int,estimatedCostUsd:float} $summary
     * @param list<array<string,mixed>> $calls
     */
    private function renderAiUsagePanel(array $summary, array $calls): string
    {
        $monthly = '<table><thead><tr>'
            . '<th>Month</th><th>Calls</th><th>Success</th><th>Failed</th><th>Input tokens</th><th>Output tokens</th><th>Total tokens</th><th>Estimated cost</th>'
            . '</tr></thead><tbody><tr>'
            . '<td>' . $this->escape($summary['month']) . '</td>'
            . '<td>' . (int)$summary['calls'] . '</td>'
            . '<td>' . (int)$summary['successful'] . '</td>'
            . '<td>' . (int)$summary['failed'] . '</td>'
            . '<td>' . $this->formatNumber((float)$summary['inputTokens']) . '</td>'
            . '<td>' . $this->formatNumber((float)$summary['outputTokens']) . '</td>'
            . '<td>' . $this->formatNumber((float)$summary['totalTokens']) . '</td>'
            . '<td>$' . $this->formatNumber((float)$summary['estimatedCostUsd'], 4) . '</td>'
            . '</tr></tbody></table>';

        return $monthly
            . '<table><thead><tr>'
            . '<th>Date</th><th>Run type</th><th>Status</th><th>Model</th><th>Context</th><th>Tokens</th><th>Cost</th><th>Duration</th><th>Error</th>'
            . '</tr></thead><tbody>'
            . ($calls === [] ? '<tr><td colspan="9" class="muted">No AI calls logged yet.</td></tr>' : '')
            . implode('', array_map($this->renderAiUsageRow(...), $calls))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderAiUsageRow(array $row): string
    {
        $tokens = 'In ' . $this->formatNumber((float)($row['input_tokens'] ?? 0))
            . '<br>Out ' . $this->formatNumber((float)($row['output_tokens'] ?? 0))
            . '<br>Total ' . $this->formatNumber((float)($row['total_tokens'] ?? 0));
        $context = (string)($row['page_url'] ?? '') !== ''
            ? $this->renderUrl((string)$row['page_url'])
            : '<span class="muted">No page</span>';
        if ((int)($row['recommendation_uid'] ?? 0) > 0) {
            $context .= '<br><span class="muted">Recommendation #' . (int)$row['recommendation_uid'] . '</span>';
        }

        return '<tr>'
            . '<td>' . $this->escape(date('Y-m-d H:i', (int)($row['crdate'] ?? 0))) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['run_type'] ?? '')) . '</span></td>'
            . '<td><span class="pill">' . $this->escape((string)($row['status'] ?? '')) . '</span></td>'
            . '<td>' . $this->escape((string)($row['model'] ?? '')) . '</td>'
            . '<td class="url">' . $context . '</td>'
            . '<td>' . $tokens . '</td>'
            . '<td>$' . $this->formatNumber((float)($row['estimated_cost_usd'] ?? 0), 4) . '</td>'
            . '<td>' . $this->formatNumber((float)($row['duration_ms'] ?? 0)) . ' ms</td>'
            . '<td>' . nl2br($this->escape($this->shorten((string)($row['error_message'] ?? ''), 180))) . '</td>'
            . '</tr>';
    }

    /**
     * @param list<array<string,mixed>> $historyRows
     */
    private function renderApplyHistoryTable(array $historyRows, string $formToken): string
    {
        return '<table><thead><tr>'
            . '<th>Date</th><th>Action</th><th>Source</th><th>Status</th><th>Result</th><th>Summary</th><th>Actions</th>'
            . '</tr></thead><tbody>'
            . ($historyRows === [] ? '<tr><td colspan="7" class="muted">No applied recommendation history yet.</td></tr>' : '')
            . implode('', array_map(fn(array $row): string => $this->renderApplyHistoryRow($row, $formToken), $historyRows))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderApplyHistoryRow(array $row, string $formToken): string
    {
        $result = 'Total ' . (int)($row['total'] ?? 0)
            . '<br>Applied ' . (int)($row['applied'] ?? 0)
            . '<br>Already implemented ' . (int)($row['already_implemented'] ?? 0)
            . '<br>Skipped ' . (int)($row['skipped'] ?? 0)
            . '<br>Failed ' . (int)($row['failed'] ?? 0);

        $actions = '<a class="button" href="?downloadApplyHistory=' . (int)($row['uid'] ?? 0) . '">View changes</a>';
        if ((int)($row['applied'] ?? 0) > 0 && !str_starts_with((string)($row['action_type'] ?? ''), 'rollback')) {
            $actions .= ' <form method="post" class="inline-form">'
                . '<input type="hidden" name="formToken" value="' . $this->escape($formToken) . '">'
                . '<input type="hidden" name="action" value="rollbackApplyHistory">'
                . '<input type="hidden" name="historyUid" value="' . (int)$row['uid'] . '">'
                . '<button class="button button-reject" type="submit">Roll back run</button>'
                . '</form>';
        }

        return '<tr>'
            . '<td>' . $this->escape(date('Y-m-d H:i', (int)($row['crdate'] ?? 0))) . '</td>'
            . '<td>' . $this->escape((string)($row['action_label'] ?? '')) . '<br><span class="muted">' . $this->escape((string)($row['action_type'] ?? '')) . '</span></td>'
            . '<td><span class="pill">' . $this->escape((string)($row['trigger_source'] ?? '')) . '</span></td>'
            . '<td><span class="pill">' . $this->escape((string)($row['status'] ?? '')) . '</span></td>'
            . '<td>' . $result . '</td>'
            . '<td>' . nl2br($this->escape($this->shorten((string)($row['summary'] ?? ''), 180))) . '</td>'
            . '<td>' . $actions . '</td>'
            . '</tr>';
    }

    /**
     * @param list<array<string,mixed>> $rollbacks
     */
    private function renderRollbackTable(array $rollbacks, string $formToken): string
    {
        return '<table><thead><tr>'
            . '<th>Date</th><th>Status</th><th>Recommendation</th><th>Action</th><th>Target</th><th>Message</th><th>Actions</th>'
            . '</tr></thead><tbody>'
            . ($rollbacks === [] ? '<tr><td colspan="7" class="muted">No rollback snapshots yet.</td></tr>' : '')
            . implode('', array_map(fn(array $row): string => $this->renderRollbackRow($row, $formToken), $rollbacks))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderRollbackRow(array $row, string $formToken): string
    {
        $actions = '<span class="muted">-</span>';
        if ((string)($row['status'] ?? '') === 'available') {
            $actions = '<form method="post" class="inline-form">'
                . '<input type="hidden" name="formToken" value="' . $this->escape($formToken) . '">'
                . '<input type="hidden" name="action" value="rollbackRecommendation">'
                . '<input type="hidden" name="uid" value="' . (int)$row['recommendation_uid'] . '">'
                . '<button class="button button-reject" type="submit">Roll back recommendation</button>'
                . '</form>';
        }

        return '<tr>'
            . '<td>' . $this->escape(date('Y-m-d H:i', (int)($row['crdate'] ?? 0))) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['status'] ?? '')) . '</span></td>'
            . '<td>#' . (int)($row['recommendation_uid'] ?? 0) . '</td>'
            . '<td>' . $this->escape((string)($row['action_type'] ?? '')) . '</td>'
            . '<td>' . $this->escape((string)($row['target_table'] ?? '')) . ' #' . (int)($row['target_uid'] ?? 0) . '</td>'
            . '<td>' . nl2br($this->escape($this->shorten((string)($row['message'] ?? ''), 180))) . '</td>'
            . '<td>' . $actions . '</td>'
            . '</tr>';
    }

    /**
     * @param list<array<string,mixed>> $evaluations
     */
    private function renderImpactEvaluationsTable(array $evaluations): string
    {
        return '<table><thead><tr>'
            . '<th>Date</th><th>Stage</th><th>Impact</th><th>Recommendation</th><th>Page</th><th>Windows</th><th>Metrics</th><th>AI Summary</th>'
            . '</tr></thead><tbody>'
            . ($evaluations === [] ? '<tr><td colspan="8" class="muted">No impact evaluations yet. Run <code>vendor/bin/typo3 seo:recommendations:evaluate-impact --sync</code> after applied changes are at least 35 days old.</td></tr>' : '')
            . implode('', array_map($this->renderImpactEvaluationRow(...), $evaluations))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderImpactEvaluationRow(array $row): string
    {
        $beforeWindow = $this->formatDate((int)($row['before_from'] ?? 0)) . ' - ' . $this->formatDate((int)($row['before_to'] ?? 0));
        $afterWindow = $this->formatDate((int)($row['after_from'] ?? 0)) . ' - ' . $this->formatDate((int)($row['after_to'] ?? 0));
        $metrics = 'Clicks ' . $this->formatNumber((float)($row['before_clicks'] ?? 0)) . ' -> ' . $this->formatNumber((float)($row['after_clicks'] ?? 0))
            . ' (' . $this->formatSignedNumber((float)($row['clicks_delta'] ?? 0)) . ')<br>'
            . 'Impr. ' . $this->formatNumber((float)($row['before_impressions'] ?? 0)) . ' -> ' . $this->formatNumber((float)($row['after_impressions'] ?? 0))
            . ' (' . $this->formatSignedNumber((float)($row['impressions_delta'] ?? 0)) . ')<br>'
            . 'CTR ' . $this->formatPercent((float)($row['before_ctr'] ?? 0)) . ' -> ' . $this->formatPercent((float)($row['after_ctr'] ?? 0))
            . ' (' . $this->formatSignedPercent((float)($row['ctr_delta'] ?? 0)) . ')<br>'
            . 'Pos. ' . $this->formatNumber((float)($row['before_position'] ?? 0), 1) . ' -> ' . $this->formatNumber((float)($row['after_position'] ?? 0), 1)
            . ' (' . $this->formatSignedNumber((float)($row['position_delta'] ?? 0), 1) . ')';

        return '<tr>'
            . '<td>' . $this->escape(date('Y-m-d H:i', (int)($row['crdate'] ?? 0))) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['evaluation_stage'] ?? '')) . '</span></td>'
            . '<td><span class="pill">' . $this->escape((string)($row['impact_status'] ?? '')) . '</span><br><span class="muted">' . $this->escape((string)($row['confidence'] ?? '')) . '</span></td>'
            . '<td>#' . (int)($row['recommendation_uid'] ?? 0) . '<br><span class="muted">Applied ' . $this->escape($this->formatDate((int)($row['applied_at'] ?? 0))) . '</span></td>'
            . '<td class="url">' . $this->renderUrl((string)($row['page_url'] ?? '')) . '<br><span class="muted">' . $this->escape((string)($row['query_text'] ?? '')) . '</span></td>'
            . '<td><span class="muted">Before</span><br>' . $this->escape($beforeWindow) . '<br><span class="muted">After</span><br>' . $this->escape($afterWindow) . '</td>'
            . '<td>' . $metrics . '</td>'
            . '<td>' . nl2br($this->escape((string)($row['ai_summary'] ?? ''))) . '<br><span class="muted">' . nl2br($this->escape((string)($row['ai_next_action'] ?? ''))) . '</span></td>'
            . '</tr>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderGscInsightRow(array $row): string
    {
        $currentRange = $this->formatDate((int)($row['current_from'] ?? 0)) . ' - ' . $this->formatDate((int)($row['current_to'] ?? 0));
        $previousRange = $this->formatDate((int)($row['previous_from'] ?? 0)) . ' - ' . $this->formatDate((int)($row['previous_to'] ?? 0));

        return '<tr>'
            . '<td class="priority">' . (int)($row['priority'] ?? 0) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['trend_type'] ?? '')) . '</span></td>'
            . '<td class="url">' . $this->renderUrl((string)($row['page_url'] ?? '')) . '<br><span class="muted">page uid: ' . (int)($row['page_uid'] ?? 0) . '</span></td>'
            . '<td>' . $this->escape((string)($row['query_text'] ?? '')) . '</td>'
            . '<td><span class="muted">' . $this->escape($currentRange) . '</span><br>'
            . 'clicks ' . $this->formatNumber((float)($row['current_clicks'] ?? 0)) . '<br>'
            . 'impr. ' . $this->formatNumber((float)($row['current_impressions'] ?? 0)) . '<br>'
            . 'CTR ' . $this->formatPercent((float)($row['current_ctr'] ?? 0)) . '<br>'
            . 'pos. ' . $this->formatNumber((float)($row['current_position'] ?? 0), 1) . '</td>'
            . '<td><span class="muted">' . $this->escape($previousRange) . '</span><br>'
            . 'clicks ' . $this->formatNumber((float)($row['previous_clicks'] ?? 0)) . '<br>'
            . 'impr. ' . $this->formatNumber((float)($row['previous_impressions'] ?? 0)) . '<br>'
            . 'CTR ' . $this->formatPercent((float)($row['previous_ctr'] ?? 0)) . '<br>'
            . 'pos. ' . $this->formatNumber((float)($row['previous_position'] ?? 0), 1) . '</td>'
            . '<td>clicks ' . $this->formatSignedNumber((float)($row['clicks_delta'] ?? 0)) . '<br>'
            . 'impr. ' . $this->formatSignedNumber((float)($row['impressions_delta'] ?? 0)) . '<br>'
            . 'CTR ' . $this->formatSignedPercent((float)($row['ctr_delta'] ?? 0)) . '<br>'
            . 'pos. ' . $this->formatSignedNumber((float)($row['position_delta'] ?? 0), 1) . '</td>'
            . '<td>' . nl2br($this->escape((string)($row['summary'] ?? ''))) . '</td>'
            . '</tr>';
    }

    /**
     * @param list<array<string,mixed>> $recommendations
     */
    private function renderRecommendationsTable(array $recommendations, string $formToken): string
    {
        return '<table class="recommendations-table"><thead><tr>'
            . '<th>UID</th><th>Priority</th><th>Status</th><th>Type</th><th>Action</th><th>Page</th><th>Query</th><th>Issue</th><th>Recommendation</th><th>Proposed Metadata</th><th>Verification</th><th>Actions</th><th>Command</th>'
            . '</tr></thead><tbody>'
            . ($recommendations === [] ? '<tr><td colspan="13" class="muted">No recommendations need action. Run the snapshot and generate commands when you want a fresh audit.</td></tr>' : '')
            . implode('', array_map(fn(array $row): string => $this->renderRecommendationRow($row, $formToken), $recommendations))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderAiRunRow(array $row): string
    {
        return '<tr>'
            . '<td>' . $this->escape(date('Y-m-d H:i', (int)($row['crdate'] ?? 0))) . '</td>'
            . '<td>' . $this->escape((string)($row['model'] ?? '')) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['mode'] ?? '')) . '</span></td>'
            . '<td>' . (int)($row['pages_analyzed'] ?? 0) . '</td>'
            . '<td>' . (int)($row['recommendations_generated'] ?? 0) . '</td>'
            . '<td>' . (int)($row['recommendations_stored'] ?? 0) . '</td>'
            . '<td>' . $this->escape((string)($row['focus_summary'] ?? '')) . '</td>'
            . '<td><a class="button" href="?downloadAiRun=' . (int)($row['uid'] ?? 0) . '">Download suggestions</a></td>'
            . '</tr>';
    }

    /**
     * @param list<array<string,mixed>> $snapshots
     */
    private function renderRenderedSnapshotsTable(array $snapshots): string
    {
        return '<table><thead><tr>'
            . '<th>URL</th><th>Status</th><th>Rendered Title</th><th>Description</th><th>Words</th><th>H1</th><th>Images</th><th>Links</th><th>Issues</th>'
            . '</tr></thead><tbody>'
            . ($snapshots === [] ? '<tr><td colspan="9" class="muted">No rendered snapshots yet. Run <code>vendor/bin/typo3 seo:rendered:snapshot</code>.</td></tr>' : '')
            . implode('', array_map($this->renderRenderedSnapshotRow(...), $snapshots))
            . '</tbody></table>';
    }

    /**
     * @param list<array<string,mixed>> $snapshots
     */
    private function renderPageSnapshotsTable(array $snapshots): string
    {
        return '<table><thead><tr>'
            . '<th>Page</th><th>SEO Title</th><th>Description</th><th>H1</th><th>Words</th><th>Robots</th><th>Content Preview</th>'
            . '</tr></thead><tbody>'
            . ($snapshots === [] ? '<tr><td colspan="7" class="muted">No CMS snapshots yet. Run <code>vendor/bin/typo3 seo:pages:snapshot</code>.</td></tr>' : '')
            . implode('', array_map($this->renderPageSnapshotRow(...), $snapshots))
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderRecommendationRow(array $row, string $formToken): string
    {
        $payload = json_decode((string)($row['action_payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $normalizedAction = $this->recommendationApplyService->actionForRecommendation($row);
        $displayPayload = $normalizedAction['payload'];

        $metadata = '';
        if ((string)($row['proposed_seo_title'] ?? '') !== '') {
            $metadata .= '<strong>Title:</strong> ' . $this->escape((string)$row['proposed_seo_title']) . '<br>';
        }
        if ((string)($row['proposed_description'] ?? '') !== '') {
            $metadata .= '<strong>Description:</strong> ' . $this->escape((string)$row['proposed_description']);
        }
        if ($metadata === '' && ($normalizedAction['seoTitle'] !== '' || $normalizedAction['description'] !== '')) {
            if ($normalizedAction['seoTitle'] !== '') {
                $metadata .= '<strong>Title:</strong> ' . $this->escape($normalizedAction['seoTitle']) . '<br>';
            }
            if ($normalizedAction['description'] !== '') {
                $metadata .= '<strong>Description:</strong> ' . $this->escape($normalizedAction['description']);
            }
        }
        if (
            $metadata === ''
            && (
                (string)($displayPayload['content_element_header'] ?? '') !== ''
                || (string)($displayPayload['content_body_html'] ?? '') !== ''
                || (string)($displayPayload['content_brief'] ?? '') !== ''
                || ($displayPayload['suggested_headings'] ?? []) !== []
            )
        ) {
            $contentHeader = (string)($displayPayload['content_element_header'] ?? '');
            if ($contentHeader === '' && is_array($displayPayload['suggested_headings'] ?? null)) {
                $contentHeader = (string)($displayPayload['suggested_headings'][0] ?? '');
            }
            $contentPreview = (string)($displayPayload['content_body_html'] ?? '');
            if ($contentPreview === '') {
                $contentPreview = (string)($displayPayload['content_brief'] ?? '');
            }
            $metadata = '<strong>Content:</strong> ' . $this->escape($contentHeader !== '' ? $contentHeader : 'Generated content')
                . '<br><span class="muted">' . $this->escape($this->shorten($this->cleanPreviewText($contentPreview), 120)) . '</span>';
        } elseif ($metadata === '' && (string)($displayPayload['structured_data_type'] ?? '') !== '') {
            $metadata = '<strong>Schema:</strong> ' . $this->escape((string)$displayPayload['structured_data_type']);
        } elseif ($metadata === '' && ((string)($displayPayload['canonical_link'] ?? '') !== '' || array_key_exists('no_index', $displayPayload))) {
            $parts = [];
            if (array_key_exists('no_index', $displayPayload)) {
                $parts[] = 'no_index: ' . (int)$displayPayload['no_index'];
            }
            if ((string)($displayPayload['canonical_link'] ?? '') !== '') {
                $parts[] = 'canonical: ' . (string)$displayPayload['canonical_link'];
            }
            $metadata = '<strong>Indexing:</strong> ' . $this->escape(implode(', ', $parts));
        }
        if ($metadata === '') {
            $metadata = '<span class="muted">Manual review</span>';
        }

        $applyCapability = $normalizedAction['applyCapability'];
        if ($applyCapability === 'safe_metadata') {
            $applyCommand = 'vendor/bin/typo3 seo:recommendations:apply --uid=' . (int)$row['uid'] . ' --yes';
            $secondCommand = (string)($row['status'] ?? '') === 'applied'
                ? 'vendor/bin/typo3 seo:recommendations:verify --uid=' . (int)$row['uid'] . ' --refresh'
                : 'Apply first';
        } elseif (in_array($applyCapability, ['content_draft', 'image_alt', 'indexing_update', 'structured_data'], true)) {
            $applyCommand = 'vendor/bin/typo3 seo:recommendations:apply --uid=' . (int)$row['uid'] . ' --yes';
            $secondCommand = (string)($row['status'] ?? '') === 'applied'
                ? 'vendor/bin/typo3 seo:recommendations:verify --uid=' . (int)$row['uid'] . ' --refresh'
                : 'Apply first';
        } else {
            $applyCommand = 'Manual content/template change';
            $secondCommand = (string)($row['status'] ?? '') === 'applied'
                ? 'vendor/bin/typo3 seo:recommendations:verify --uid=' . (int)$row['uid'] . ' --refresh'
                : 'Apply first';
        }
        $canApplyAutomatically = in_array($applyCapability, ['safe_metadata', 'content_draft', 'image_alt', 'indexing_update', 'structured_data'], true);
        $applyControl = '<div class="row-actions">';
        if ($canApplyAutomatically) {
            $applyControl .= '<form method="post" class="inline-form">'
                . '<input type="hidden" name="formToken" value="' . $this->escape($formToken) . '">'
                . '<input type="hidden" name="action" value="applyRecommendation">'
                . '<input type="hidden" name="uid" value="' . (int)$row['uid'] . '">'
                . '<button class="button" type="submit">Apply</button>'
                . '</form>';
        } else {
            $applyControl .= '<span class="muted">Manual</span>';
        }
        $applyControl .= '<form method="post" class="inline-form">'
            . '<input type="hidden" name="formToken" value="' . $this->escape($formToken) . '">'
            . '<input type="hidden" name="action" value="rejectRecommendation">'
            . '<input type="hidden" name="uid" value="' . (int)$row['uid'] . '">'
            . '<button class="button button-reject" type="submit">Reject</button>'
            . '</form></div>';

        return '<tr>'
            . '<td>' . (int)($row['uid'] ?? 0) . '</td>'
            . '<td class="priority">' . (int)($row['priority'] ?? 0) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['status'] ?? '')) . '</span></td>'
            . '<td>' . $this->escape((string)($row['recommendation_type'] ?? '')) . '</td>'
            . '<td>' . $this->renderActionSummary($row) . '</td>'
            . '<td class="url">' . $this->renderUrl((string)($row['page_url'] ?? '')) . '<br><span class="muted">page uid: ' . (int)($row['page_uid'] ?? 0) . '</span></td>'
            . '<td>' . $this->escape((string)($row['query_text'] ?? '')) . '</td>'
            . '<td>' . nl2br($this->escape((string)($row['issue'] ?? ''))) . '</td>'
            . '<td>' . nl2br($this->escape((string)($row['recommendation'] ?? ''))) . '</td>'
            . '<td>' . $metadata . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['verification_status'] ?? 'not_checked')) . '</span></td>'
            . '<td>' . $applyControl . '</td>'
            . '<td><code>' . $this->escape($applyCommand) . '</code><br><code>' . $this->escape($secondCommand) . '</code></td>'
            . '</tr>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderActionSummary(array $row): string
    {
        $action = $this->recommendationApplyService->actionForRecommendation($row);
        $actionType = $action['actionType'];
        $capability = $action['applyCapability'];
        $payload = $action['payload'];

        $details = '';
        if ((string)($payload['content_element_header'] ?? '') !== '') {
            $details = '<br><span class="muted">' . $this->escape($this->shorten((string)$payload['content_element_header'], 120)) . '</span>';
        } elseif ((string)($payload['content_brief'] ?? '') !== '') {
            $details = '<br><span class="muted">' . $this->escape($this->shorten((string)$payload['content_brief'], 120)) . '</span>';
        } elseif ((string)($payload['canonical_link'] ?? '') !== '') {
            $details = '<br><span class="muted">Canonical: ' . $this->escape((string)$payload['canonical_link']) . '</span>';
        } elseif ((string)($payload['structured_data_type'] ?? '') !== '') {
            $details = '<br><span class="muted">Schema: ' . $this->escape((string)$payload['structured_data_type']) . '</span>';
        } elseif (($payload['image_alt_suggestions'] ?? []) !== [] && is_array($payload['image_alt_suggestions'])) {
            $details = '<br><span class="muted">Images: ' . count($payload['image_alt_suggestions']) . '</span>';
        } elseif (array_key_exists('no_index', $payload)) {
            $details = '<br><span class="muted">no_index: ' . (int)$payload['no_index'] . '</span>';
        }

        return '<span class="pill">' . $this->escape($actionType) . '</span><br><span class="muted">' . $this->escape($capability) . '</span>' . $details;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderRenderedSnapshotRow(array $row): string
    {
        $issues = json_decode((string)($row['issues_json'] ?? '[]'), true);
        $issueHtml = '<span class="muted">No issues</span>';
        if (is_array($issues) && $issues !== []) {
            $issueHtml = '<div class="issues">' . implode('', array_map($this->renderIssuePill(...), $issues)) . '</div>';
        }

        return '<tr>'
            . '<td class="url">' . $this->renderUrl((string)($row['url'] ?? '')) . '</td>'
            . '<td>' . (int)($row['http_status'] ?? 0) . '</td>'
            . '<td>' . $this->escape($this->shorten((string)($row['html_title'] ?? ''), 90)) . '</td>'
            . '<td>' . $this->escape($this->shorten((string)($row['meta_description'] ?? ''), 120)) . '</td>'
            . '<td>' . (int)($row['word_count'] ?? 0) . '</td>'
            . '<td>' . (int)($row['h1_count'] ?? 0) . '</td>'
            . '<td>' . (int)($row['image_count'] ?? 0) . ' / missing alt ' . (int)($row['missing_alt_count'] ?? 0) . '</td>'
            . '<td>internal ' . (int)($row['internal_link_count'] ?? 0) . '<br>external ' . (int)($row['external_link_count'] ?? 0) . '</td>'
            . '<td>' . $issueHtml . '</td>'
            . '</tr>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderPageSnapshotRow(array $row): string
    {
        return '<tr>'
            . '<td class="url">' . $this->renderUrl((string)($row['page_url'] ?? '')) . '<br><span class="muted">page uid: ' . (int)($row['page_uid'] ?? 0) . '</span></td>'
            . '<td>' . $this->escape($this->shorten((string)($row['seo_title'] ?: $row['title'] ?? ''), 90)) . '</td>'
            . '<td>' . $this->escape($this->shorten((string)($row['description'] ?? ''), 120)) . '</td>'
            . '<td>' . $this->escape($this->shorten((string)($row['h1'] ?? ''), 80)) . '</td>'
            . '<td>' . (int)($row['word_count'] ?? 0) . '</td>'
            . '<td><span class="pill">' . $this->escape((string)($row['robots'] ?? '')) . '</span></td>'
            . '<td>' . $this->escape($this->shorten((string)($row['content_text'] ?? ''), 180)) . '</td>'
            . '</tr>';
    }

    /**
     * @param array<string,mixed> $issue
     */
    private function renderIssuePill(array $issue): string
    {
        $severity = (string)($issue['severity'] ?? 'notice');
        $code = (string)($issue['code'] ?? 'issue');
        $class = match ($severity) {
            'critical' => 'pill pill-critical',
            'warning' => 'pill pill-warning',
            default => 'pill pill-notice',
        };

        return '<span class="' . $class . '" title="' . $this->escape((string)($issue['message'] ?? '')) . '">'
            . $this->escape($code)
            . '</span>';
    }

    private function renderUrl(string $url): string
    {
        if ($url === '') {
            return '<span class="muted">No URL</span>';
        }

        return '<a href="' . $this->escape($url) . '" target="_blank" rel="noreferrer">' . $this->escape($url) . '</a>';
    }

    private function shorten(string $value, int $limit): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $limit - 1))) . '...';
    }

    private function cleanPreviewText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function formatDate(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d', $timestamp) : '-';
    }

    private function formatDateTime(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-';
    }

    private function formatNumber(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, '.', ',');
    }

    private function formatSignedNumber(float $value, int $decimals = 0): string
    {
        $formatted = $this->formatNumber(abs($value), $decimals);
        if ($value > 0) {
            return '+' . $formatted;
        }
        if ($value < 0) {
            return '-' . $formatted;
        }

        return $formatted;
    }

    private function formatPercent(float $value): string
    {
        return number_format($value * 100, 2, '.', ',') . '%';
    }

    private function formatSignedPercent(float $value): string
    {
        $formatted = $this->formatPercent(abs($value));
        if ($value > 0) {
            return '+' . $formatted;
        }
        if ($value < 0) {
            return '-' . $formatted;
        }

        return $formatted;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
