<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class PageSnapshotService
{
    private const TABLE = 'tx_seoassistant_page_snapshot';

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{processed:int,stored:int,baseUrl:string}
     */
    public function snapshot(?string $baseUrl = null, bool $dryRun = false): array
    {
        $baseUrl = $this->configuration->getBaseUrl($baseUrl);
        $pages = $this->fetchPages();
        $stored = 0;

        foreach ($pages as $page) {
            $snapshot = $this->buildSnapshot($page, $baseUrl);
            if (!$dryRun) {
                $stored += $this->storeSnapshot($snapshot);
            }
        }

        return [
            'processed' => count($pages),
            'stored' => $stored,
            'baseUrl' => $baseUrl,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchPages(): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('pages');
        $select = array_values(array_intersect([
            'uid',
            'pid',
            'title',
            'nav_title',
            'subtitle',
            'seo_title',
            'description',
            'slug',
            'no_index',
            'no_follow',
            'canonical_link',
            'doktype',
            'deleted',
            'hidden',
            'sys_language_uid',
            'sorting',
        ], $columns));

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select(...$select)
            ->from('pages');

        if (in_array('deleted', $columns, true)) {
            $queryBuilder->andWhere('deleted = 0');
        }
        if (in_array('hidden', $columns, true)) {
            $queryBuilder->andWhere('hidden = 0');
        }
        if (in_array('sys_language_uid', $columns, true)) {
            $queryBuilder->andWhere('sys_language_uid = 0');
        }
        if (in_array('doktype', $columns, true)) {
            $queryBuilder->andWhere('doktype IN (1, 4)');
        }
        if (in_array('sorting', $columns, true)) {
            $queryBuilder->orderBy('sorting', 'ASC');
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    private function buildSnapshot(array $page, string $baseUrl): array
    {
        $pageUid = (int)($page['uid'] ?? 0);
        $content = $this->fetchContentText($pageUid);
        $slug = (string)($page['slug'] ?? '/');
        $pageUrl = $this->buildPageUrl($baseUrl, $slug);
        $contentText = $this->cleanText(implode("\n", $content['texts']));
        $title = (string)($page['title'] ?? '');
        $seoTitle = (string)($page['seo_title'] ?? '');
        $description = (string)($page['description'] ?? '');
        $h1 = $content['h1'] !== '' ? $content['h1'] : ($seoTitle !== '' ? $seoTitle : $title);
        $robots = ((int)($page['no_index'] ?? 0) === 1 ? 'noindex' : 'index')
            . ', '
            . ((int)($page['no_follow'] ?? 0) === 1 ? 'nofollow' : 'follow');

        return [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'page_uid' => $pageUid,
            'page_url' => $pageUrl,
            'title' => $title,
            'seo_title' => $seoTitle,
            'description' => $description,
            'slug' => $slug,
            'h1' => $h1,
            'content_text' => $contentText,
            'word_count' => $this->countWords($contentText),
            'robots' => $robots,
            'canonical_url' => (string)($page['canonical_link'] ?? ''),
            'raw_json' => json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @return array{h1:string,texts:list<string>}
     */
    private function fetchContentText(int $pageUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('tt_content');
        $textColumns = $this->detectTextColumns($columns);
        $select = array_values(array_unique(array_merge(['uid'], $textColumns)));
        $select = array_values(array_intersect($select, $columns));

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select(...$select)
            ->from('tt_content')
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
        if (in_array('sorting', $columns, true)) {
            $queryBuilder->orderBy('sorting', 'ASC');
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        $texts = [];
        $contentUids = [];
        $h1 = '';

        foreach ($rows as $row) {
            $contentUids[] = (int)($row['uid'] ?? 0);
            foreach ($textColumns as $column) {
                $text = $this->cleanText((string)($row[$column] ?? ''));
                if ($text === '') {
                    continue;
                }
                if ($h1 === '' && $column === 'header') {
                    $h1 = $text;
                }
                $texts[] = $text;
            }
        }

        foreach ($this->fetchInlineText($contentUids) as $text) {
            $texts[] = $text;
        }

        return [
            'h1' => $h1,
            'texts' => array_values(array_unique(array_filter($texts))),
        ];
    }

    /**
     * @param list<int> $contentUids
     * @return list<string>
     */
    private function fetchInlineText(array $contentUids): array
    {
        $contentUids = array_values(array_filter(array_unique($contentUids)));
        if ($contentUids === []) {
            return [];
        }

        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $tableNames = $connection->getSchemaInformation()->listTableNames();
        $texts = [];

        foreach ($tableNames as $tableName) {
            if (!str_starts_with($tableName, 'tx_sitepackage_')) {
                continue;
            }

            $columns = $connection->getSchemaInformation()->listTableColumnNames($tableName);
            if (!in_array('uid_foreign', $columns, true)) {
                continue;
            }

            $textColumns = $this->detectTextColumns($columns);
            if ($textColumns === []) {
                continue;
            }

            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder
                ->select(...array_values(array_unique(array_merge(['uid_foreign'], $textColumns))))
                ->from($tableName)
                ->where($queryBuilder->expr()->in('uid_foreign', ':contentUids'))
                ->setParameter('contentUids', $contentUids, Connection::PARAM_INT_ARRAY);

            if (in_array('tablename', $columns, true)) {
                $queryBuilder
                    ->andWhere('tablename = :tablename')
                    ->setParameter('tablename', 'tt_content');
            }
            if (in_array('deleted', $columns, true)) {
                $queryBuilder->andWhere('deleted = 0');
            }
            if (in_array('hidden', $columns, true)) {
                $queryBuilder->andWhere('hidden = 0');
            }
            if (in_array('sorting_foreign', $columns, true)) {
                $queryBuilder->orderBy('sorting_foreign', 'ASC');
            }

            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                foreach ($textColumns as $column) {
                    $text = $this->cleanText((string)($row[$column] ?? ''));
                    if ($text !== '') {
                        $texts[] = $text;
                    }
                }
            }
        }

        return $texts;
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function detectTextColumns(array $columns): array
    {
        $excluded = [
            'uid',
            'pid',
            'tstamp',
            'crdate',
            'cruser_id',
            'deleted',
            'hidden',
            'sorting',
            'sorting_foreign',
            'uid_foreign',
            'sys_language_uid',
            'l10n_parent',
            'l10n_source',
            'l10n_diffsource',
            't3ver_oid',
            't3ver_wsid',
            't3ver_state',
            't3ver_stage',
            'tablename',
            'fieldname',
            'image',
            'assets',
            'media',
        ];

        $textColumns = [];
        foreach ($columns as $column) {
            if (in_array($column, $excluded, true)) {
                continue;
            }
            if (preg_match('/(^header$|title|name|label|text|body|quote|eyebrow|author|role|value|badge)/', $column) === 1) {
                $textColumns[] = $column;
            }
        }

        return array_values(array_unique($textColumns));
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function storeSnapshot(array $snapshot): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existingUid = (int)$connection->createQueryBuilder()
            ->select('uid')
            ->from(self::TABLE)
            ->where('page_uid = :pageUid')
            ->setParameter('pageUid', (int)$snapshot['page_uid'], Connection::PARAM_INT)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $types = [
            'pid' => Connection::PARAM_INT,
            'tstamp' => Connection::PARAM_INT,
            'crdate' => Connection::PARAM_INT,
            'page_uid' => Connection::PARAM_INT,
            'word_count' => Connection::PARAM_INT,
        ];

        if ($existingUid > 0) {
            unset($snapshot['crdate']);
            unset($types['crdate']);
            $connection->update(self::TABLE, $snapshot, ['uid' => $existingUid], $types);
            return 1;
        }

        $connection->insert(self::TABLE, $snapshot, $types);
        return 1;
    }

    private function buildPageUrl(string $baseUrl, string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '' || $slug === '/') {
            return $baseUrl;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($slug, '/');
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function countWords(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
