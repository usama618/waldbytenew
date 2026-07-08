<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecommendationRollbackService
{
    private const TABLE = 'tx_seoassistant_recommendation_rollback';
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function record(
        int $recommendationUid,
        string $actionType,
        string $targetTable,
        int $targetUid,
        array $payload,
        string $message = '',
    ): int {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(
            self::TABLE,
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'recommendation_uid' => $recommendationUid,
                'action_type' => $this->trim($actionType, 64),
                'target_table' => $this->trim($targetTable, 128),
                'target_uid' => max(0, $targetUid),
                'status' => 'available',
                'rollback_payload_json' => $this->json($payload),
                'rolled_back_at' => 0,
                'rolled_back_by' => '',
                'message' => $this->trim($message, 2000),
            ],
            [
                'pid' => Connection::PARAM_INT,
                'tstamp' => Connection::PARAM_INT,
                'crdate' => Connection::PARAM_INT,
                'recommendation_uid' => Connection::PARAM_INT,
                'target_uid' => Connection::PARAM_INT,
                'rolled_back_at' => Connection::PARAM_INT,
            ]
        );

        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchRecent(int $limit = 20): array
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
     * @return array{rolledBack:int,skipped:int,failed:int,rows:list<array<string,mixed>>}
     */
    public function rollbackRecommendation(int $recommendationUid, string $source = 'backend'): array
    {
        $rows = $this->fetchAvailableForRecommendation($recommendationUid);
        if ($rows === []) {
            return [
                'rolledBack' => 0,
                'skipped' => 0,
                'failed' => 1,
                'rows' => [[
                    'recommendationUid' => $recommendationUid,
                    'status' => 'error',
                    'message' => 'No available rollback snapshot exists for this recommendation.',
                ]],
            ];
        }

        $resultRows = [];
        $rolledBack = 0;
        $failed = 0;

        foreach (array_reverse($rows) as $row) {
            try {
                $message = $this->rollbackRow($row);
                $this->markRolledBack((int)$row['uid'], $source, $message);
                $rolledBack++;
                $resultRows[] = [
                    'rollbackUid' => (int)$row['uid'],
                    'recommendationUid' => $recommendationUid,
                    'status' => 'rolled_back',
                    'action' => (string)($row['action_type'] ?? ''),
                    'message' => $message,
                ];
            } catch (RuntimeException $exception) {
                $failed++;
                $resultRows[] = [
                    'rollbackUid' => (int)$row['uid'],
                    'recommendationUid' => $recommendationUid,
                    'status' => 'error',
                    'action' => (string)($row['action_type'] ?? ''),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($rolledBack > 0 && $failed === 0) {
            $this->markRecommendationRolledBack($recommendationUid);
        }

        return [
            'rolledBack' => $rolledBack,
            'skipped' => 0,
            'failed' => $failed,
            'rows' => $resultRows,
        ];
    }

    /**
     * @param list<int> $recommendationUids
     * @return array{rolledBack:int,skipped:int,failed:int,rows:list<array<string,mixed>>}
     */
    public function rollbackRecommendations(array $recommendationUids, string $source = 'backend'): array
    {
        $rolledBack = 0;
        $skipped = 0;
        $failed = 0;
        $rows = [];

        foreach (array_values(array_unique(array_map('intval', $recommendationUids))) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $result = $this->rollbackRecommendation($uid, $source);
            $rolledBack += $result['rolledBack'];
            $skipped += $result['skipped'];
            $failed += $result['failed'];
            $rows = array_merge($rows, $result['rows']);
        }

        return [
            'rolledBack' => $rolledBack,
            'skipped' => $skipped,
            'failed' => $failed,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAvailableForRecommendation(int $recommendationUid): array
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('recommendation_uid = :recommendationUid')
            ->andWhere('status = :status')
            ->orderBy('uid', 'ASC')
            ->setParameter('recommendationUid', $recommendationUid, Connection::PARAM_INT)
            ->setParameter('status', 'available')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rollbackRow(array $row): string
    {
        $payload = $this->decodeJson((string)($row['rollback_payload_json'] ?? '{}'));
        $actionType = (string)($row['action_type'] ?? '');

        return match ($actionType) {
            'metadata_update' => $this->rollbackMetadata($payload),
            'content_gap_brief' => $this->rollbackContent($payload),
            'image_alt_suggestion' => $this->rollbackImageAlt($payload),
            'technical_indexing_issue' => $this->rollbackIndexing($payload),
            'structured_data_suggestion' => $this->rollbackStructuredData($payload),
            default => throw new RuntimeException('Rollback is not supported for action "' . $actionType . '".', 1760000071),
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function rollbackMetadata(array $payload): string
    {
        $pageUid = (int)($payload['page_uid'] ?? 0);
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        if ($pageUid <= 0 || $fields === []) {
            throw new RuntimeException('Rollback metadata payload is incomplete.', 1760000072);
        }

        $data = ['tstamp' => time()];
        $types = ['tstamp' => Connection::PARAM_INT];
        foreach (['seo_title', 'description'] as $field) {
            if (array_key_exists($field, $fields) && is_array($fields[$field])) {
                $data[$field] = (string)($fields[$field]['before'] ?? '');
            }
        }
        $this->connectionPool->getConnectionForTable('pages')->update('pages', $data, ['uid' => $pageUid], $types);

        return 'Restored page metadata for page #' . $pageUid . '.';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function rollbackContent(array $payload): string
    {
        $contentUid = (int)($payload['content_uid'] ?? 0);
        if ($contentUid <= 0) {
            throw new RuntimeException('Rollback content payload has no content uid.', 1760000073);
        }

        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $columns = $connection->getSchemaInformation()->listTableColumnNames('tt_content');
        $data = ['tstamp' => time()];
        $types = ['tstamp' => Connection::PARAM_INT];
        if (in_array('hidden', $columns, true)) {
            $data['hidden'] = 1;
            $types['hidden'] = Connection::PARAM_INT;
        }
        if (in_array('deleted', $columns, true)) {
            $data['deleted'] = 1;
            $types['deleted'] = Connection::PARAM_INT;
        }
        $connection->update('tt_content', $data, ['uid' => $contentUid], $types);

        return 'Hidden/deleted created content element #' . $contentUid . '.';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function rollbackImageAlt(array $payload): string
    {
        $references = array_values(array_filter((array)($payload['updated_references'] ?? []), 'is_array'));
        if ($references === []) {
            throw new RuntimeException('Rollback image-alt payload has no updated references.', 1760000074);
        }

        $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        foreach ($references as $reference) {
            $referenceUid = (int)($reference['reference_uid'] ?? 0);
            if ($referenceUid <= 0) {
                continue;
            }
            $connection->update(
                'sys_file_reference',
                [
                    'alternative' => (string)($reference['before'] ?? ''),
                    'tstamp' => time(),
                ],
                ['uid' => $referenceUid],
                ['tstamp' => Connection::PARAM_INT]
            );
        }

        return 'Restored alt text on ' . count($references) . ' file reference(s).';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function rollbackIndexing(array $payload): string
    {
        $pageUid = (int)($payload['page_uid'] ?? 0);
        $before = is_array($payload['before'] ?? null) ? $payload['before'] : [];
        if ($pageUid <= 0 || $before === []) {
            throw new RuntimeException('Rollback indexing payload is incomplete.', 1760000075);
        }

        $data = ['tstamp' => time()];
        $types = ['tstamp' => Connection::PARAM_INT];
        foreach (['no_index', 'no_follow'] as $field) {
            if (array_key_exists($field, $before)) {
                $data[$field] = (int)$before[$field];
                $types[$field] = Connection::PARAM_INT;
            }
        }
        if (array_key_exists('canonical_link', $before)) {
            $data['canonical_link'] = (string)$before['canonical_link'];
        }
        $this->connectionPool->getConnectionForTable('pages')->update('pages', $data, ['uid' => $pageUid], $types);

        return 'Restored indexing fields for page #' . $pageUid . '.';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function rollbackStructuredData(array $payload): string
    {
        $structuredDataUid = (int)($payload['structured_data_uid'] ?? 0);
        if ($structuredDataUid <= 0) {
            throw new RuntimeException('Rollback structured-data payload has no structured-data uid.', 1760000076);
        }

        $connection = $this->connectionPool->getConnectionForTable('tx_seoassistant_structured_data');
        $previous = is_array($payload['previous_row'] ?? null) ? $payload['previous_row'] : [];
        if ($previous !== []) {
            $data = [
                'tstamp' => time(),
                'json_ld' => (string)($previous['json_ld'] ?? ''),
                'enabled' => (int)($previous['enabled'] ?? 0),
                'schema_type' => (string)($previous['schema_type'] ?? ''),
            ];
            $connection->update(
                'tx_seoassistant_structured_data',
                $data,
                ['uid' => $structuredDataUid],
                ['tstamp' => Connection::PARAM_INT, 'enabled' => Connection::PARAM_INT]
            );
            return 'Restored previous structured-data row #' . $structuredDataUid . '.';
        }

        $connection->update(
            'tx_seoassistant_structured_data',
            ['enabled' => 0, 'tstamp' => time()],
            ['uid' => $structuredDataUid],
            ['enabled' => Connection::PARAM_INT, 'tstamp' => Connection::PARAM_INT]
        );

        return 'Disabled created structured-data row #' . $structuredDataUid . '.';
    }

    private function markRolledBack(int $rollbackUid, string $source, string $message): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                [
                    'status' => 'rolled_back',
                    'rolled_back_at' => $now,
                    'rolled_back_by' => $this->trim($source, 32),
                    'message' => $message,
                    'tstamp' => $now,
                ],
                ['uid' => $rollbackUid],
                [
                    'rolled_back_at' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                ]
            );
    }

    private function markRecommendationRolledBack(int $recommendationUid): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->update(
                self::RECOMMENDATION_TABLE,
                [
                    'status' => 'rolled_back',
                    'verification_status' => 'rolled_back',
                    'tstamp' => $now,
                ],
                ['uid' => $recommendationUid],
                ['tstamp' => Connection::PARAM_INT]
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
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
