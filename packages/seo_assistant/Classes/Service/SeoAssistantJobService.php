<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class SeoAssistantJobService
{
    private const TABLE = 'tx_seoassistant_job';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function enqueue(string $jobType, array $payload, string $queuedBy = 'backend', int $priority = 50): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(
            self::TABLE,
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'job_type' => $this->trim($jobType, 64),
                'status' => 'queued',
                'priority' => max(0, min(100, $priority)),
                'attempts' => 0,
                'queued_by' => $this->trim($queuedBy, 32),
                'started_at' => 0,
                'finished_at' => 0,
                'payload_json' => $this->json($payload),
                'result_json' => '{}',
                'error_message' => '',
            ],
            [
                'pid' => Connection::PARAM_INT,
                'tstamp' => Connection::PARAM_INT,
                'crdate' => Connection::PARAM_INT,
                'priority' => Connection::PARAM_INT,
                'attempts' => Connection::PARAM_INT,
                'started_at' => Connection::PARAM_INT,
                'finished_at' => Connection::PARAM_INT,
            ]
        );

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function claimNext(): ?array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('status = :status')
            ->orderBy('priority', 'DESC')
            ->addOrderBy('crdate', 'ASC')
            ->setMaxResults(1)
            ->setParameter('status', 'queued')
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        $now = time();
        $connection->update(
            self::TABLE,
            [
                'status' => 'running',
                'attempts' => (int)($row['attempts'] ?? 0) + 1,
                'started_at' => $now,
                'tstamp' => $now,
            ],
            ['uid' => (int)$row['uid']],
            [
                'attempts' => Connection::PARAM_INT,
                'started_at' => Connection::PARAM_INT,
                'tstamp' => Connection::PARAM_INT,
            ]
        );

        $row['status'] = 'running';
        $row['attempts'] = (int)($row['attempts'] ?? 0) + 1;
        $row['started_at'] = $now;

        return $row;
    }

    /**
     * @param array<string,mixed> $result
     */
    public function complete(int $uid, array $result): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                [
                    'status' => 'completed',
                    'finished_at' => $now,
                    'result_json' => $this->json($result),
                    'error_message' => '',
                    'tstamp' => $now,
                ],
                ['uid' => $uid],
                [
                    'finished_at' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                ]
            );
    }

    public function fail(int $uid, string $message): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                [
                    'status' => 'failed',
                    'finished_at' => $now,
                    'error_message' => $this->trim($message, 4000),
                    'tstamp' => $now,
                ],
                ['uid' => $uid],
                [
                    'finished_at' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                ]
            );
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
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function payload(array $job): array
    {
        $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);

        return is_array($payload) ? $payload : [];
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
