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
    private const DEFAULT_CONTENT_CTYPE = 'seo_text';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{uid:int,pageUid:int,actionType:string,applyCapability:string,seoTitle:string,description:string,changedFields:list<string>,dryRun:bool,contentUid:int,contentHidden:bool,contentHeader:string}
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

        throw new RuntimeException('Recommendation action "' . $actionType . '" is manual and cannot be applied automatically.', 1760000045);
    }

    /**
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>} $action
     * @return array{uid:int,pageUid:int,actionType:string,applyCapability:string,seoTitle:string,description:string,changedFields:list<string>,dryRun:bool,contentUid:int,contentHidden:bool,contentHeader:string}
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
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array{actionType:string,applyCapability:string,seoTitle:string,description:string,payload:array<string,mixed>} $action
     * @return array{uid:int,pageUid:int,actionType:string,applyCapability:string,seoTitle:string,description:string,changedFields:list<string>,dryRun:bool,contentUid:int,contentHidden:bool,contentHeader:string}
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

        $html = strip_tags($html, '<p><ul><ol><li><strong><em><br>');
        $html = preg_replace('/<([a-z0-9]+)\\b[^>]*>/i', '<$1>', $html) ?? $html;
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
