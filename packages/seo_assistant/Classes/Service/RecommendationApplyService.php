<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecommendationApplyService
{
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{uid:int,pageUid:int,actionType:string,applyCapability:string,seoTitle:string,description:string,changedFields:list<string>,dryRun:bool}
     */
    public function apply(int $recommendationUid, bool $dryRun = true, bool $force = false): array
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

        $metadataAction = $this->extractMetadataAction($recommendation);
        $actionType = $metadataAction['actionType'];
        $applyCapability = $metadataAction['applyCapability'];
        $seoTitle = $metadataAction['seoTitle'];
        $description = $metadataAction['description'];

        if ($actionType !== 'metadata_update') {
            throw new RuntimeException('Recommendation action "' . $actionType . '" is manual and cannot be applied automatically.', 1760000045);
        }
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
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array{actionType:string,applyCapability:string,seoTitle:string,description:string}
     */
    private function extractMetadataAction(array $recommendation): array
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

        return [
            'actionType' => $actionType !== '' ? $actionType : 'manual_review',
            'applyCapability' => $applyCapability !== '' ? $applyCapability : 'manual',
            'seoTitle' => mb_substr($seoTitle, 0, 60),
            'description' => mb_substr($description, 0, 155),
        ];
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
