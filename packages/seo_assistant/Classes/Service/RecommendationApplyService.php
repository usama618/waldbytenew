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
     * @return array{uid:int,pageUid:int,seoTitle:string,description:string,dryRun:bool}
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

        $seoTitle = trim((string)($recommendation['proposed_seo_title'] ?? ''));
        $description = trim((string)($recommendation['proposed_description'] ?? ''));
        if ($seoTitle === '' && $description === '') {
            throw new RuntimeException('Recommendation has no proposed SEO title or description.', 1760000043);
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

            $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
                ->update(
                    self::RECOMMENDATION_TABLE,
                    [
                        'page_uid' => $pageUid,
                        'status' => 'applied',
                        'applied_at' => time(),
                        'tstamp' => time(),
                    ],
                    ['uid' => $recommendationUid],
                    [
                        'page_uid' => Connection::PARAM_INT,
                        'applied_at' => Connection::PARAM_INT,
                        'tstamp' => Connection::PARAM_INT,
                    ]
                );
        }

        return [
            'uid' => $recommendationUid,
            'pageUid' => $pageUid,
            'seoTitle' => $seoTitle,
            'description' => $description,
            'dryRun' => $dryRun,
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
