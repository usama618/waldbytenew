<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecommendationVerificationService
{
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';
    private const RENDERED_SNAPSHOT_TABLE = 'tx_seoassistant_rendered_snapshot';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UrlNormalizer $urlNormalizer,
    ) {}

    /**
     * @return array{uid:int,pageUrl:string,status:string,checkedFields:list<string>,message:string}
     */
    public function verify(int $recommendationUid): array
    {
        $recommendation = $this->fetchRecommendation($recommendationUid);
        $pageUrl = (string)($recommendation['page_url'] ?? '');
        if ($pageUrl === '') {
            throw new RuntimeException('Recommendation uid ' . $recommendationUid . ' has no page URL.', 1760000051);
        }

        if ((string)($recommendation['status'] ?? '') !== 'applied') {
            $result = [
                'status' => 'not_applied',
                'message' => 'Recommendation has not been applied yet.',
                'checks' => [],
            ];
            $this->storeVerification($recommendationUid, $result);

            return [
                'uid' => $recommendationUid,
                'pageUrl' => $pageUrl,
                'status' => $result['status'],
                'checkedFields' => [],
                'message' => $result['message'],
            ];
        }

        $actionType = $this->extractActionType($recommendation);
        if ($actionType === 'image_alt_suggestion') {
            return $this->verifyImageAltRecommendation($recommendationUid, $recommendation, $pageUrl);
        }

        $metadataAction = $this->extractMetadataAction($recommendation);
        if ($actionType !== 'metadata_update' && $metadataAction['actionType'] !== 'metadata_update') {
            $result = [
                'status' => 'manual_review',
                'message' => 'Only metadata_update and image_alt_suggestion actions can be verified automatically.',
                'checks' => [],
            ];
            $this->storeVerification($recommendationUid, $result);

            return [
                'uid' => $recommendationUid,
                'pageUrl' => $pageUrl,
                'status' => $result['status'],
                'checkedFields' => [],
                'message' => $result['message'],
            ];
        }

        $renderedSnapshot = $this->fetchRenderedSnapshot($pageUrl);
        if ($renderedSnapshot === null) {
            $result = [
                'status' => 'needs_snapshot',
                'message' => 'No rendered snapshot exists for this URL. Run seo:rendered:snapshot for the page, then verify again.',
                'checks' => [],
            ];
            $this->storeVerification($recommendationUid, $result);

            return [
                'uid' => $recommendationUid,
                'pageUrl' => $pageUrl,
                'status' => $result['status'],
                'checkedFields' => [],
                'message' => $result['message'],
            ];
        }

        $checks = [];
        if ($metadataAction['seoTitle'] !== '') {
            $checks['seo_title'] = [
                'expected' => $metadataAction['seoTitle'],
                'rendered' => (string)($renderedSnapshot['html_title'] ?? ''),
                'matched' => $this->metadataMatches($metadataAction['seoTitle'], (string)($renderedSnapshot['html_title'] ?? '')),
            ];
        }
        if ($metadataAction['description'] !== '') {
            $checks['description'] = [
                'expected' => $metadataAction['description'],
                'rendered' => (string)($renderedSnapshot['meta_description'] ?? ''),
                'matched' => $this->metadataMatches($metadataAction['description'], (string)($renderedSnapshot['meta_description'] ?? '')),
            ];
        }

        $failed = array_filter($checks, static fn(array $check): bool => !$check['matched']);
        $status = $checks !== [] && $failed === [] ? 'verified' : 'needs_review';
        $message = $status === 'verified'
            ? 'Applied metadata is visible in the latest rendered snapshot.'
            : 'Applied metadata does not fully match the latest rendered snapshot.';

        $result = [
            'status' => $status,
            'message' => $message,
            'rendered_snapshot_uid' => (int)($renderedSnapshot['uid'] ?? 0),
            'rendered_snapshot_time' => (int)($renderedSnapshot['tstamp'] ?? 0),
            'checks' => $checks,
        ];
        $this->storeVerification($recommendationUid, $result);

        return [
            'uid' => $recommendationUid,
            'pageUrl' => $pageUrl,
            'status' => $status,
            'checkedFields' => array_keys($checks),
            'message' => $message,
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array{uid:int,pageUrl:string,status:string,checkedFields:list<string>,message:string}
     */
    private function verifyImageAltRecommendation(int $recommendationUid, array $recommendation, string $pageUrl): array
    {
        $renderedSnapshot = $this->fetchRenderedSnapshot($pageUrl);
        if ($renderedSnapshot === null) {
            $result = [
                'status' => 'needs_snapshot',
                'message' => 'No rendered snapshot exists for this URL. Run seo:rendered:snapshot for the page, then verify again.',
                'checks' => [],
            ];
            $this->storeVerification($recommendationUid, $result);

            return [
                'uid' => $recommendationUid,
                'pageUrl' => $pageUrl,
                'status' => $result['status'],
                'checkedFields' => [],
                'message' => $result['message'],
            ];
        }

        $appliedChanges = $this->decodeJson((string)($recommendation['applied_changes_json'] ?? '{}'));
        $updatedReferences = array_values(array_filter((array)($appliedChanges['updated_references'] ?? []), 'is_array'));
        $renderedImages = $this->decodeJson((string)($renderedSnapshot['images_json'] ?? '[]'));

        $checks = [];
        foreach ($updatedReferences as $reference) {
            $expectedAlt = (string)($reference['after'] ?? '');
            $renderedImage = $this->matchRenderedImage((string)($reference['src'] ?? ''), (string)($reference['filename'] ?? ''), $renderedImages);
            $renderedAlt = is_array($renderedImage) ? (string)($renderedImage['alt'] ?? '') : '';
            $checks['sys_file_reference_' . (int)($reference['reference_uid'] ?? 0)] = [
                'expected' => $expectedAlt,
                'rendered' => $renderedAlt,
                'src' => (string)($reference['src'] ?? ''),
                'matched' => $this->metadataMatches($expectedAlt, $renderedAlt),
            ];
        }

        $failed = array_filter($checks, static fn(array $check): bool => !$check['matched']);
        $status = $checks !== [] && $failed === [] ? 'verified' : 'needs_review';
        $message = $status === 'verified'
            ? 'Applied image alt text is visible in the latest rendered snapshot.'
            : 'Applied image alt text does not fully match the latest rendered snapshot.';

        $result = [
            'status' => $status,
            'message' => $message,
            'rendered_snapshot_uid' => (int)($renderedSnapshot['uid'] ?? 0),
            'rendered_snapshot_time' => (int)($renderedSnapshot['tstamp'] ?? 0),
            'checks' => $checks,
        ];
        $this->storeVerification($recommendationUid, $result);

        return [
            'uid' => $recommendationUid,
            'pageUrl' => $pageUrl,
            'status' => $status,
            'checkedFields' => array_keys($checks),
            'message' => $message,
        ];
    }

    /**
     * @return list<int>
     */
    public function fetchPendingRecommendationUids(int $limit = 100): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->createQueryBuilder();

        $rows = $queryBuilder
            ->select('uid')
            ->from(self::RECOMMENDATION_TABLE)
            ->where('status = :status')
            ->andWhere($queryBuilder->expr()->in('verification_status', ':verificationStatuses'))
            ->orderBy('applied_at', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setParameter('status', 'applied')
            ->setParameter('verificationStatuses', ['pending', 'needs_snapshot', 'needs_review', 'not_checked'], Connection::PARAM_STR_ARRAY)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map('intval', $rows));
    }

    public function getRecommendationPageUrl(int $recommendationUid): string
    {
        $recommendation = $this->fetchRecommendation($recommendationUid);
        return (string)($recommendation['page_url'] ?? '');
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
            throw new RuntimeException('Recommendation uid ' . $uid . ' was not found.', 1760000052);
        }

        return $recommendation;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRenderedSnapshot(string $pageUrl): ?array
    {
        $snapshot = $this->connectionPool->getConnectionForTable(self::RENDERED_SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::RENDERED_SNAPSHOT_TABLE)
            ->where('url_hash = :urlHash')
            ->setParameter('urlHash', hash('sha256', $this->urlNormalizer->normalize($pageUrl)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param array<string,mixed> $recommendation
     */
    private function extractActionType(array $recommendation): string
    {
        $payload = $this->decodeJson((string)($recommendation['action_payload_json'] ?? '{}'));
        $actionType = trim((string)($recommendation['action_type'] ?? ''));
        if ($actionType === '' && ((string)($payload['seo_title'] ?? $recommendation['proposed_seo_title'] ?? '') !== '' || (string)($payload['description'] ?? $recommendation['proposed_description'] ?? '') !== '')) {
            return 'metadata_update';
        }
        if ($actionType === '' && ($payload['image_alt_suggestions'] ?? []) !== []) {
            return 'image_alt_suggestion';
        }

        return $actionType !== '' ? $actionType : 'manual_review';
    }

    /**
     * @param array<string,mixed> $recommendation
     * @return array{actionType:string,seoTitle:string,description:string}
     */
    private function extractMetadataAction(array $recommendation): array
    {
        $payload = $this->decodeJson((string)($recommendation['action_payload_json'] ?? '{}'));

        $seoTitle = trim((string)($payload['seo_title'] ?? $recommendation['proposed_seo_title'] ?? ''));
        $description = trim((string)($payload['description'] ?? $recommendation['proposed_description'] ?? ''));
        $actionType = trim((string)($recommendation['action_type'] ?? ''));
        if ($actionType === '' && ($seoTitle !== '' || $description !== '')) {
            $actionType = 'metadata_update';
        }

        return [
            'actionType' => $actionType !== '' ? $actionType : 'manual_review',
            'seoTitle' => mb_substr($seoTitle, 0, 60),
            'description' => mb_substr($description, 0, 155),
        ];
    }

    /**
     * @param mixed $images
     * @return array<string,mixed>|null
     */
    private function matchRenderedImage(string $originalSrc, string $filename, $images): ?array
    {
        if (!is_array($images)) {
            return null;
        }

        $originalPath = mb_strtolower(rawurldecode((string)(parse_url($originalSrc, PHP_URL_PATH) ?: $originalSrc)));
        $originalBasename = mb_strtolower((string)basename($originalPath));
        $filename = mb_strtolower($filename);
        $filenameStem = mb_strtolower((string)pathinfo($filename, PATHINFO_FILENAME));

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $srcPath = mb_strtolower(rawurldecode((string)(parse_url((string)($image['src'] ?? ''), PHP_URL_PATH) ?: ($image['src'] ?? ''))));
            $srcBasename = mb_strtolower((string)basename($srcPath));
            if ($originalPath !== '' && $srcPath === $originalPath) {
                return $image;
            }
            if ($originalBasename !== '' && $srcBasename === $originalBasename) {
                return $image;
            }
            if ($filenameStem !== '' && str_contains(preg_replace('/^csm_/', '', $srcBasename) ?? $srcBasename, $filenameStem)) {
                return $image;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private function metadataMatches(string $expected, string $rendered): bool
    {
        $expected = $this->normalizeText($expected);
        $rendered = $this->normalizeText($rendered);
        if ($expected === '' || $rendered === '') {
            return false;
        }

        return $expected === $rendered
            || str_contains($rendered, $expected)
            || str_contains($expected, $rendered);
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    /**
     * @param array<string,mixed> $result
     */
    private function storeVerification(int $recommendationUid, array $result): void
    {
        $this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->update(
                self::RECOMMENDATION_TABLE,
                [
                    'verification_status' => (string)($result['status'] ?? 'needs_review'),
                    'verification_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'verified_at' => time(),
                    'tstamp' => time(),
                ],
                ['uid' => $recommendationUid],
                [
                    'verified_at' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                ]
            );
    }
}
