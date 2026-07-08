<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

final class RecommendationGuardrailService
{
    private const SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RECOMMENDATION_TABLE = 'tx_seoassistant_recommendation';

    private const UNSUPPORTED_CLAIMS = [
        'platz 1',
        'platz #1',
        'nummer 1',
        'rank 1',
        'ranking garantie',
        'ranking-garantie',
        'garantiert',
        '100% mehr',
        'sofort mehr',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly UrlNormalizer $urlNormalizer,
    ) {}

    /**
     * @param array<string,mixed> $recommendation
     * @return array{valid:bool,status:string,score:int,errors:list<string>,warnings:list<string>,checks:array<string,mixed>}
     */
    public function validate(array $recommendation): array
    {
        $errors = [];
        $warnings = [];
        $checks = [];
        $payload = $this->decodeJson((string)($recommendation['action_payload_json'] ?? '{}'));
        $actionType = (string)($recommendation['action_type'] ?? '');

        $title = trim((string)($recommendation['proposed_seo_title'] ?? $payload['seo_title'] ?? ''));
        $description = trim((string)($recommendation['proposed_description'] ?? $payload['description'] ?? ''));
        if ($title !== '' && mb_strlen($title) > 60) {
            $errors[] = 'SEO title is longer than 60 characters.';
        }
        if ($description !== '' && mb_strlen($description) > 155) {
            $errors[] = 'Meta description is longer than 155 characters.';
        }
        $checks['metadata_lengths'] = [
            'title_length' => mb_strlen($title),
            'description_length' => mb_strlen($description),
        ];

        if ($this->hasUnsupportedClaim($recommendation, $payload)) {
            $errors[] = 'Suggestion contains an unsupported ranking/performance claim.';
        }
        if ($this->hasKeywordStuffing($title . ' ' . $description . ' ' . (string)($recommendation['recommendation'] ?? ''))) {
            $errors[] = 'Suggestion appears to repeat terms too aggressively.';
        }
        if ($this->hasIrrelevantLocalInjection($recommendation, $payload)) {
            $warnings[] = 'Local term usage should be reviewed for relevance.';
        }

        if ($actionType === 'internal_link_suggestion') {
            $missingLinks = $this->missingInternalLinks($payload);
            if ($missingLinks !== []) {
                $errors[] = 'One or more suggested internal links do not exist in the current page snapshot.';
            }
            $checks['missing_internal_links'] = $missingLinks;
        }

        if ($actionType === 'structured_data_suggestion') {
            $schemaPreview = trim((string)($payload['structured_data_preview'] ?? ''));
            if ($schemaPreview !== '' && in_array($schemaPreview[0] ?? '', ['{', '['], true)) {
                json_decode($schemaPreview, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = 'Structured-data preview is not valid JSON.';
                }
            }
        }

        if ((string)($recommendation['dedupe_hash'] ?? '') !== '' && $this->duplicateExists((string)$recommendation['dedupe_hash'])) {
            $warnings[] = 'A recommendation with the same dedupe hash already exists.';
        }

        $score = max(0, 100 - (count($errors) * 35) - (count($warnings) * 10));
        $valid = $errors === [];

        return [
            'valid' => $valid,
            'status' => $valid ? ($warnings === [] ? 'passed' : 'warning') : 'failed',
            'score' => $score,
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array<string,mixed> $payload
     */
    private function hasUnsupportedClaim(array $recommendation, array $payload): bool
    {
        $text = mb_strtolower(implode(' ', [
            (string)($recommendation['issue'] ?? ''),
            (string)($recommendation['recommendation'] ?? ''),
            (string)($recommendation['proposed_seo_title'] ?? ''),
            (string)($recommendation['proposed_description'] ?? ''),
            (string)($payload['content_brief'] ?? ''),
            (string)($payload['content_body_html'] ?? ''),
        ]));

        foreach (self::UNSUPPORTED_CLAIMS as $claim) {
            if (str_contains($text, $claim)) {
                return true;
            }
        }

        return false;
    }

    private function hasKeywordStuffing(string $text): bool
    {
        $text = mb_strtolower(strip_tags($text));
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 5) {
                continue;
            }
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        foreach ($counts as $count) {
            if ($count >= 5) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array<string,mixed> $payload
     */
    private function hasIrrelevantLocalInjection(array $recommendation, array $payload): bool
    {
        $text = mb_strtolower(implode(' ', [
            (string)($recommendation['recommendation'] ?? ''),
            (string)($recommendation['proposed_seo_title'] ?? ''),
            (string)($recommendation['proposed_description'] ?? ''),
            (string)($payload['content_body_html'] ?? ''),
        ]));
        $localMentions = substr_count($text, 'karlsruhe');
        if ($localMentions <= 2) {
            return false;
        }

        $pageUrl = mb_strtolower((string)($recommendation['page_url'] ?? ''));
        $query = mb_strtolower((string)($recommendation['query_text'] ?? ''));

        return !str_contains($pageUrl, 'karlsruhe') && !str_contains($query, 'karlsruhe');
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function missingInternalLinks(array $payload): array
    {
        $existing = $this->currentUrlKeys();
        $missing = [];
        foreach ((array)($payload['suggested_links'] ?? []) as $link) {
            if (!is_array($link)) {
                continue;
            }
            foreach (['source_url', 'target_url'] as $field) {
                $url = (string)($link[$field] ?? '');
                if ($url !== '' && !isset($existing[$this->urlNormalizer->normalize($url)])) {
                    $missing[] = $field . ': ' . $url;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return array<string,bool>
     */
    private function currentUrlKeys(): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::SNAPSHOT_TABLE)
            ->createQueryBuilder()
            ->select('page_url')
            ->from(self::SNAPSHOT_TABLE)
            ->where('page_url <> :empty')
            ->setParameter('empty', '')
            ->executeQuery()
            ->fetchFirstColumn();

        $keys = [];
        foreach ($rows as $url) {
            $normalized = $this->urlNormalizer->normalize((string)$url);
            if ($normalized !== '') {
                $keys[$normalized] = true;
            }
        }

        return $keys;
    }

    private function duplicateExists(string $dedupeHash): bool
    {
        return (int)$this->connectionPool->getConnectionForTable(self::RECOMMENDATION_TABLE)
            ->createQueryBuilder()
            ->count('uid')
            ->from(self::RECOMMENDATION_TABLE)
            ->where('dedupe_hash = :dedupeHash')
            ->setParameter('dedupeHash', $dedupeHash)
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}
