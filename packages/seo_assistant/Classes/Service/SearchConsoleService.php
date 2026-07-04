<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use DateTimeImmutable;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;

final class SearchConsoleService
{
    private const TABLE = 'tx_seoassistant_gsc_row';

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly GoogleTokenService $googleTokenService,
        private readonly RequestFactory $requestFactory,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string> $dimensions
     * @return array{fetched:int,stored:int,siteUrl:string,startDate:string,endDate:string}
     */
    public function sync(
        string $startDate,
        string $endDate,
        ?string $siteUrl = null,
        array $dimensions = ['page', 'query'],
        int $rowLimit = 25000,
        string $searchType = 'web',
        bool $dryRun = false,
    ): array {
        $siteUrl = $this->configuration->getGscSiteUrl($siteUrl);
        $rowLimit = max(1, min(25000, $rowLimit));
        $dimensions = array_values(array_filter(array_map('trim', $dimensions)));
        if ($dimensions === []) {
            $dimensions = ['page', 'query'];
        }

        $body = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => $dimensions,
            'searchType' => $searchType,
            'rowLimit' => $rowLimit,
        ];

        $response = $this->requestFactory->request(
            'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query',
            'POST',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->googleTokenService->getAccessToken(),
                    'Accept' => 'application/json',
                ],
                'json' => $body,
                'timeout' => 60,
            ]
        );

        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Search Console response was not valid JSON.', 1760000011);
        }

        $rows = $payload['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $stored = 0;
        if (!$dryRun) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $stored += $this->storeRow($siteUrl, $startDate, $endDate, $dimensions, $searchType, $row);
                }
            }
        }

        return [
            'fetched' => count($rows),
            'stored' => $stored,
            'siteUrl' => $siteUrl,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
    }

    /**
     * @param list<string> $dimensions
     * @param array<string,mixed> $row
     */
    private function storeRow(
        string $siteUrl,
        string $startDate,
        string $endDate,
        array $dimensions,
        string $searchType,
        array $row,
    ): int {
        $keys = is_array($row['keys'] ?? null) ? array_values($row['keys']) : [];
        $dimensionMap = [];
        foreach ($dimensions as $index => $dimension) {
            $dimensionMap[$dimension] = (string)($keys[$index] ?? '');
        }

        $data = [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'site_url' => $siteUrl,
            'page_url' => $dimensionMap['page'] ?? '',
            'query_text' => $dimensionMap['query'] ?? '',
            'country' => $dimensionMap['country'] ?? '',
            'device' => $dimensionMap['device'] ?? '',
            'search_type' => $searchType,
            'date_from' => $this->dateToTimestamp($startDate),
            'date_to' => $this->dateToTimestamp($endDate),
            'clicks' => (float)($row['clicks'] ?? 0),
            'impressions' => (float)($row['impressions'] ?? 0),
            'ctr' => (float)($row['ctr'] ?? 0),
            'position' => (float)($row['position'] ?? 0),
            'raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $data['row_hash'] = hash('sha256', implode('|', [
            $siteUrl,
            $startDate,
            $endDate,
            $searchType,
            implode(',', $dimensions),
            implode('|', array_map('strval', $keys)),
        ]));

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existingUid = (int)$connection->createQueryBuilder()
            ->select('uid')
            ->from(self::TABLE)
            ->where('row_hash = :row_hash')
            ->setParameter('row_hash', $data['row_hash'])
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $types = [
            'pid' => Connection::PARAM_INT,
            'tstamp' => Connection::PARAM_INT,
            'crdate' => Connection::PARAM_INT,
            'date_from' => Connection::PARAM_INT,
            'date_to' => Connection::PARAM_INT,
        ];

        if ($existingUid > 0) {
            unset($data['crdate']);
            unset($types['crdate']);
            $connection->update(self::TABLE, $data, ['uid' => $existingUid], $types);
            return 1;
        }

        $connection->insert(self::TABLE, $data, $types);
        return 1;
    }

    private function dateToTimestamp(string $date): int
    {
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateTime instanceof DateTimeImmutable) {
            throw new RuntimeException('Invalid date "' . $date . '". Use YYYY-MM-DD.', 1760000012);
        }

        return $dateTime->getTimestamp();
    }
}
