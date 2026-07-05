<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiRecommendationService
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly RequestFactory $requestFactory,
    ) {}

    public function isConfigured(): bool
    {
        return $this->configuration->isAiConfigured();
    }

    public function getModel(): string
    {
        return $this->configuration->getOpenAiModel();
    }

    /**
     * @param array<string,mixed> $context
     * @return array{recommendation_type:string,action_type:string,action_payload:array<string,mixed>,query_text:string,issue:string,recommendation:string,proposed_seo_title:string,proposed_description:string,priority:int}|null
     */
    public function createRecommendation(array $context): ?array
    {
        $recommendations = $this->createRecommendations($context, 1);
        return $recommendations[0] ?? null;
    }

    /**
     * @param array<string,mixed> $context
     * @return list<array{recommendation_type:string,action_type:string,action_payload:array<string,mixed>,query_text:string,issue:string,recommendation:string,proposed_seo_title:string,proposed_description:string,priority:int}>
     */
    public function createRecommendations(array $context, int $maxRecommendations = 3): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $response = $this->requestFactory->request(
                $this->configuration->getOpenAiResponsesUrl(),
                'POST',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->configuration->getOpenAiApiKey(),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->configuration->getOpenAiModel(),
                        'instructions' => implode(' ', [
                            'Du bist ein AI SEO Strategist fuer eine deutsche TYPO3 Webagentur.',
                            'Analysiere Google Search Console Daten, CMS-Inhalte und gerendertes HTML.',
                            'Entscheide selbst, welche SEO-Empfehlungen wichtig sind.',
                            'Erstelle nur konkrete, faktenbasierte Empfehlungen mit klarer Prioritaet.',
                            'Wenn keine sinnvolle Empfehlung vorhanden ist, gib eine leere recommendations-Liste zurueck.',
                            'Keine erfundenen Leistungsversprechen, keine Rankings garantieren.',
                            'Meta Titles maximal 60 Zeichen, Meta Descriptions maximal 155 Zeichen.',
                            'Nutze action_type metadata_update nur, wenn pages.seo_title oder pages.description sicher aktualisiert werden koennen.',
                            'Nutze fuer Inhalte, Links, Bilder, Schema und technische Themen eine manuelle action_type mit konkretem Payload.',
                            'Schreibe auf Deutsch, passend fuer WALDBYTE und die Region Karlsruhe, wenn lokal relevant.',
                        ]),
                        'input' => [
                            [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'input_text',
                                        'text' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                    ],
                                ],
                            ],
                        ],
                        'text' => [
                            'format' => [
                                'type' => 'json_schema',
                                'name' => 'seo_recommendations',
                                'strict' => true,
                                'schema' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['recommendations'],
                                    'properties' => [
                                        'recommendations' => [
                                            'type' => 'array',
                                            'maxItems' => max(1, min(5, $maxRecommendations)),
                                            'items' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'required' => [
                                                    'recommendation_type',
                                                    'action_type',
                                                    'action_payload',
                                                    'query_text',
                                                    'issue',
                                                    'recommendation',
                                                    'proposed_seo_title',
                                                    'proposed_description',
                                                    'priority',
                                                ],
                                                'properties' => [
                                                    'recommendation_type' => [
                                                        'type' => 'string',
                                                        'description' => 'Short machine key like meta_title, meta_description, content_gap, internal_links, image_alt, structured_data, technical_indexing.',
                                                    ],
                                                    'action_type' => [
                                                        'type' => 'string',
                                                        'enum' => [
                                                            'metadata_update',
                                                            'content_gap_brief',
                                                            'internal_link_suggestion',
                                                            'image_alt_suggestion',
                                                            'structured_data_suggestion',
                                                            'technical_indexing_issue',
                                                            'manual_review',
                                                        ],
                                                        'description' => 'Machine-applicable action category. Only metadata_update may be applied automatically.',
                                                    ],
                                                    'action_payload' => [
                                                        'type' => 'object',
                                                        'additionalProperties' => false,
                                                        'required' => [
                                                            'target_table',
                                                            'target_uid',
                                                            'seo_title',
                                                            'description',
                                                            'content_brief',
                                                            'suggested_headings',
                                                            'suggested_links',
                                                            'image_alt_suggestions',
                                                            'structured_data_type',
                                                            'structured_data_preview',
                                                            'technical_steps',
                                                        ],
                                                        'properties' => [
                                                            'target_table' => [
                                                                'type' => 'string',
                                                                'description' => 'TYPO3 table to change when applicable. Use pages for metadata updates, otherwise empty string.',
                                                            ],
                                                            'target_uid' => [
                                                                'type' => 'integer',
                                                                'minimum' => 0,
                                                            ],
                                                            'seo_title' => [
                                                                'type' => 'string',
                                                                'description' => 'Only for metadata_update. Empty string otherwise.',
                                                            ],
                                                            'description' => [
                                                                'type' => 'string',
                                                                'description' => 'Only for metadata_update. Empty string otherwise.',
                                                            ],
                                                            'content_brief' => [
                                                                'type' => 'string',
                                                                'description' => 'Concrete content gap or editor brief. Empty string if not relevant.',
                                                            ],
                                                            'suggested_headings' => [
                                                                'type' => 'array',
                                                                'items' => ['type' => 'string'],
                                                                'maxItems' => 8,
                                                            ],
                                                            'suggested_links' => [
                                                                'type' => 'array',
                                                                'items' => [
                                                                    'type' => 'object',
                                                                    'additionalProperties' => false,
                                                                    'required' => ['source_url', 'target_url', 'anchor_text', 'reason'],
                                                                    'properties' => [
                                                                        'source_url' => ['type' => 'string'],
                                                                        'target_url' => ['type' => 'string'],
                                                                        'anchor_text' => ['type' => 'string'],
                                                                        'reason' => ['type' => 'string'],
                                                                    ],
                                                                ],
                                                                'maxItems' => 8,
                                                            ],
                                                            'image_alt_suggestions' => [
                                                                'type' => 'array',
                                                                'items' => [
                                                                    'type' => 'object',
                                                                    'additionalProperties' => false,
                                                                    'required' => ['src', 'alt_text', 'reason'],
                                                                    'properties' => [
                                                                        'src' => ['type' => 'string'],
                                                                        'alt_text' => ['type' => 'string'],
                                                                        'reason' => ['type' => 'string'],
                                                                    ],
                                                                ],
                                                                'maxItems' => 12,
                                                            ],
                                                            'structured_data_type' => [
                                                                'type' => 'string',
                                                                'description' => 'Schema.org type suggestion like Service, FAQPage, BlogPosting, BreadcrumbList, or empty string.',
                                                            ],
                                                            'structured_data_preview' => [
                                                                'type' => 'string',
                                                                'description' => 'Compact JSON-LD preview or implementation note. Empty string if not relevant.',
                                                            ],
                                                            'technical_steps' => [
                                                                'type' => 'array',
                                                                'items' => ['type' => 'string'],
                                                                'maxItems' => 8,
                                                            ],
                                                        ],
                                                    ],
                                                    'query_text' => [
                                                        'type' => 'string',
                                                        'description' => 'Main Search Console query this recommendation targets. Empty string for technical recommendations.',
                                                    ],
                                                    'issue' => [
                                                        'type' => 'string',
                                                    ],
                                                    'recommendation' => [
                                                        'type' => 'string',
                                                    ],
                                                    'proposed_seo_title' => [
                                                        'type' => 'string',
                                                        'description' => 'Only fill when a page SEO title should change. Otherwise empty string.',
                                                    ],
                                                    'proposed_description' => [
                                                        'type' => 'string',
                                                        'description' => 'Only fill when the page meta description should change. Otherwise empty string.',
                                                    ],
                                                    'priority' => [
                                                        'type' => 'integer',
                                                        'minimum' => 1,
                                                        'maximum' => 100,
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'verbosity' => 'low',
                        ],
                        'max_output_tokens' => 2600,
                    ],
                    'timeout' => 45,
                ]
            );

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                return [];
            }

            $outputText = $this->extractOutputText($payload);
            if ($outputText === '') {
                return [];
            }

            $payload = json_decode($outputText, true);
            if (!is_array($payload)) {
                return [];
            }

            return $this->normalizeRecommendations($payload['recommendations'] ?? [], $maxRecommendations);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param mixed $items
     * @return list<array{recommendation_type:string,action_type:string,action_payload:array<string,mixed>,query_text:string,issue:string,recommendation:string,proposed_seo_title:string,proposed_description:string,priority:int}>
     */
    private function normalizeRecommendations($items, int $maxRecommendations): array
    {
        if (!is_array($items)) {
            return [];
        }

        $recommendations = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $issue = trim((string)($item['issue'] ?? ''));
            $recommendation = trim((string)($item['recommendation'] ?? ''));
            if ($issue === '' || $recommendation === '') {
                continue;
            }

            $proposedTitle = mb_substr(trim((string)($item['proposed_seo_title'] ?? '')), 0, 60);
            $proposedDescription = mb_substr(trim((string)($item['proposed_description'] ?? '')), 0, 155);
            $actionPayload = $this->normalizeActionPayload($item['action_payload'] ?? [], $proposedTitle, $proposedDescription);
            $actionType = $this->normalizeActionType((string)($item['action_type'] ?? ''), $actionPayload, $proposedTitle, $proposedDescription);

            $recommendations[] = [
                'recommendation_type' => trim((string)($item['recommendation_type'] ?? 'ai_recommendation')) ?: 'ai_recommendation',
                'action_type' => $actionType,
                'action_payload' => $actionPayload,
                'query_text' => trim((string)($item['query_text'] ?? '')),
                'issue' => $issue,
                'recommendation' => $recommendation,
                'proposed_seo_title' => $proposedTitle,
                'proposed_description' => $proposedDescription,
                'priority' => max(1, min(100, (int)($item['priority'] ?? 50))),
            ];

            if (count($recommendations) >= max(1, $maxRecommendations)) {
                break;
            }
        }

        return $recommendations;
    }

    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    private function normalizeActionPayload($payload, string $proposedTitle, string $proposedDescription): array
    {
        if (!is_array($payload)) {
            $payload = [];
        }

        $normalized = [
            'target_table' => trim((string)($payload['target_table'] ?? '')),
            'target_uid' => max(0, (int)($payload['target_uid'] ?? 0)),
            'seo_title' => mb_substr(trim((string)($payload['seo_title'] ?? $proposedTitle)), 0, 60),
            'description' => mb_substr(trim((string)($payload['description'] ?? $proposedDescription)), 0, 155),
            'content_brief' => mb_substr(trim((string)($payload['content_brief'] ?? '')), 0, 1800),
            'suggested_headings' => $this->normalizeStringList($payload['suggested_headings'] ?? [], 8, 120),
            'suggested_links' => $this->normalizeLinkSuggestions($payload['suggested_links'] ?? []),
            'image_alt_suggestions' => $this->normalizeImageAltSuggestions($payload['image_alt_suggestions'] ?? []),
            'structured_data_type' => mb_substr(trim((string)($payload['structured_data_type'] ?? '')), 0, 80),
            'structured_data_preview' => mb_substr(trim((string)($payload['structured_data_preview'] ?? '')), 0, 2200),
            'technical_steps' => $this->normalizeStringList($payload['technical_steps'] ?? [], 8, 220),
        ];

        if ($normalized['seo_title'] === '' && $proposedTitle !== '') {
            $normalized['seo_title'] = $proposedTitle;
        }
        if ($normalized['description'] === '' && $proposedDescription !== '') {
            $normalized['description'] = $proposedDescription;
        }

        return $normalized;
    }

    /**
     * @param mixed $items
     * @return list<string>
     */
    private function normalizeStringList($items, int $limit, int $itemLength): array
    {
        if (!is_array($items)) {
            return [];
        }

        $values = [];
        foreach ($items as $item) {
            $value = mb_substr(trim((string)$item), 0, $itemLength);
            if ($value !== '') {
                $values[] = $value;
            }
            if (count($values) >= $limit) {
                break;
            }
        }

        return $values;
    }

    /**
     * @param mixed $items
     * @return list<array{source_url:string,target_url:string,anchor_text:string,reason:string}>
     */
    private function normalizeLinkSuggestions($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $values = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sourceUrl = mb_substr(trim((string)($item['source_url'] ?? '')), 0, 2048);
            $targetUrl = mb_substr(trim((string)($item['target_url'] ?? '')), 0, 2048);
            $anchorText = mb_substr(trim((string)($item['anchor_text'] ?? '')), 0, 160);
            if ($targetUrl === '' && $anchorText === '') {
                continue;
            }
            $values[] = [
                'source_url' => $sourceUrl,
                'target_url' => $targetUrl,
                'anchor_text' => $anchorText,
                'reason' => mb_substr(trim((string)($item['reason'] ?? '')), 0, 260),
            ];
            if (count($values) >= 8) {
                break;
            }
        }

        return $values;
    }

    /**
     * @param mixed $items
     * @return list<array{src:string,alt_text:string,reason:string}>
     */
    private function normalizeImageAltSuggestions($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $values = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $src = mb_substr(trim((string)($item['src'] ?? '')), 0, 2048);
            $altText = mb_substr(trim((string)($item['alt_text'] ?? '')), 0, 255);
            if ($src === '' || $altText === '') {
                continue;
            }
            $values[] = [
                'src' => $src,
                'alt_text' => $altText,
                'reason' => mb_substr(trim((string)($item['reason'] ?? '')), 0, 260),
            ];
            if (count($values) >= 12) {
                break;
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function normalizeActionType(string $actionType, array $payload, string $proposedTitle, string $proposedDescription): string
    {
        $actionType = strtolower(trim($actionType));
        $allowed = [
            'metadata_update',
            'content_gap_brief',
            'internal_link_suggestion',
            'image_alt_suggestion',
            'structured_data_suggestion',
            'technical_indexing_issue',
            'manual_review',
        ];
        if (!in_array($actionType, $allowed, true)) {
            $actionType = '';
        }

        if ($actionType === '' && ($proposedTitle !== '' || $proposedDescription !== '' || (string)($payload['seo_title'] ?? '') !== '' || (string)($payload['description'] ?? '') !== '')) {
            return 'metadata_update';
        }
        if ($actionType === '' && (string)($payload['content_brief'] ?? '') !== '') {
            return 'content_gap_brief';
        }
        if ($actionType === '' && ($payload['suggested_links'] ?? []) !== []) {
            return 'internal_link_suggestion';
        }
        if ($actionType === '' && ($payload['image_alt_suggestions'] ?? []) !== []) {
            return 'image_alt_suggestion';
        }
        if ($actionType === '' && (string)($payload['structured_data_type'] ?? '') !== '') {
            return 'structured_data_suggestion';
        }

        return $actionType !== '' ? $actionType : 'manual_review';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractOutputText(array $payload): string
    {
        if (isset($payload['output_text']) && is_string($payload['output_text'])) {
            return trim($payload['output_text']);
        }

        foreach (($payload['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    return trim($content['text']);
                }
            }
        }

        return '';
    }
}
