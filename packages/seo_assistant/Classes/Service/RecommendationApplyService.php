<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecommendationApplyService
{
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';
    private const CONTENT_TABLE = 'tt_content';
    private const PAGE_SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RENDERED_SNAPSHOT_TABLE = 'tx_seoassistant_rendered_snapshot';
    private const STRUCTURED_DATA_TABLE = 'tx_seoassistant_structured_data';
    private const DEFAULT_CONTENT_CTYPE = 'seo_text';
    private const AUTO_APPLY_CAPABILITIES = ['safe_metadata', 'content_draft', 'image_alt', 'indexing_update', 'structured_data'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UrlNormalizer $urlNormalizer,
    ) {}

    /**
     * @return array{uid:int,pageUid:int,actionType:string,applyCapability:string,seoTitle:string,description:string,changedFields:list<string>,dryRun:bool,contentUid:int,contentHidden:bool,contentHeader:string,imageAltUpdated:int,imageAltSkipped:int}
     */
    public function apply(
        int $recommendationUid,
        bool $dryRun = true,
        bool $force = false,
        bool $publishContent = false,
        string $contentCType = self::DEFAULT_CONTENT_CTYPE,
    ): array
    {
        $recommendation = $this->fetchRecommendation($recommendationUid);
        $pageUid = (int)($recommendation['page_uid'] ?? 0);
        if ($pageUid <= 0) {
            $pageUid = $this->resolvePageUidFromUrl((string)($recommendation['page_url'] ?? ''));
        }
        if ($pageUid <= 0) {
            throw new RuntimeException('Recommendation has no TYPO3 page_uid and the page could not be resolved from its URL. Apply the suggestion manually.', 1760000041);
        }

        $status = (string)($recommendation['status'] ?? '');
        if (!$force && !in_array($status, ['draft', 'approved'], true)) {
            throw new RuntimeException('Recommendation status "' . $status . '" cannot be applied without --force.', 1760000042);
        }

        $action = $this->extractAction($recommendation);
        $actionType = $action['actionType'];
        $applyCapability = $action['applyCapability'];
        $implementedState = $this->implementedState($recommendation, $pageUid, $action);
        if ($implementedState['implemented']) {
            if (!$dryRun) {
                $this->markRecommendationImplemented($recommendationUid, $pageUid, $implementedState);
            }

            return $this->alreadyImplementedResult($recommendationUid, $pageUid, $action, $dryRun, $implementedState['message']);
        }

        if ($actionType === 'metadata_update') {
            return $this->applyMetadataRecommendation($recommendationUid, $pageUid, $action, $dryRun, $force);
        }

        if ($actionType === 'content_gap_brief') {
            return $this->applyContentGapRecommendation(
                $recommendationUid,
                $pageUid,
                $recommendation,
                $action,
                $dryRun,
                $force,
                $publishContent,
                $contentCType
            );
        }

        if ($actionType === 'image_alt_suggestion') {
            return $this->applyImageAltRecommendation($recommendationUid, $pageUid, $recommendation, $action, $dryRun, $force);
        }

        if ($actionType === 'technical_indexing_issue') {
            return $this->applyIndexingRecommendation($recommendationUid, $pageUid, $recommendation, $action, $dryRun, $force);
        }

        if ($actionType === 'structured_data_suggestion') {
            return $this->applyStructuredDataRecommendation($recommendationUid, $pageUid, $recommendation, $action, $dryRun, $force);
        }

        throw new RuntimeException('Recommendation action "' . $actionType . '" is manual and cannot be applied automatically.', 1760000045);
    }

    /**
     * @return array{dryRun:bool,total:int,applied:int,alreadyImplemented:int,skipped:int,failed:int,rows:list<array<string,mixed>>}
     */
    public function applyAll(
        bool $dryRun = true,
        bool $force = false,
        bool $publishContent = false,
        string $contentCType = self::DEFAULT_CONTENT_CTYPE,
        int $limit = 100,
    ): array {
        $recommendations = $this->fetchAutomaticRecommendations($limit, $force);
        $rows = [];
        $applied = 0;
        $alreadyImplemented = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($recommendations as $recommendation) {
            $uid = (int)($recommendation['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            $pageUid = (int)($recommendation['page_uid'] ?? 0);
            if ($pageUid <= 0) {
                $pageUid = $this->resolvePageUidFromUrl((string)($recommendation['page_url'] ?? ''));
            }
            $action = $this->extractAction($recommendation);
            $implementedState = $pageUid > 0
                ? $this->implementedState($recommendation, $pageUid, $action)
                : ['implemented' => false, 'message' => 'Page could not be resolved.', 'checks' => []];

            if ($implementedState['implemented']) {
                if (!$dryRun && $pageUid > 0) {
                    $this->markRecommendationImplemented($uid, $pageUid, $implementedState);
                }
                $alreadyImplemented++;
                $rows[] = [
                    'uid' => $uid,
                    'status' => 'already_implemented',
                    'action' => $action['actionType'],
                    'capability' => $action['applyCapability'],
                    'message' => $implementedState['message'],
                ];
                continue;
            }

            if (!in_array($action['applyCapability'], self::AUTO_APPLY_CAPABILITIES, true)) {
                $skipped++;
                $rows[] = [
                    'uid' => $uid,
                    'status' => 'skipped',
                    'action' => $action['actionType'],
                    'capability' => $action['applyCapability'],
                    'message' => 'Manual recommendation cannot be applied automatically.',
                ];
                continue;
            }

            try {
                $result = $this->apply($uid, $dryRun, $force, $publishContent, $contentCType);
                if (($result['alreadyImplemented'] ?? false) === true) {
                    $alreadyImplemented++;
                    $status = 'already_implemented';
                } else {
                    $applied++;
                    $status = $dryRun ? 'would_apply' : 'applied';
                }
                $rows[] = [
                    'uid' => $uid,
                    'status' => $status,
                    'action' => $result['actionType'],
                    'capability' => $result['applyCapability'],
                    'message' => implode(', ', $result['changedFields']) ?: (string)($result['message'] ?? ''),
                ];
            } catch (RuntimeException $exception) {
                $failed++;
                $rows[] = [
                    'uid' => $uid,
                    'status' => 'error',
                    'action' => $action['actionType'],
                    'capability' => $action['applyCapability'],
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'dryRun' => $dryRun,
            'total' => count($recommendations),
            'applied' => $applied,
            'alreadyImplemented' => $alreadyImplemented,
            'skipped' => $skipped,
            'failed' => $failed,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    public function isAlreadyImplemented(array $recommendation): bool
    {
        $pageUid = (int)($recommendation['page_uid'] ?? 0);
        if ($pageUid <= 0) {
            $pageUid = $this->resolvePageUidFromUrl((string)($recommendation['page_url'] ?? ''));
        }
        if ($pageUid <= 0) {
            return false;
        }

        return $this->implementedState($recommendation, $pageUid, $this->extractAction($recommendation))['implemented'];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>}
     */
    public function actionForRecommendation(array $recommendation): array
    {
        return $this->extractAction($recommendation);
    }

    /**
     * @return array{uid:int,status:string}
     */
    public function reject(int $recommendationUid): array
    {
        $recommendation = $this->fetchRecommendation($recommendationUid);
        $now = time();
        $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->update(
                self::RECOMMENDATION_TABLE,
                [
                    'status' => 'dismissed',
                    'verification_status' => 'rejected',
                    'verification_json' => $this->json([
                        'status' => 'rejected',
                        'message' => 'Recommendation was rejected in the backend module.',
                        'previous_status' => (string)($recommendation['status'] ?? ''),
                    ]),
                    'tstamp' => $now,
                ],
                ['uid' => $recommendationUid],
                ['tstamp' => Connection::PARAM_INT]
            );

        return [
            'uid' => $recommendationUid,
            'status' => 'dismissed',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAutomaticRecommendations(int $limit, bool $force): array
    {
        $limit = max(1, min(500, $limit));
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from(self::RECOMMENDATION_TABLE)
            ->orderBy('priority', 'DESC')
            ->addOrderBy('tstamp', 'DESC')
            ->setMaxResults($limit * 3);

        if ($force) {
            $queryBuilder
                ->where($queryBuilder->expr()->notIn('status', ':excludedStatuses'))
                ->setParameter('excludedStatuses', ['applied', 'implemented', 'dismissed'], Connection::PARAM_STR_ARRAY);
        } else {
            $queryBuilder
                ->where($queryBuilder->expr()->in('status', ':statuses'))
                ->setParameter('statuses', ['draft', 'approved'], Connection::PARAM_STR_ARRAY);
        }

        return array_slice($queryBuilder->executeQuery()->fetchAllAssociative(), 0, $limit);
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>} $action
     * @return array{implemented:bool,message:string,checks:array<string,mixed>}
     */
    private function implementedState(array $recommendation, int $pageUid, array $action): array
    {
        return match ($action['actionType']) {
            'metadata_update' => $this->metadataImplementedState($recommendation, $pageUid, $action),
            'content_gap_brief' => $this->contentImplementedState($pageUid, $action),
            'image_alt_suggestion' => $this->imageAltImplementedState($pageUid, $action),
            'structured_data_suggestion' => $this->structuredDataImplementedState($recommendation, $action),
            'technical_indexing_issue' => $this->indexingImplementedState($recommendation, $pageUid, $action),
            default => [
                'implemented' => false,
                'message' => 'No automatic implementation check exists for this action.',
                'checks' => [],
            ],
        };
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{seoTitle:string,description:string} $action
     * @return array{implemented:bool,message:string,checks:array<string,mixed>}
     */
    private function metadataImplementedState(array $recommendation, int $pageUid, array $action): array
    {
        $metadata = $this->fetchPageMetadata($pageUid);
        $renderedSnapshot = $this->fetchRenderedSnapshot((string)($recommendation['page_url'] ?? ''));
        $checks = [];

        if ($action['seoTitle'] !== '') {
            $pageValue = (string)($metadata['seo_title'] ?? '');
            $renderedValue = (string)($renderedSnapshot['html_title'] ?? '');
            $checks['seo_title'] = [
                'expected' => $action['seoTitle'],
                'page_value' => $pageValue,
                'rendered_value' => $renderedValue,
                'matched' => $this->metadataMatches($action['seoTitle'], $pageValue)
                    || $this->metadataMatches($action['seoTitle'], $renderedValue),
            ];
        }

        if ($action['description'] !== '') {
            $pageValue = (string)($metadata['description'] ?? '');
            $renderedValue = (string)($renderedSnapshot['meta_description'] ?? '');
            $checks['description'] = [
                'expected' => $action['description'],
                'page_value' => $pageValue,
                'rendered_value' => $renderedValue,
                'matched' => $this->metadataMatches($action['description'], $pageValue)
                    || $this->metadataMatches($action['description'], $renderedValue),
            ];
        }

        return $this->stateFromChecks($checks, 'Recommended metadata already matches the page.');
    }

    /**
     * @param array{payload:array<string,mixed>} $action
     * @return array{implemented:bool,message:string,checks:array<string,mixed>}
     */
    private function contentImplementedState(int $pageUid, array $action): array
    {
        $payload = $action['payload'];
        $contentText = $this->normalizeText($this->fetchVisibleContentText($pageUid));
        $checks = [];
        $header = $this->normalizeText((string)($payload['content_element_header'] ?? ''));
        if ($header !== '') {
            $checks['content_header'] = [
                'expected' => $header,
                'matched' => str_contains($contentText, $header),
            ];
        }

        $bodyText = $this->cleanText((string)($payload['content_body_html'] ?? $payload['content_brief'] ?? ''));
        $bodyNeedle = $this->contentNeedle($bodyText);
        if ($bodyNeedle !== '') {
            $checks['content_body'] = [
                'expected' => $bodyNeedle,
                'matched' => str_contains($contentText, $this->normalizeText($bodyNeedle)),
            ];
        }

        return $this->stateFromChecks($checks, 'Recommended content is already visible on the page.');
    }

    /**
     * @param array{payload:array<string,mixed>} $action
     * @return array{implemented:bool,message:string,checks:array<string,mixed>}
     */
    private function imageAltImplementedState(int $pageUid, array $action): array
    {
        $suggestions = $this->imageAltSuggestions($action['payload']['image_alt_suggestions'] ?? []);
        if ($suggestions === []) {
            return [
                'implemented' => false,
                'message' => 'No usable image alt suggestions exist.',
                'checks' => [],
            ];
        }

        $references = $this->fetchImageReferencesForPage($pageUid);
        $checks = [];
        foreach ($suggestions as $index => $suggestion) {
            $matchedReferences = $this->matchImageReferences($suggestion['src'], $references);
            $currentAlt = count($matchedReferences) === 1 ? (string)($matchedReferences[0]['alternative'] ?? '') : '';
            $checks['image_' . ($index + 1)] = [
                'src' => $suggestion['src'],
                'expected' => $suggestion['alt_text'],
                'current' => $currentAlt,
                'matched' => count($matchedReferences) === 1
                    && $this->normalizeText($currentAlt) === $this->normalizeText($suggestion['alt_text']),
            ];
        }

        return $this->stateFromChecks($checks, 'Recommended image alt text is already stored on the page file references.');
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{payload:array<string,mixed>} $action
     * @return array{implemented:bool,message:string,checks:array<string,mixed>}
     */
    private function structuredDataImplementedState(array $recommendation, array $action): array
    {
        $schemaType = trim((string)($action['payload']['structured_data_type'] ?? ''));
        if ($schemaType === '') {
            return [
                'implemented' => false,
                'message' => 'No structured data type was provided.',
                'checks' => [],
            ];
        }

        $renderedSnapshot = $this->fetchRenderedSnapshot((string)($recommendation['page_url'] ?? ''));
        $pageUrl = (string)($recommendation['page_url'] ?? '');
        $structuredData = $this->decodeJson((string)($renderedSnapshot['structured_data_json'] ?? '[]'));
        $checks = [
            'structured_data_type' => [
                'expected' => $schemaType,
                'matched' => $this->jsonContainsSchemaTypeForPage($structuredData, $schemaType, $pageUrl),
            ],
        ];

        return $this->stateFromChecks($checks, 'Recommended structured data type is already rendered.');
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{payload:array<string,mixed>} $action
     * @return array{implemented:bool,message:string,checks:array<string,mixed>}
     */
    private function indexingImplementedState(array $recommendation, int $pageUid, array $action): array
    {
        $payload = $action['payload'];
        $page = $this->fetchPageIndexing($pageUid);
        $checks = [];

        if (array_key_exists('no_index', $payload)) {
            $expected = (int)$payload['no_index'];
            $checks['no_index'] = [
                'expected' => $expected,
                'current' => (int)($page['no_index'] ?? 0),
                'matched' => (int)($page['no_index'] ?? 0) === $expected,
            ];
        }

        if ((string)($payload['canonical_link'] ?? '') !== '') {
            $expectedCanonical = $this->urlNormalizer->normalize((string)$payload['canonical_link']);
            $currentCanonical = $this->urlNormalizer->normalize((string)($page['canonical_link'] ?? ''));
            $renderedSnapshot = $this->fetchRenderedSnapshot((string)($recommendation['page_url'] ?? ''));
            $renderedCanonical = $this->urlNormalizer->normalize((string)($renderedSnapshot['canonical_url'] ?? ''));
            $checks['canonical_link'] = [
                'expected' => $expectedCanonical,
                'current' => $currentCanonical,
                'rendered' => $renderedCanonical,
                'matched' => $currentCanonical === $expectedCanonical || $renderedCanonical === $expectedCanonical,
            ];
        }

        return $this->stateFromChecks($checks, 'Recommended indexing state is already configured.');
    }

    /**
     * @param array<string,mixed> $checks
     * @return array{implemented:bool,message:string,checks:array<string,mixed>}
     */
    private function stateFromChecks(array $checks, string $successMessage): array
    {
        if ($checks === []) {
            return [
                'implemented' => false,
                'message' => 'No automatic implementation checks could be built.',
                'checks' => [],
            ];
        }

        $failed = array_filter($checks, static fn(array $check): bool => ($check['matched'] ?? false) !== true);
        return [
            'implemented' => $failed === [],
            'message' => $failed === [] ? $successMessage : 'Recommendation is not implemented yet.',
            'checks' => $checks,
        ];
    }

    /**
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string} $action
     * @return array<string,mixed>
     */
    private function alreadyImplementedResult(int $recommendationUid, int $pageUid, array $action, bool $dryRun, string $message): array
    {
        return [
            'uid' => $recommendationUid,
            'pageUid' => $pageUid,
            'actionType' => $action['actionType'],
            'applyCapability' => $action['applyCapability'],
            'seoTitle' => $action['seoTitle'],
            'description' => $action['description'],
            'changedFields' => [],
            'dryRun' => $dryRun,
            'contentUid' => 0,
            'contentHidden' => false,
            'contentHeader' => '',
            'imageAltUpdated' => 0,
            'imageAltSkipped' => 0,
            'alreadyImplemented' => true,
            'message' => $message,
        ];
    }

    /**
     * @param array{implemented:bool,message:string,checks:array<string,mixed>} $implementedState
     */
    private function markRecommendationImplemented(int $recommendationUid, int $pageUid, array $implementedState): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->update(
                self::RECOMMENDATION_TABLE,
                [
                    'page_uid' => $pageUid,
                    'status' => 'implemented',
                    'verification_status' => 'verified',
                    'verification_json' => $this->json($implementedState),
                    'verified_at' => $now,
                    'tstamp' => $now,
                ],
                ['uid' => $recommendationUid],
                [
                    'page_uid' => Connection::PARAM_INT,
                    'verified_at' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                ]
            );
    }

    /**
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>} $action
     * @return array{uid:int,pageUid:int,actionType:string,applyCapability:string,seoTitle:string,description:string,changedFields:list<string>,dryRun:bool,contentUid:int,contentHidden:bool,contentHeader:string,imageAltUpdated:int,imageAltSkipped:int}
     */
    private function applyMetadataRecommendation(int $recommendationUid, int $pageUid, array $action, bool $dryRun, bool $force): array
    {
        $actionType = $action['actionType'];
        $applyCapability = $action['applyCapability'];
        $seoTitle = $action['seoTitle'];
        $description = $action['description'];

        if (!$force && $applyCapability !== 'safe_metadata') {
            throw new RuntimeException('Recommendation apply capability "' . $applyCapability . '" is not safe for automatic apply.', 1760000046);
        }
        if ($seoTitle === '' && $description === '') {
            throw new RuntimeException('Recommendation has no proposed SEO title or description.', 1760000043);
        }

        $currentMetadata = $this->fetchPageMetadata($pageUid);
        $changedFields = [];
        if ($seoTitle !== '') {
            $changedFields[] = 'seo_title';
        }
        if ($description !== '') {
            $changedFields[] = 'description';
        }

        if (!$dryRun) {
            $pageData = [
                'tstamp' => time(),
            ];
            $types = [
                'tstamp' => Connection::PARAM_INT,
            ];
            if ($seoTitle !== '') {
                $pageData['seo_title'] = $seoTitle;
            }
            if ($description !== '') {
                $pageData['description'] = $description;
            }

            $this->connectionPool->getConnectionForTable('pages')
                ->update('pages', $pageData, ['uid' => $pageUid], $types);

            $appliedChanges = [
                'action_type' => $actionType,
                'apply_capability' => $applyCapability,
                'page_uid' => $pageUid,
                'fields' => [
                    'seo_title' => [
                        'before' => (string)($currentMetadata['seo_title'] ?? ''),
                        'after' => $seoTitle,
                        'changed' => $seoTitle !== '' && $seoTitle !== (string)($currentMetadata['seo_title'] ?? ''),
                    ],
                    'description' => [
                        'before' => (string)($currentMetadata['description'] ?? ''),
                        'after' => $description,
                        'changed' => $description !== '' && $description !== (string)($currentMetadata['description'] ?? ''),
                    ],
                ],
            ];

            $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
                ->update(
                    self::RECOMMENDATION_TABLE,
                    [
                        'page_uid' => $pageUid,
                        'status' => 'applied',
                        'applied_changes_json' => json_encode($appliedChanges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'verification_status' => 'pending',
                        'verification_json' => '',
                        'applied_at' => time(),
                        'verified_at' => 0,
                        'tstamp' => time(),
                    ],
                    ['uid' => $recommendationUid],
                    [
                        'page_uid' => Connection::PARAM_INT,
                        'applied_at' => Connection::PARAM_INT,
                        'verified_at' => Connection::PARAM_INT,
                        'tstamp' => Connection::PARAM_INT,
                    ]
                );
        }

        return [
            'uid' => $recommendationUid,
            'pageUid' => $pageUid,
            'actionType' => $actionType,
            'applyCapability' => $applyCapability,
            'seoTitle' => $seoTitle,
            'description' => $description,
            'changedFields' => $changedFields,
            'dryRun' => $dryRun,
            'contentUid' => 0,
            'contentHidden' => false,
            'contentHeader' => '',
            'imageAltUpdated' => 0,
            'imageAltSkipped' => 0,
            'alreadyImplemented' => false,
            'message' => '',
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>} $action
     * @return array{uid:int,pageUid:int,actionType:string,applyCapability:string,seoTitle:string,description:string,changedFields:list<string>,dryRun:bool,contentUid:int,contentHidden:bool,contentHeader:string,imageAltUpdated:int,imageAltSkipped:int}
     */
    private function applyContentGapRecommendation(
        int $recommendationUid,
        int $pageUid,
        array $recommendation,
        array $action,
        bool $dryRun,
        bool $force,
        bool $publishContent,
        string $contentCType,
    ): array {
        $applyCapability = $action['applyCapability'];
        if (!$force && $applyCapability !== 'content_draft') {
            throw new RuntimeException('Recommendation apply capability "' . $applyCapability . '" is not enabled for content automation.', 1760000048);
        }

        $contentDraft = $this->buildContentDraft($recommendation, $action);
        if ($publishContent && !$contentDraft['hasReadyBody'] && !$force) {
            throw new RuntimeException('Publishing content requires an AI content_body_html payload. Run AI generation again, or use --force to publish the fallback draft.', 1760000049);
        }

        $contentCType = $this->normalizeCType($contentCType);
        $contentHidden = !$publishContent;
        $contentUid = 0;
        $changedFields = ['tt_content.' . $contentCType, 'tt_content.header', 'tt_content.bodytext'];
        if ($this->shouldUseH1Header($recommendation)) {
            $changedFields[] = 'tt_content.header_layout';
        }
        if ($contentHidden) {
            $changedFields[] = 'tt_content.hidden';
        }

        if (!$dryRun) {
            $connection = $this->connectionPool->getConnectionForTable(self::CONTENT_TABLE);
            $columns = $connection->getSchemaInformation()->listTableColumnNames(self::CONTENT_TABLE);
            foreach (['pid', 'CType', 'header', 'bodytext'] as $requiredColumn) {
                if (!in_array($requiredColumn, $columns, true)) {
                    throw new RuntimeException('tt_content is missing required column "' . $requiredColumn . '".', 1760000050);
                }
            }

            $contentData = [
                'pid' => $pageUid,
                'CType' => $contentCType,
                'header' => $contentDraft['header'],
                'bodytext' => $contentDraft['bodytext'],
                'colPos' => 0,
                'sorting' => $this->nextContentSorting($pageUid, 0),
                'hidden' => $contentHidden ? 1 : 0,
                'deleted' => 0,
                'sys_language_uid' => 0,
                'header_layout' => $contentDraft['headerLayout'],
                'crdate' => time(),
                'tstamp' => time(),
            ];
            $contentData = array_intersect_key($contentData, array_flip($columns));

            $types = [];
            foreach (['pid', 'colPos', 'sorting', 'hidden', 'deleted', 'sys_language_uid', 'header_layout', 'crdate', 'tstamp'] as $integerColumn) {
                if (array_key_exists($integerColumn, $contentData)) {
                    $types[$integerColumn] = Connection::PARAM_INT;
                }
            }

            $connection->insert(self::CONTENT_TABLE, $contentData, $types);
            $contentUid = (int)$connection->lastInsertId();

            $appliedChanges = [
                'action_type' => $action['actionType'],
                'apply_capability' => $applyCapability,
                'page_uid' => $pageUid,
                'content_table' => self::CONTENT_TABLE,
                'content_uid' => $contentUid,
                'content_hidden' => $contentHidden,
                'content_ctype' => $contentCType,
                'content_header' => $contentDraft['header'],
                'content_bodytext' => $contentDraft['bodytext'],
                'used_ready_body' => $contentDraft['hasReadyBody'],
            ];

            $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
                ->update(
                    self::RECOMMENDATION_TABLE,
                    [
                        'page_uid' => $pageUid,
                        'status' => 'applied',
                        'applied_changes_json' => $this->json($appliedChanges),
                        'verification_status' => $contentHidden ? 'content_draft_created' : 'content_published',
                        'verification_json' => '',
                        'applied_at' => time(),
                        'verified_at' => 0,
                        'tstamp' => time(),
                    ],
                    ['uid' => $recommendationUid],
                    [
                        'page_uid' => Connection::PARAM_INT,
                        'applied_at' => Connection::PARAM_INT,
                        'verified_at' => Connection::PARAM_INT,
                        'tstamp' => Connection::PARAM_INT,
                    ]
                );
        }

        return [
            'uid' => $recommendationUid,
            'pageUid' => $pageUid,
            'actionType' => $action['actionType'],
            'applyCapability' => $applyCapability,
            'seoTitle' => '',
            'description' => '',
            'changedFields' => $changedFields,
            'dryRun' => $dryRun,
            'contentUid' => $contentUid,
            'contentHidden' => $contentHidden,
            'contentHeader' => $contentDraft['header'],
            'imageAltUpdated' => 0,
            'imageAltSkipped' => 0,
            'alreadyImplemented' => false,
            'message' => '',
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>} $action
     * @return array{uid:int,pageUid:int,actionType:string,applyCapability:string,seoTitle:string,description:string,changedFields:list<string>,dryRun:bool,contentUid:int,contentHidden:bool,contentHeader:string,imageAltUpdated:int,imageAltSkipped:int}
     */
    private function applyImageAltRecommendation(int $recommendationUid, int $pageUid, array $recommendation, array $action, bool $dryRun, bool $force): array
    {
        $applyCapability = $action['applyCapability'];
        if (!$force && $applyCapability !== 'image_alt') {
            throw new RuntimeException('Recommendation apply capability "' . $applyCapability . '" is not enabled for image alt automation.', 1760000054);
        }

        $suggestions = $this->imageAltSuggestions($action['payload']['image_alt_suggestions'] ?? []);
        if ($suggestions === []) {
            throw new RuntimeException('Recommendation has no usable image alt suggestions.', 1760000055);
        }

        $references = $this->fetchImageReferencesForPage($pageUid);
        if ($references === []) {
            throw new RuntimeException('No TYPO3 file references were found for this page.', 1760000056);
        }

        $matches = [];
        $skipped = [];
        foreach ($suggestions as $suggestion) {
            $matchedReferences = $this->matchImageReferences($suggestion['src'], $references);
            if (count($matchedReferences) !== 1) {
                $skipped[] = [
                    'src' => $suggestion['src'],
                    'alt_text' => $suggestion['alt_text'],
                    'reason' => count($matchedReferences) === 0 ? 'No matching sys_file_reference found.' : 'Multiple matching sys_file_reference rows found.',
                ];
                continue;
            }

            $reference = $matchedReferences[0];
            $matches[] = [
                'reference_uid' => (int)$reference['reference_uid'],
                'file_uid' => (int)$reference['file_uid'],
                'src' => $suggestion['src'],
                'identifier' => (string)($reference['identifier'] ?? ''),
                'filename' => (string)($reference['filename'] ?? ''),
                'fieldname' => (string)($reference['fieldname'] ?? ''),
                'tablenames' => (string)($reference['tablenames'] ?? ''),
                'uid_foreign' => (int)($reference['uid_foreign'] ?? 0),
                'before' => (string)($reference['alternative'] ?? ''),
                'after' => $suggestion['alt_text'],
                'reason' => $suggestion['reason'],
            ];
        }

        if ($matches === []) {
            throw new RuntimeException('No image alt suggestions could be matched safely. Skipped ' . count($skipped) . ' suggestion(s).', 1760000057);
        }

        if (!$dryRun) {
            $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
            $columns = $connection->getSchemaInformation()->listTableColumnNames('sys_file_reference');
            if (!in_array('alternative', $columns, true)) {
                throw new RuntimeException('sys_file_reference is missing the alternative column.', 1760000058);
            }

            foreach ($matches as $match) {
                $data = [
                    'alternative' => $match['after'],
                ];
                $types = [];
                if (in_array('tstamp', $columns, true)) {
                    $data['tstamp'] = time();
                    $types['tstamp'] = Connection::PARAM_INT;
                }

                $connection->update(
                    'sys_file_reference',
                    $data,
                    ['uid' => (int)$match['reference_uid']],
                    $types
                );
            }

            $appliedChanges = [
                'action_type' => $action['actionType'],
                'apply_capability' => $applyCapability,
                'page_uid' => $pageUid,
                'updated_references' => $matches,
                'skipped_suggestions' => $skipped,
            ];

            $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
                ->update(
                    self::RECOMMENDATION_TABLE,
                    [
                        'page_uid' => $pageUid,
                        'status' => 'applied',
                        'applied_changes_json' => $this->json($appliedChanges),
                        'verification_status' => 'pending',
                        'verification_json' => '',
                        'applied_at' => time(),
                        'verified_at' => 0,
                        'tstamp' => time(),
                    ],
                    ['uid' => $recommendationUid],
                    [
                        'page_uid' => Connection::PARAM_INT,
                        'applied_at' => Connection::PARAM_INT,
                        'verified_at' => Connection::PARAM_INT,
                        'tstamp' => Connection::PARAM_INT,
                    ]
                );
        }

        return [
            'uid' => $recommendationUid,
            'pageUid' => $pageUid,
            'actionType' => $action['actionType'],
            'applyCapability' => $applyCapability,
            'seoTitle' => '',
            'description' => '',
            'changedFields' => ['sys_file_reference.alternative'],
            'dryRun' => $dryRun,
            'contentUid' => 0,
            'contentHidden' => false,
            'contentHeader' => '',
            'imageAltUpdated' => count($matches),
            'imageAltSkipped' => count($skipped),
            'alreadyImplemented' => false,
            'message' => '',
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>} $action
     * @return array<string,mixed>
     */
    private function applyIndexingRecommendation(int $recommendationUid, int $pageUid, array $recommendation, array $action, bool $dryRun, bool $force): array
    {
        $applyCapability = $action['applyCapability'];
        if (!$force && $applyCapability !== 'indexing_update') {
            throw new RuntimeException('Recommendation apply capability "' . $applyCapability . '" is not enabled for indexing automation.', 1760000060);
        }

        $payload = $action['payload'];
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('pages');
        $pageBefore = $this->fetchPageIndexing($pageUid);
        $data = ['tstamp' => time()];
        $types = ['tstamp' => Connection::PARAM_INT];
        $changedFields = [];

        if (array_key_exists('no_index', $payload) && in_array('no_index', $columns, true)) {
            $data['no_index'] = (int)$payload['no_index'];
            $types['no_index'] = Connection::PARAM_INT;
            $changedFields[] = 'pages.no_index';
        }
        if (array_key_exists('no_follow', $payload) && in_array('no_follow', $columns, true)) {
            $data['no_follow'] = (int)$payload['no_follow'];
            $types['no_follow'] = Connection::PARAM_INT;
            $changedFields[] = 'pages.no_follow';
        }
        if ((string)($payload['canonical_link'] ?? '') !== '' && in_array('canonical_link', $columns, true)) {
            $data['canonical_link'] = (string)$payload['canonical_link'];
            $changedFields[] = 'pages.canonical_link';
        }

        if (count($data) === 1) {
            throw new RuntimeException('No supported indexing fields are available on pages.', 1760000061);
        }

        if (!$dryRun) {
            $connection->update('pages', $data, ['uid' => $pageUid], $types);
            $appliedChanges = [
                'action_type' => $action['actionType'],
                'apply_capability' => $applyCapability,
                'page_uid' => $pageUid,
                'before' => $pageBefore,
                'after' => array_intersect_key($data, array_flip(['no_index', 'no_follow', 'canonical_link'])),
            ];

            $this->markRecommendationApplied($recommendationUid, $pageUid, $appliedChanges, 'pending');
        }

        return [
            'uid' => $recommendationUid,
            'pageUid' => $pageUid,
            'actionType' => $action['actionType'],
            'applyCapability' => $applyCapability,
            'seoTitle' => '',
            'description' => '',
            'changedFields' => $changedFields,
            'dryRun' => $dryRun,
            'contentUid' => 0,
            'contentHidden' => false,
            'contentHeader' => '',
            'imageAltUpdated' => 0,
            'imageAltSkipped' => 0,
            'alreadyImplemented' => false,
            'message' => '',
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>} $action
     * @return array<string,mixed>
     */
    private function applyStructuredDataRecommendation(int $recommendationUid, int $pageUid, array $recommendation, array $action, bool $dryRun, bool $force): array
    {
        $applyCapability = $action['applyCapability'];
        if (!$force && $applyCapability !== 'structured_data') {
            throw new RuntimeException('Recommendation apply capability "' . $applyCapability . '" is not enabled for structured-data automation.', 1760000062);
        }

        $schema = $this->buildStructuredDataSchema($recommendation, $pageUid, $action);
        if ($schema === []) {
            throw new RuntimeException('No safe structured-data payload could be generated for this recommendation.', 1760000063);
        }

        if (!$dryRun) {
            $connection = $this->connectionPool->getConnectionForTable(self::STRUCTURED_DATA_TABLE);
            $this->assertTableExists(self::STRUCTURED_DATA_TABLE);
            $now = time();
            $schemaHash = hash('sha256', $pageUid . '|' . (string)$schema['schema_type'] . '|' . $this->json($schema['json_ld']));
            $data = [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'page_uid' => $pageUid,
                'page_url' => (string)($recommendation['page_url'] ?? ''),
                'schema_type' => (string)$schema['schema_type'],
                'json_ld' => $this->json($schema['json_ld']),
                'source_recommendation' => $recommendationUid,
                'enabled' => 1,
                'schema_hash' => $schemaHash,
            ];
            $types = [
                'pid' => Connection::PARAM_INT,
                'tstamp' => Connection::PARAM_INT,
                'crdate' => Connection::PARAM_INT,
                'page_uid' => Connection::PARAM_INT,
                'source_recommendation' => Connection::PARAM_INT,
                'enabled' => Connection::PARAM_INT,
            ];

            $existingUid = (int)$connection->createQueryBuilder()
                ->select('uid')
                ->from(self::STRUCTURED_DATA_TABLE)
                ->where('schema_hash = :schemaHash')
                ->setParameter('schemaHash', $schemaHash)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($existingUid > 0) {
                unset($data['crdate'], $types['crdate']);
                $connection->update(self::STRUCTURED_DATA_TABLE, $data, ['uid' => $existingUid], $types);
            } else {
                $connection->insert(self::STRUCTURED_DATA_TABLE, $data, $types);
                $existingUid = (int)$connection->lastInsertId();
            }

            $appliedChanges = [
                'action_type' => $action['actionType'],
                'apply_capability' => $applyCapability,
                'page_uid' => $pageUid,
                'structured_data_uid' => $existingUid,
                'schema_type' => (string)$schema['schema_type'],
                'json_ld' => $schema['json_ld'],
            ];
            $this->markRecommendationApplied($recommendationUid, $pageUid, $appliedChanges, 'pending');
        }

        return [
            'uid' => $recommendationUid,
            'pageUid' => $pageUid,
            'actionType' => $action['actionType'],
            'applyCapability' => $applyCapability,
            'seoTitle' => '',
            'description' => '',
            'changedFields' => [self::STRUCTURED_DATA_TABLE . '.json_ld'],
            'dryRun' => $dryRun,
            'contentUid' => 0,
            'contentHidden' => false,
            'contentHeader' => '',
            'imageAltUpdated' => 0,
            'imageAltSkipped' => 0,
            'alreadyImplemented' => false,
            'message' => '',
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>}
     */
    private function extractAction(array $recommendation): array
    {
        $payload = json_decode((string)($recommendation['action_payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $seoTitle = trim((string)($payload['seo_title'] ?? $recommendation['proposed_seo_title'] ?? ''));
        $description = trim((string)($payload['description'] ?? $recommendation['proposed_description'] ?? ''));
        $storedActionType = trim((string)($recommendation['action_type'] ?? ''));
        $actionType = $storedActionType;
        $recommendationType = (string)($recommendation['recommendation_type'] ?? '');

        if (($actionType === '' || $actionType === 'manual_review') && $seoTitle === '' && $this->isLongTitleRecommendation($recommendation)) {
            $actionType = 'metadata_update';
            $seoTitle = $this->generateSeoTitle($recommendation);
            $payload['target_table'] = 'pages';
            $payload['seo_title'] = $seoTitle;
        }

        if (($actionType === '' || $actionType === 'manual_review') && $description === '' && $this->isLongDescriptionRecommendation($recommendation)) {
            $actionType = 'metadata_update';
            $description = $this->generateMetaDescription($recommendation);
            $payload['target_table'] = 'pages';
            $payload['description'] = $description;
        }

        if (($actionType === '' || $actionType === 'manual_review') && $this->isContentRecommendation($recommendation)) {
            $actionType = 'content_gap_brief';
            $payload = array_replace($payload, $this->contentPayloadForLegacyRecommendation($recommendation));
        }

        if (($actionType === '' || $actionType === 'manual_review') && $this->isIndexingRecommendation($recommendation)) {
            $actionType = 'technical_indexing_issue';
            $payload = array_replace($payload, $this->indexingPayloadForRecommendation($recommendation));
        }

        if (($actionType === '' || $actionType === 'manual_review') && str_contains($recommendationType, 'structured_data')) {
            $actionType = 'structured_data_suggestion';
            $payload['structured_data_type'] = (string)($payload['structured_data_type'] ?? $this->inferStructuredDataType($recommendation));
        }

        if ($actionType === '' && ($seoTitle !== '' || $description !== '')) {
            $actionType = 'metadata_update';
        }

        $targetTable = trim((string)($payload['target_table'] ?? 'pages'));
        if ($actionType === 'metadata_update' && $targetTable !== '' && $targetTable !== 'pages') {
            throw new RuntimeException('Metadata updates can only target the TYPO3 pages table.', 1760000047);
        }

        $applyCapability = trim((string)($recommendation['apply_capability'] ?? ''));
        if (
            ($applyCapability === '' || ($storedActionType === '' && $applyCapability === 'manual'))
            && $actionType === 'metadata_update'
            && ($seoTitle !== '' || $description !== '')
        ) {
            $applyCapability = 'safe_metadata';
        }
        if (
            ($applyCapability === '' || $applyCapability === 'manual')
            && $actionType === 'content_gap_brief'
            && (
                (string)($payload['content_body_html'] ?? '') !== ''
                || (string)($payload['content_brief'] ?? '') !== ''
                || ($payload['suggested_headings'] ?? []) !== []
            )
        ) {
            $applyCapability = 'content_draft';
        }
        if (
            ($applyCapability === '' || $applyCapability === 'manual')
            && $actionType === 'image_alt_suggestion'
            && $this->imageAltSuggestions($payload['image_alt_suggestions'] ?? []) !== []
        ) {
            $applyCapability = 'image_alt';
        }
        if (
            ($applyCapability === '' || $applyCapability === 'manual')
            && $actionType === 'technical_indexing_issue'
            && $this->hasIndexingPayload($payload)
        ) {
            $applyCapability = 'indexing_update';
        }
        if (
            ($applyCapability === '' || $applyCapability === 'manual')
            && $actionType === 'structured_data_suggestion'
            && (string)($payload['structured_data_type'] ?? '') !== ''
        ) {
            $applyCapability = 'structured_data';
        }

        return [
            'actionType' => $actionType !== '' ? $actionType : 'manual_review',
            'applyCapability' => $applyCapability !== '' ? $applyCapability : 'manual',
            'seoTitle' => mb_substr($seoTitle, 0, 60),
            'description' => mb_substr($description, 0, 155),
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{payload:array<string,mixed>} $action
     * @return array{header:string,bodytext:string,headerLayout:int,hasReadyBody:bool}
     */
    private function buildContentDraft(array $recommendation, array $action): array
    {
        $payload = $action['payload'];
        $headings = $this->stringList($payload['suggested_headings'] ?? []);
        $header = trim((string)($payload['content_element_header'] ?? ''));
        if ($header === '') {
            $header = $headings[0] ?? '';
        }
        if ($header === '') {
            $header = $this->fallbackContentHeader($recommendation);
        }

        $readyBody = $this->sanitizeContentHtml((string)($payload['content_body_html'] ?? ''));
        $hasReadyBody = $this->countWords($readyBody) >= 60;
        $bodytext = $hasReadyBody ? $readyBody : $this->buildFallbackContentBody($recommendation, $payload, $headings);

        if ($bodytext === '') {
            throw new RuntimeException('Recommendation has no usable content body to create a content element.', 1760000053);
        }

        return [
            'header' => mb_substr($header, 0, 120),
            'bodytext' => $bodytext,
            'headerLayout' => $this->shouldUseH1Header($recommendation) ? 1 : 2,
            'hasReadyBody' => $hasReadyBody,
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array<string,mixed> $payload
     * @param list<string> $headings
     */
    private function buildFallbackContentBody(array $recommendation, array $payload, array $headings): string
    {
        $topic = $this->contentTopic($recommendation, $headings);
        $brief = $this->cleanText((string)($payload['content_brief'] ?? ''));
        $recommendationText = $this->cleanText((string)($recommendation['recommendation'] ?? ''));

        $paragraphs = [];
        $paragraphs[] = $topic !== ''
            ? 'Diese Ergaenzung vertieft das Thema ' . $topic . ' fuer Besucher, die eine konkrete Einschaetzung, klare naechste Schritte und eine fachliche Einordnung erwarten.'
            : 'Diese Ergaenzung vertieft den vorhandenen Seiteninhalt mit mehr Kontext, klaren naechsten Schritten und einer fachlichen Einordnung fuer Besucher.';
        $paragraphs[] = 'Wichtig ist, Ausgangslage, Ziel, technische Anforderungen und wirtschaftlichen Nutzen zusammenzubringen. So wird schneller sichtbar, ob eine Optimierung, ein Relaunch, bessere Inhalte oder eine technische Umsetzung der passende naechste Schritt ist.';
        $paragraphs[] = 'WALDBYTE kann dabei Analyse, Konzeption, TYPO3-Umsetzung, Performance, SEO und laufende Wartung verbinden. Dadurch entstehen Inhalte und Systeme, die nicht nur auffindbar sind, sondern auch zu qualifizierten Anfragen fuehren.';

        if ($brief !== '' || $recommendationText !== '') {
            $paragraphs[] = trim($brief . ' ' . $recommendationText);
        }

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = $this->cleanText($paragraph);
            if ($paragraph !== '') {
                $html .= '<p>' . $this->html($paragraph) . '</p>';
            }
        }

        $items = array_values(array_slice(array_filter($headings), 1, 3));
        if ($items !== []) {
            $html .= '<ul>';
            foreach ($items as $item) {
                $html .= '<li>' . $this->html($item) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param list<string> $headings
     */
    private function contentTopic(array $recommendation, array $headings): string
    {
        $query = trim((string)($recommendation['query_text'] ?? ''));
        if ($query !== '') {
            return $query;
        }

        $heading = trim($headings[0] ?? '');
        if ($heading !== '') {
            return $heading;
        }

        $path = (string)(parse_url((string)($recommendation['page_url'] ?? ''), PHP_URL_PATH) ?: '');
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $lastSegment = end($segments);
        if (is_string($lastSegment) && $lastSegment !== '') {
            return str_replace('-', ' ', $lastSegment);
        }

        return '';
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function fallbackContentHeader(array $recommendation): string
    {
        $topic = $this->contentTopic($recommendation, []);
        if ($topic !== '') {
            return ucfirst($topic);
        }

        return 'Ergaenzender SEO-Inhalt';
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function isLongTitleRecommendation(array $recommendation): bool
    {
        return (string)($recommendation['recommendation_type'] ?? '') === 'rendered_long_title'
            || str_contains(mb_strtolower((string)($recommendation['issue'] ?? '')), 'title is longer');
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function isLongDescriptionRecommendation(array $recommendation): bool
    {
        return (string)($recommendation['recommendation_type'] ?? '') === 'rendered_long_meta_description'
            || str_contains(mb_strtolower((string)($recommendation['issue'] ?? '')), 'description is longer');
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function isContentRecommendation(array $recommendation): bool
    {
        $type = (string)($recommendation['recommendation_type'] ?? '');

        return in_array($type, ['rendered_thin_content', 'rendered_missing_h1', 'ai_content_gap', 'ai_internal_links', 'ai_technical_h1'], true)
            || str_contains(mb_strtolower((string)($recommendation['issue'] ?? '')), 'no rendered h1')
            || str_contains(mb_strtolower((string)($recommendation['issue'] ?? '')), 'fewer than 250 words');
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function isIndexingRecommendation(array $recommendation): bool
    {
        $type = (string)($recommendation['recommendation_type'] ?? '');
        $text = mb_strtolower((string)($recommendation['issue'] ?? '') . ' ' . (string)($recommendation['recommendation'] ?? ''));

        return in_array($type, ['rendered_noindex', 'rendered_missing_canonical', 'ai_technical_indexing'], true)
            || str_contains($text, 'noindex')
            || str_contains($text, 'canonical');
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array<string,mixed>
     */
    private function contentPayloadForLegacyRecommendation(array $recommendation): array
    {
        $header = $this->legacyContentHeader($recommendation);

        return [
            'content_brief' => (string)($recommendation['recommendation'] ?? $recommendation['issue'] ?? ''),
            'content_element_header' => $header,
            'content_body_html' => $this->legacyContentBodyHtml($recommendation, $header),
            'suggested_headings' => [$header],
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function legacyContentHeader(array $recommendation): string
    {
        if ($this->shouldUseH1Header($recommendation)) {
            return $this->generatePageTopic($recommendation);
        }

        $type = (string)($recommendation['recommendation_type'] ?? '');
        if ($type === 'ai_internal_links') {
            return 'Weiterführende Themen';
        }
        if (str_contains($this->pagePath($recommendation), 'karlsruhe')) {
            return 'Webentwicklung für die Region Karlsruhe';
        }

        $topic = $this->generatePageTopic($recommendation);
        return $topic !== '' ? $topic . ' im Überblick' : 'Ergänzende Informationen';
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function legacyContentBodyHtml(array $recommendation, string $header): string
    {
        $topic = $this->generatePageTopic($recommendation);
        $recommendationText = $this->cleanText((string)($recommendation['recommendation'] ?? ''));
        $pagePath = $this->pagePath($recommendation);

        $html = '';
        if ((string)($recommendation['recommendation_type'] ?? '') === 'ai_internal_links') {
            $html .= '<p>Ergänzend zu den Projekten führen diese Links zu passenden Leistungsbereichen von WALDBYTE. So können Besucher schneller einordnen, welche technische Arbeit hinter einem Projekt steht und welche Leistungen für ein ähnliches Vorhaben relevant sind.</p>';
            $links = $this->legacyInternalLinks($recommendation);
            if ($links !== []) {
                $html .= '<ul>';
                foreach ($links as $label => $href) {
                    $html .= '<li><a href="' . $this->html($href) . '">' . $this->html($label) . '</a></li>';
                }
                $html .= '</ul>';
            }
            $html .= '<p>Die Verknüpfung von Referenzen, Technologien und Leistungen hilft Nutzern bei der Orientierung und stärkt gleichzeitig die thematische Struktur der Website.</p>';

            return $html;
        }

        if ($this->shouldUseH1Header($recommendation)) {
            return '<p>' . $this->html($topic !== '' ? $topic : $header) . ' ist das zentrale Thema dieser Seite. Die Seite erhält damit eine klare Hauptüberschrift und eine verständlichere Dokumentstruktur für Besucher und Suchmaschinen.</p>'
                . '<p>WALDBYTE achtet bei der Umsetzung auf saubere TYPO3-Strukturen, nachvollziehbare Inhalte, schnelle Ladezeiten und eine klare Verbindung zwischen Thema, Leistung und nächstem Schritt.</p>';
        }

        $html .= '<p>' . $this->html($header) . ' ergänzt die vorhandenen Inhalte mit mehr Kontext für Besucher, die eine konkrete Einschätzung und klare nächste Schritte suchen.</p>';
        if (str_contains($pagePath, '/leistungen') || str_contains($pagePath, '/technologien') || str_contains($recommendationText, 'Karlsruhe')) {
            $html .= '<p>Für Unternehmen in der Region Karlsruhe verbindet WALDBYTE technische Umsetzung, Beratung und laufende Betreuung. Je nach Projekt geht es um TYPO3, E-Commerce, Performance, SEO, Wartung oder individuelle Webentwicklung.</p>';
        } else {
            $html .= '<p>Wichtig sind eine klare Ausgangslage, belastbare Technik, verständliche Inhalte und eine Struktur, die Anfragen, Vertrauen und langfristige Weiterentwicklung unterstützt.</p>';
        }
        if ($recommendationText !== '') {
            $html .= '<p>' . $this->html($recommendationText) . '</p>';
        }
        $html .= '<p>Der Einstieg erfolgt pragmatisch über eine kurze Bestandsaufnahme. Danach lassen sich Prioritäten, Aufwand und sinnvolle nächste Schritte transparent festlegen.</p>';

        return $html;
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array<string,string>
     */
    private function legacyInternalLinks(array $recommendation): array
    {
        $text = mb_strtolower((string)($recommendation['recommendation'] ?? '') . ' ' . (string)($recommendation['issue'] ?? ''));
        $links = [];
        $candidates = [
            'Webentwicklung' => ['/leistungen/webentwicklung', ['webentwicklung']],
            'E-Commerce' => ['/leistungen/e-commerce', ['e-commerce', 'commerce', 'shop']],
            'Performance & SEO' => ['/leistungen/performance-seo', ['performance', 'seo']],
            'Wartung & Hosting' => ['/leistungen/wartung-hosting', ['wartung', 'hosting']],
            'TYPO3' => ['/technologien/typo3', ['typo3']],
            'PrestaShop' => ['/technologien/prestashop', ['prestashop']],
        ];
        foreach ($candidates as $label => [$href, $needles]) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    $links[$label] = $href;
                    break;
                }
            }
        }

        return $links !== [] ? $links : [
            'Webentwicklung' => '/leistungen/webentwicklung',
            'Performance & SEO' => '/leistungen/performance-seo',
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array<string,mixed>
     */
    private function indexingPayloadForRecommendation(array $recommendation): array
    {
        $pageUrl = (string)($recommendation['page_url'] ?? '');
        $path = $this->pagePath($recommendation);
        $text = mb_strtolower((string)($recommendation['issue'] ?? '') . ' ' . (string)($recommendation['recommendation'] ?? ''));

        if ($this->isUtilityNoindexPage($path)) {
            return [
                'no_index' => 1,
                'no_follow' => 0,
            ];
        }

        $payload = [
            'no_index' => 0,
            'no_follow' => 0,
        ];
        if ($pageUrl !== '' && (str_contains($text, 'canonical') || str_contains($text, 'noindex'))) {
            $payload['canonical_link'] = $this->urlNormalizer->normalize($pageUrl);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasIndexingPayload(array $payload): bool
    {
        return array_key_exists('no_index', $payload)
            || array_key_exists('no_follow', $payload)
            || (string)($payload['canonical_link'] ?? '') !== '';
    }

    private function isUtilityNoindexPage(string $path): bool
    {
        return str_contains($path, '/kontakt/danke')
            || str_contains($path, '/impressum')
            || str_contains($path, '/datenschutz');
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function inferStructuredDataType(array $recommendation): string
    {
        $text = mb_strtolower((string)($recommendation['recommendation'] ?? '') . ' ' . (string)($recommendation['issue'] ?? ''));
        $path = $this->pagePath($recommendation);

        if (str_contains($text, 'faq') || str_contains($path, '/blogs/')) {
            return 'FAQPage';
        }
        if (str_contains($text, 'contact') || str_contains($path, '/kontakt')) {
            return 'ContactPage';
        }
        if (str_contains($text, 'itemlist') || str_contains($text, 'collectionpage') || in_array($path, ['/leistungen', '/technologien', '/projekte'], true)) {
            return 'ItemList';
        }

        return 'Service';
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function generateSeoTitle(array $recommendation): string
    {
        $topic = $this->generatePageTopic($recommendation);
        if ($topic === '') {
            $topic = 'WALDBYTE';
        }
        $topic = preg_replace('/\s*[\-|–|—|]\s*WALDBYTE.*$/iu', '', $topic) ?? $topic;
        $suffix = ' | WALDBYTE';
        $maxTopicLength = 60 - mb_strlen($suffix);
        if (mb_strlen($topic) > $maxTopicLength) {
            $topic = rtrim(mb_substr($topic, 0, $maxTopicLength - 1));
        }

        return trim($topic) . $suffix;
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function generateMetaDescription(array $recommendation): string
    {
        $pageUrl = (string)($recommendation['page_url'] ?? '');
        $renderedSnapshot = $this->fetchRenderedSnapshot($pageUrl);
        $current = $this->cleanText((string)($renderedSnapshot['meta_description'] ?? ''));
        if ($current !== '') {
            return $this->truncateSentence($current, 155);
        }

        $topic = $this->generatePageTopic($recommendation);
        $description = 'WALDBYTE unterstützt Unternehmen in der Region Karlsruhe mit ' . ($topic !== '' ? $topic : 'Webentwicklung, SEO und technischer Betreuung') . '. Jetzt Projekt einschätzen lassen.';

        return $this->truncateSentence($description, 155);
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function generatePageTopic(array $recommendation): string
    {
        $pageUid = (int)($recommendation['page_uid'] ?? 0);
        if ($pageUid <= 0) {
            $pageUid = $this->resolvePageUidFromUrl((string)($recommendation['page_url'] ?? ''));
        }
        if ($pageUid > 0) {
            $page = $this->fetchPageRecord($pageUid);
            $title = trim((string)($page['nav_title'] ?? '') ?: (string)($page['seo_title'] ?? '') ?: (string)($page['title'] ?? ''));
            if ($title !== '') {
                return $this->cleanText($title);
            }
        }

        $segment = basename($this->pagePath($recommendation));
        $segment = str_replace('-', ' ', rawurldecode($segment));

        return trim(mb_convert_case($segment, MB_CASE_TITLE, 'UTF-8'));
    }

    private function truncateSentence(string $text, int $limit): string
    {
        $text = $this->cleanText($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $short = rtrim(mb_substr($text, 0, $limit - 1));
        $lastBoundary = max((int)mb_strrpos($short, '.'), (int)mb_strrpos($short, ','), (int)mb_strrpos($short, ' '));
        if ($lastBoundary > 80) {
            $short = rtrim(mb_substr($short, 0, $lastBoundary));
        }

        return rtrim($short, '.,;:- ') . '…';
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function pagePath(array $recommendation): string
    {
        $path = (string)(parse_url((string)($recommendation['page_url'] ?? ''), PHP_URL_PATH) ?: '');

        return '/' . trim($path, '/');
    }

    /**
     * @param mixed $items
     * @return list<array{src:string,alt_text:string,reason:string}>
     */
    private function imageAltSuggestions($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $suggestions = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $src = trim((string)($item['src'] ?? ''));
            $altText = mb_substr($this->cleanText((string)($item['alt_text'] ?? '')), 0, 255);
            if ($src === '' || $altText === '') {
                continue;
            }
            $suggestions[] = [
                'src' => $src,
                'alt_text' => $altText,
                'reason' => mb_substr($this->cleanText((string)($item['reason'] ?? '')), 0, 260),
            ];
            if (count($suggestions) >= 12) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchImageReferencesForPage(int $pageUid): array
    {
        $contentUids = $this->fetchPageContentUids($pageUid);
        $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('sys_file_reference');
        foreach (['uid', 'uid_local', 'uid_foreign', 'tablenames'] as $requiredColumn) {
            if (!in_array($requiredColumn, $columns, true)) {
                return [];
            }
        }

        $fileConnection = $this->connectionPool->getConnectionForTable('sys_file');
        $fileColumns = $fileConnection->getSchemaInformation()->listTableColumnNames('sys_file');
        if (!in_array('uid', $fileColumns, true) || !in_array('identifier', $fileColumns, true)) {
            return [];
        }

        $queryBuilder = $connection->createQueryBuilder();
        $select = [
            'ref.uid AS reference_uid',
            'ref.uid_local AS file_uid',
            'ref.uid_foreign',
            'ref.tablenames',
            'file.identifier',
        ];
        foreach (['fieldname', 'alternative', 'title'] as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = 'ref.' . $column;
            }
        }
        if (in_array('name', $fileColumns, true)) {
            $select[] = 'file.name AS filename';
        }

        $conditions = ['(ref.tablenames = :pagesTable AND ref.uid_foreign = :pageUid)'];
        if ($contentUids !== []) {
            $conditions[] = '(ref.tablenames = :contentTable AND ' . $queryBuilder->expr()->in('ref.uid_foreign', ':contentUids') . ')';
            $queryBuilder->setParameter('contentUids', $contentUids, Connection::PARAM_INT_ARRAY);
        }

        $queryBuilder
            ->selectLiteral(...$select)
            ->from('sys_file_reference', 'ref')
            ->join('ref', 'sys_file', 'file', 'file.uid = ref.uid_local')
            ->where('(' . implode(' OR ', $conditions) . ')')
            ->setParameter('pagesTable', 'pages')
            ->setParameter('contentTable', self::CONTENT_TABLE)
            ->setParameter('pageUid', $pageUid, Connection::PARAM_INT);

        if (in_array('deleted', $columns, true)) {
            $queryBuilder->andWhere('ref.deleted = 0');
        }
        if (in_array('hidden', $columns, true)) {
            $queryBuilder->andWhere('ref.hidden = 0');
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return list<int>
     */
    private function fetchPageContentUids(int $pageUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::CONTENT_TABLE);
        $columns = $connection->getSchemaInformation()->listTableColumnNames(self::CONTENT_TABLE);
        if (!in_array('uid', $columns, true) || !in_array('pid', $columns, true)) {
            return [];
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('uid')
            ->from(self::CONTENT_TABLE)
            ->where('pid = :pid')
            ->setParameter('pid', $pageUid, Connection::PARAM_INT);

        if (in_array('deleted', $columns, true)) {
            $queryBuilder->andWhere('deleted = 0');
        }
        if (in_array('hidden', $columns, true)) {
            $queryBuilder->andWhere('hidden = 0');
        }
        if (in_array('sys_language_uid', $columns, true)) {
            $queryBuilder->andWhere('sys_language_uid IN (0, -1)');
        }

        return array_values(array_filter(array_map('intval', $queryBuilder->executeQuery()->fetchFirstColumn())));
    }

    /**
     * @param list<array<string,mixed>> $references
     * @return list<array<string,mixed>>
     */
    private function matchImageReferences(string $src, array $references): array
    {
        $srcPath = rawurldecode((string)(parse_url($src, PHP_URL_PATH) ?: $src));
        $srcPathLower = mb_strtolower($srcPath);
        $srcBasename = mb_strtolower((string)basename($srcPath));
        $srcBasenameWithoutProcessedPrefix = preg_replace('/^csm_/', '', $srcBasename) ?? $srcBasename;

        $matches = [];
        foreach ($references as $reference) {
            $identifier = rawurldecode((string)($reference['identifier'] ?? ''));
            $filename = (string)($reference['filename'] ?? basename($identifier));
            $identifierLower = mb_strtolower($identifier);
            $filenameLower = mb_strtolower($filename);
            $filenameStem = mb_strtolower((string)pathinfo($filename, PATHINFO_FILENAME));

            if ($filenameLower !== '' && ($srcBasename === $filenameLower || $srcBasenameWithoutProcessedPrefix === $filenameLower)) {
                $matches[] = $reference;
                continue;
            }
            if ($filenameStem !== '' && str_contains($srcBasenameWithoutProcessedPrefix, $filenameStem)) {
                $matches[] = $reference;
                continue;
            }
            if ($identifierLower !== '' && str_contains($srcPathLower, $identifierLower)) {
                $matches[] = $reference;
            }
        }

        $unique = [];
        foreach ($matches as $match) {
            $unique[(int)($match['reference_uid'] ?? 0)] = $match;
        }

        return array_values($unique);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRenderedSnapshot(string $pageUrl): ?array
    {
        if ($pageUrl === '') {
            return null;
        }

        $row = $this->connectionPool->getConnectionForTable(self::RENDERED_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::RENDERED_SNAPSHOT_TABLE)
            ->where('url_hash = :urlHash')
            ->setParameter('urlHash', hash('sha256', $this->urlNormalizer->normalize($pageUrl)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{payload:array<string,mixed>} $action
     * @return array{schema_type:string,json_ld:array<string,mixed>|list<array<string,mixed>>}
     */
    private function buildStructuredDataSchema(array $recommendation, int $pageUid, array $action): array
    {
        $schemaType = trim((string)($action['payload']['structured_data_type'] ?? ''));
        if ($schemaType === '') {
            $schemaType = $this->inferStructuredDataType($recommendation);
        }
        $schemaType = trim(preg_split('/\s*\/\s*/', $schemaType)[0] ?? $schemaType);
        $pageUrl = $this->urlNormalizer->normalize((string)($recommendation['page_url'] ?? ''));
        $topic = $this->generatePageTopic($recommendation);
        $page = $this->fetchPageRecord($pageUid);
        $description = $this->truncateSentence((string)($page['description'] ?? '') ?: (string)($recommendation['recommendation'] ?? ''), 240);
        $organizationId = 'https://waldbyte.de/#organization';

        if (strcasecmp($schemaType, 'Service') === 0 || strcasecmp($schemaType, 'ProfessionalService') === 0) {
            return [
                'schema_type' => 'Service',
                'json_ld' => [
                    '@type' => 'Service',
                    '@id' => $pageUrl . '#service',
                    'name' => $topic !== '' ? $topic : 'WALDBYTE Leistung',
                    'description' => $description !== '' ? $description : 'Digitale Leistung von WALDBYTE für Unternehmen in Deutschland und der Region Karlsruhe.',
                    'serviceType' => $this->serviceTypeFromTopic($topic, $recommendation),
                    'provider' => ['@id' => $organizationId],
                    'areaServed' => ['Deutschland', 'Region Karlsruhe'],
                    'url' => $pageUrl,
                ],
            ];
        }

        if (strcasecmp($schemaType, 'ContactPage') === 0) {
            return [
                'schema_type' => 'ContactPage',
                'json_ld' => [
                    '@type' => 'ContactPage',
                    '@id' => $pageUrl . '#contact',
                    'name' => $topic !== '' ? $topic : 'Kontakt',
                    'url' => $pageUrl,
                    'about' => ['@id' => $organizationId],
                    'mainEntity' => ['@id' => $organizationId],
                ],
            ];
        }

        if (strcasecmp($schemaType, 'FAQPage') === 0) {
            $faqs = $this->extractFaqItems($recommendation);
            if ($faqs === []) {
                return [];
            }

            return [
                'schema_type' => 'FAQPage',
                'json_ld' => [
                    '@type' => 'FAQPage',
                    '@id' => $pageUrl . '#faq',
                    'mainEntity' => $faqs,
                ],
            ];
        }

        if (strcasecmp($schemaType, 'ItemList') === 0 || strcasecmp($schemaType, 'CollectionPage') === 0) {
            $items = $this->structuredListItems($recommendation);
            if ($items === []) {
                return [
                    'schema_type' => 'CollectionPage',
                    'json_ld' => [
                        '@type' => 'CollectionPage',
                        '@id' => $pageUrl . '#collection',
                        'name' => $topic !== '' ? $topic : 'WALDBYTE Übersicht',
                        'url' => $pageUrl,
                        'isPartOf' => ['@id' => 'https://waldbyte.de/#website'],
                    ],
                ];
            }

            return [
                'schema_type' => 'ItemList',
                'json_ld' => [
                    '@type' => 'ItemList',
                    '@id' => $pageUrl . '#itemlist',
                    'name' => $topic !== '' ? $topic : 'WALDBYTE Übersicht',
                    'itemListElement' => $items,
                ],
            ];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function serviceTypeFromTopic(string $topic, array $recommendation): string
    {
        $text = mb_strtolower($topic . ' ' . (string)($recommendation['recommendation'] ?? '') . ' ' . $this->pagePath($recommendation));
        if (str_contains($text, 'typo3')) {
            return 'TYPO3 Entwicklung und Support';
        }
        if (str_contains($text, 'prestashop')) {
            return 'PrestaShop Entwicklung und Support';
        }
        if (str_contains($text, 'hosting') || str_contains($text, 'wartung')) {
            return 'Website-Wartung, Hosting, Monitoring und Backups';
        }
        if (str_contains($text, 'seo') || str_contains($text, 'performance')) {
            return 'Technische SEO und Performance-Optimierung';
        }
        if (str_contains($text, 'commerce') || str_contains($text, 'shop')) {
            return 'E-Commerce Entwicklung';
        }

        return 'Webentwicklung und digitale Beratung';
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return list<array<string,mixed>>
     */
    private function extractFaqItems(array $recommendation): array
    {
        $snapshot = $this->fetchRenderedSnapshot((string)($recommendation['page_url'] ?? ''));
        $text = $this->cleanText((string)($snapshot['visible_text'] ?? ''));
        if ($text === '') {
            return [];
        }

        preg_match_all('/([^.!?]{8,140}\?)\s+(.{30,420}?)(?=\s+[^.!?]{8,140}\?|\z)/u', $text, $matches, PREG_SET_ORDER);
        $items = [];
        foreach ($matches as $match) {
            $question = $this->cleanText((string)($match[1] ?? ''));
            $answer = $this->truncateSentence((string)($match[2] ?? ''), 320);
            if ($question === '' || $answer === '') {
                continue;
            }
            $items[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
            if (count($items) >= 6) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return list<array<string,mixed>>
     */
    private function structuredListItems(array $recommendation): array
    {
        $snapshot = $this->fetchRenderedSnapshot((string)($recommendation['page_url'] ?? ''));
        $links = $this->decodeJson((string)($snapshot['links_json'] ?? '[]'));
        $items = [];
        $seen = [];
        foreach ($links as $link) {
            if (!is_array($link) || !($link['internal'] ?? false)) {
                continue;
            }
            $href = $this->urlNormalizer->normalize((string)($link['href'] ?? ''));
            $text = $this->cleanText((string)($link['text'] ?? ''));
            if ($href === '' || $text === '' || isset($seen[$href])) {
                continue;
            }
            if (!$this->isRelevantListUrl($href, $recommendation)) {
                continue;
            }
            $seen[$href] = true;
            $items[] = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
                'name' => $text,
                'url' => $href,
            ];
            if (count($items) >= 12) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function isRelevantListUrl(string $href, array $recommendation): bool
    {
        $path = $this->pagePath($recommendation);
        $targetPath = (string)(parse_url($href, PHP_URL_PATH) ?: '');
        if ($path === '/leistungen') {
            return str_starts_with($targetPath, '/leistungen/');
        }
        if ($path === '/technologien') {
            return str_starts_with($targetPath, '/technologien/');
        }
        if ($path === '/projekte') {
            return str_contains($targetPath, '/projekte') || str_contains($targetPath, '/leistungen/');
        }

        return str_starts_with($targetPath, '/');
    }

    private function fetchVisibleContentText(int $pageUid): string
    {
        $parts = [];
        $snapshot = $this->connectionPool->getConnectionForTable(self::PAGE_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('content_text')
            ->from(self::PAGE_SNAPSHOT_TABLE)
            ->where('page_uid = :pageUid')
            ->setParameter('pageUid', $pageUid, Connection::PARAM_INT)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if (is_string($snapshot) && trim($snapshot) !== '') {
            $parts[] = $snapshot;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::CONTENT_TABLE);
        $columns = $connection->getSchemaInformation()->listTableColumnNames(self::CONTENT_TABLE);
        if (!in_array('pid', $columns, true)) {
            return implode(' ', $parts);
        }

        $select = array_values(array_intersect(['header', 'subheader', 'bodytext'], $columns));
        if ($select === []) {
            return implode(' ', $parts);
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select(...$select)
            ->from(self::CONTENT_TABLE)
            ->where('pid = :pid')
            ->setParameter('pid', $pageUid, Connection::PARAM_INT);

        if (in_array('deleted', $columns, true)) {
            $queryBuilder->andWhere('deleted = 0');
        }
        if (in_array('hidden', $columns, true)) {
            $queryBuilder->andWhere('hidden = 0');
        }
        if (in_array('sys_language_uid', $columns, true)) {
            $queryBuilder->andWhere('sys_language_uid IN (0, -1)');
        }

        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            foreach ($select as $column) {
                $value = trim($this->cleanText((string)($row[$column] ?? '')));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return implode(' ', $parts);
    }

    private function contentNeedle(string $bodyText): string
    {
        $bodyText = $this->cleanText($bodyText);
        if (mb_strlen($bodyText) < 30) {
            return '';
        }

        foreach (preg_split('/[.!?]\s+/u', $bodyText, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) >= 45) {
                return mb_substr($sentence, 0, 140);
            }
        }

        return mb_substr($bodyText, 0, 140);
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
     * @param mixed $value
     */
    private function jsonContainsSchemaTypeForPage($value, string $schemaType, string $pageUrl): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $expected = $this->normalizeText($schemaType);
        foreach ($value as $key => $item) {
            if ($key === '@type') {
                $types = is_array($item) ? $item : [$item];
                foreach ($types as $type) {
                    if ($this->normalizeText((string)$type) === $expected) {
                        return $expected === 'service'
                            ? $this->schemaObjectReferencesPage($value, $pageUrl)
                            : true;
                    }
                }
            }

            if (is_array($item) && $this->jsonContainsSchemaTypeForPage($item, $schemaType, $pageUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $schema
     */
    private function schemaObjectReferencesPage(array $schema, string $pageUrl): bool
    {
        $normalizedPageUrl = $this->urlNormalizer->normalize($pageUrl);
        $pagePath = (string)(parse_url($normalizedPageUrl, PHP_URL_PATH) ?: '');
        foreach (['@id', 'url', 'mainEntityOfPage'] as $field) {
            $candidate = $schema[$field] ?? '';
            if (is_array($candidate)) {
                $candidate = (string)($candidate['@id'] ?? $candidate['url'] ?? '');
            }
            $candidate = $this->urlNormalizer->normalize((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $normalizedPageUrl || str_starts_with($candidate, rtrim($normalizedPageUrl, '/') . '#')) {
                return true;
            }
            if ($pagePath !== '' && $pagePath !== '/' && str_contains((string)(parse_url($candidate, PHP_URL_PATH) ?: ''), $pagePath)) {
                return true;
            }
        }

        return false;
    }

    private function metadataMatches(string $expected, string $actual): bool
    {
        $expected = $this->normalizeText($expected);
        $actual = $this->normalizeText($actual);
        if ($expected === '' || $actual === '') {
            return false;
        }

        return $expected === $actual
            || str_contains($actual, $expected)
            || str_contains($expected, $actual);
    }

    private function normalizeText(string $value): string
    {
        return mb_strtolower($this->cleanText($value));
    }

    /**
     * @param mixed $items
     * @return list<string>
     */
    private function stringList($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $strings = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $strings[] = mb_substr($value, 0, 120);
            }
            if (count($strings) >= 8) {
                break;
            }
        }

        return $strings;
    }

    private function sanitizeContentHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = strip_tags($html, '<p><ul><ol><li><strong><em><br><a>');
        $html = preg_replace_callback(
            '/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>/i',
            function (array $matches): string {
                $href = trim((string)($matches[2] ?? ''));
                if (
                    $href === ''
                    || str_starts_with($href, 'javascript:')
                    || str_starts_with($href, 'data:')
                ) {
                    return '<a>';
                }

                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            },
            $html
        ) ?? $html;
        $html = preg_replace('/<(p|ul|ol|li|strong|em|br)\b[^>]*>/i', '<$1>', $html) ?? $html;
        $html = preg_replace('/\\s+/u', ' ', $html) ?? $html;

        return trim($html);
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function countWords(string $value): int
    {
        $text = $this->cleanText($value);
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function shouldUseH1Header(array $recommendation): bool
    {
        return str_contains((string)($recommendation['recommendation_type'] ?? ''), 'missing_h1')
            || str_contains(mb_strtolower((string)($recommendation['issue'] ?? '')), 'no h1')
            || str_contains(mb_strtolower((string)($recommendation['issue'] ?? '')), 'keine h1');
    }

    private function normalizeCType(string $contentCType): string
    {
        $contentCType = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($contentCType)) ?? '';

        return $contentCType !== '' ? $contentCType : self::DEFAULT_CONTENT_CTYPE;
    }

    private function nextContentSorting(int $pageUid, int $colPos): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::CONTENT_TABLE);
        $columns = $connection->getSchemaInformation()->listTableColumnNames(self::CONTENT_TABLE);
        if (!in_array('sorting', $columns, true)) {
            return 256;
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->selectLiteral('MAX(sorting)')
            ->from(self::CONTENT_TABLE)
            ->where('pid = :pid')
            ->setParameter('pid', $pageUid, Connection::PARAM_INT);

        if (in_array('colPos', $columns, true)) {
            $queryBuilder
                ->andWhere('colPos = :colPos')
                ->setParameter('colPos', $colPos, Connection::PARAM_INT);
        }
        if (in_array('deleted', $columns, true)) {
            $queryBuilder->andWhere('deleted = 0');
        }

        return ((int)$queryBuilder->executeQuery()->fetchOne()) + 256;
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param mixed $value
     */
    private function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array{seo_title:string,description:string}
     */
    private function fetchPageMetadata(int $pageUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('pages');
        $select = array_values(array_intersect(['seo_title', 'description'], $columns));
        if ($select === []) {
            return [
                'seo_title' => '',
                'description' => '',
            ];
        }

        $row = $connection->createQueryBuilder()
            ->select(...$select)
            ->from('pages')
            ->where('uid = :uid')
            ->setParameter('uid', $pageUid, Connection::PARAM_INT)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return [
                'seo_title' => '',
                'description' => '',
            ];
        }

        return [
            'seo_title' => (string)($row['seo_title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchPageRecord(int $pageUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('pages');
        $select = array_values(array_intersect(['uid', 'title', 'nav_title', 'seo_title', 'description', 'slug', 'no_index', 'no_follow', 'canonical_link'], $columns));
        if ($select === []) {
            return [];
        }

        $row = $connection->createQueryBuilder()
            ->select(...$select)
            ->from('pages')
            ->where('uid = :uid')
            ->setParameter('uid', $pageUid, Connection::PARAM_INT)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    /**
     * @return array{no_index:int,no_follow:int,canonical_link:string}
     */
    private function fetchPageIndexing(int $pageUid): array
    {
        $page = $this->fetchPageRecord($pageUid);

        return [
            'no_index' => (int)($page['no_index'] ?? 0),
            'no_follow' => (int)($page['no_follow'] ?? 0),
            'canonical_link' => (string)($page['canonical_link'] ?? ''),
        ];
    }

    private function assertTableExists(string $table): void
    {
        $tables = $this->connectionPool->getConnectionForTable($table)
            ->getSchemaInformation()
            ->listTableNames();
        if (!in_array($table, $tables, true)) {
            throw new RuntimeException('Database table "' . $table . '" is missing. Run TYPO3 extension setup/analyze database first.', 1760000064);
        }
    }

    /**
     * @param array<string,mixed> $appliedChanges
     */
    private function markRecommendationApplied(int $recommendationUid, int $pageUid, array $appliedChanges, string $verificationStatus): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->update(
                self::RECOMMENDATION_TABLE,
                [
                    'page_uid' => $pageUid,
                    'status' => 'applied',
                    'applied_changes_json' => $this->json($appliedChanges),
                    'verification_status' => $verificationStatus,
                    'verification_json' => '',
                    'applied_at' => $now,
                    'verified_at' => 0,
                    'tstamp' => $now,
                ],
                ['uid' => $recommendationUid],
                [
                    'page_uid' => Connection::PARAM_INT,
                    'applied_at' => Connection::PARAM_INT,
                    'verified_at' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                ]
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchRecommendation(int $uid): array
    {
        $recommendation = $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::RECOMMENDATION_TABLE)
            ->where('uid = :uid')
            ->setParameter('uid', $uid, Connection::PARAM_INT)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($recommendation)) {
            throw new RuntimeException('Recommendation uid ' . $uid . ' was not found.', 1760000044);
        }

        return $recommendation;
    }

    private function resolvePageUidFromUrl(string $url): int
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return 0;
        }

        $path = trim(rawurldecode($path));
        if ($path === '') {
            return 0;
        }

        $path = '/' . trim($path, '/');
        $exactSlugs = array_values(array_unique([
            $path,
            rtrim($path, '/') ?: '/',
            rtrim($path, '/') . '/',
        ]));

        $connection = $this->connectionPool->getConnectionForTable('pages');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('pages');
        if (!in_array('slug', $columns, true)) {
            return 0;
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->in('slug', ':slugs'))
            ->setParameter('slugs', $exactSlugs, Connection::PARAM_STR_ARRAY)
            ->setMaxResults(1);
        $this->addPageVisibilityRestrictions($queryBuilder, $columns);

        $pageUid = (int)$queryBuilder->executeQuery()->fetchOne();
        if ($pageUid > 0) {
            return $pageUid;
        }

        $lastSegment = trim((string)basename($path), '/');
        if ($lastSegment === '') {
            return 0;
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->like('slug', ':slugSuffix'))
            ->setParameter('slugSuffix', '%/' . $lastSegment);
        $this->addPageVisibilityRestrictions($queryBuilder, $columns);

        $matches = $queryBuilder->executeQuery()->fetchFirstColumn();
        $matches = array_values(array_filter(array_map('intval', $matches)));

        return count($matches) === 1 ? $matches[0] : 0;
    }

    /**
     * @param list<string> $columns
     */
    private function addPageVisibilityRestrictions($queryBuilder, array $columns): void
    {
        if (in_array('deleted', $columns, true)) {
            $queryBuilder->andWhere('deleted = 0');
        }
        if (in_array('hidden', $columns, true)) {
            $queryBuilder->andWhere('hidden = 0');
        }
        if (in_array('sys_language_uid', $columns, true)) {
            $queryBuilder->andWhere('sys_language_uid = 0');
        }
    }
}
