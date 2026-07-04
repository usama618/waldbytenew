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
            throw new RuntimeException('Recommendation has no TYPO3 page_uid. Apply the suggestion manually.', 1760000041);
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
                        'status' => 'applied',
                        'applied_at' => time(),
                        'tstamp' => time(),
                    ],
                    ['uid' => $recommendationUid],
                    [
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
}
