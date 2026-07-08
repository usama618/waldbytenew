<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class SeoAssistantAlertService
{
    private const TABLE = 'tx_seoassistant_alert';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string,mixed> $context
     */
    public function record(string $source, string $title, string $message, array $context = [], string $severity = 'error'): void
    {
        $now = time();
        try {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(
                self::TABLE,
                [
                    'pid' => 0,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'source' => $this->trim($source, 64),
                    'severity' => in_array($severity, ['notice', 'warning', 'error', 'critical'], true) ? $severity : 'error',
                    'status' => 'open',
                    'title' => $this->trim($title, 255),
                    'message' => $this->trim($message, 4000),
                    'context_json' => $this->json($context),
                    'resolved_at' => 0,
                ],
                [
                    'pid' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                    'crdate' => Connection::PARAM_INT,
                    'resolved_at' => Connection::PARAM_INT,
                ]
            );
        } catch (Throwable) {
            // Alerting must not mask the original failing job or command.
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchOpen(int $limit = 20): array
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('status = :status')
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->setParameter('status', 'open')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function resolve(int $uid): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                [
                    'status' => 'resolved',
                    'resolved_at' => $now,
                    'tstamp' => $now,
                ],
                ['uid' => $uid],
                [
                    'resolved_at' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                ]
            );
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
