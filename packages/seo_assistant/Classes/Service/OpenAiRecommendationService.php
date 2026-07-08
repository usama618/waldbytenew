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
        private readonly AiUsageLogService $aiUsageLogService,
        private readonly SeoAssistantAlertService $alertService,
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
     * @return array{impact_status:string,confidence:string,summary:string,next_action:string}|null
     */
    public function evaluateImpact(array $context): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $startedAt = microtime(true);
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
                            'Du bist ein AI SEO Analyst fuer eine deutsche TYPO3 Webagentur.',
                            'Bewerte nur, ob eine bereits umgesetzte SEO-Empfehlung anhand der gelieferten Google Search Console Vergleichsfenster wahrscheinlich geholfen hat.',
                            'Beachte die konkreten Datumsbereiche, den Puffer nach Umsetzung und die Datenmenge.',
                            'Uebertreibe nicht: Wenn Impressionen oder Klicks zu niedrig sind, waehle not_enough_data.',
                            'Wenn die Daten gemischt oder schwach sind, waehle neutral statt improved oder declined.',
                            'Keine Ranking-Garantien und keine erfundenen Zahlen.',
                            'Antworte kurz auf Deutsch.',
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
                                'name' => 'seo_impact_evaluation',
                                'strict' => true,
                                'schema' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['impact_status', 'confidence', 'summary', 'next_action'],
                                    'properties' => [
                                        'impact_status' => [
                                            'type' => 'string',
                                            'enum' => ['improved', 'neutral', 'declined', 'not_enough_data'],
                                        ],
                                        'confidence' => [
                                            'type' => 'string',
                                            'enum' => ['low', 'medium', 'high'],
                                        ],
                                        'summary' => [
                                            'type' => 'string',
                                        ],
                                        'next_action' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                            ],
                            'verbosity' => 'low',
                        ],
                        'max_output_tokens' => 900,
                    ],
                    'timeout' => 30,
                ]
            );

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                $this->recordAiCall('impact_evaluation', $context, null, 'failed', $startedAt, 'OpenAI response was not valid JSON.');
                return null;
            }
            $this->recordAiCall('impact_evaluation', $context, $payload, 'success', $startedAt);

            $outputText = $this->extractOutputText($payload);
            if ($outputText === '') {
                return null;
            }

            $data = json_decode($outputText, true);
            if (!is_array($data)) {
                return null;
            }

            return $this->normalizeImpactEvaluation($data);
        } catch (Throwable $exception) {
            $this->recordAiCall('impact_evaluation', $context, null, 'failed', $startedAt, $exception->getMessage());
            return null;
        }
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

        $startedAt = microtime(true);
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
                            'Nutze fuer Inhalte, Links, Bilder, Schema und technische Themen eine passende action_type mit konkretem Payload.',
                            'Bei content_gap_brief liefere content_element_header und content_body_html als direkt nutzbaren deutschen TYPO3-Richtext-Entwurf mit 120 bis 220 Woertern.',
                            'content_body_html darf nur p, ul, ol, li, strong, em und br Tags enthalten, keine h-Tags, keine Links, kein Markdown.',
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
                                                        'description' => 'Machine-applicable action category. metadata_update updates page metadata; content_gap_brief can create a tt_content draft.',
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
                                                            'content_element_header',
                                                            'content_body_html',
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
                                                            'content_element_header' => [
                                                                'type' => 'string',
                                                                'description' => 'Ready-to-use content element header for content_gap_brief. Empty string otherwise.',
                                                            ],
                                                            'content_body_html' => [
                                                                'type' => 'string',
                                                                'description' => 'Ready-to-use TYPO3 richtext body HTML for content_gap_brief. Only p, ul, ol, li, strong, em, br tags. Empty string otherwise.',
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
                        'max_output_tokens' => 3600,
                    ],
                    'timeout' => 45,
                ]
            );

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                $this->recordAiCall('recommendation_generation', $context, null, 'failed', $startedAt, 'OpenAI response was not valid JSON.');
                return [];
            }
            $this->recordAiCall('recommendation_generation', $context, $payload, 'success', $startedAt);

            $outputText = $this->extractOutputText($payload);
            if ($outputText === '') {
                return [];
            }

            $payload = json_decode($outputText, true);
            if (!is_array($payload)) {
                return [];
            }

            return $this->normalizeRecommendations($payload['recommendations'] ?? [], $maxRecommendations);
        } catch (Throwable $exception) {
            $this->recordAiCall('recommendation_generation', $context, null, 'failed', $startedAt, $exception->getMessage());
            return [];
        }
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $payload
     */
    private function recordAiCall(
        string $runType,
        array $context,
        ?array $payload,
        string $status,
        float $startedAt,
        string $errorMessage = '',
    ): void {
        try {
            $usage = $payload['usage'] ?? null;
            $this->aiUsageLogService->recordCall(
                $runType,
                $this->configuration->getOpenAiModel(),
                $status,
                is_array($usage) ? $usage : null,
                $startedAt,
                $this->requestHash($runType, $context),
                $context,
                (string)($payload['id'] ?? ''),
                $errorMessage
            );
        } catch (Throwable) {
            // Usage logging must never break recommendation generation.
        }

        if ($status !== 'success') {
            try {
                $this->alertService->record(
                    'openai',
                    'OpenAI ' . $runType . ' failed',
                    $errorMessage !== '' ? $errorMessage : 'OpenAI call failed without a detailed error message.',
                    [
                        'run_type' => $runType,
                        'model' => $this->configuration->getOpenAiModel(),
                        'request_hash' => $this->requestHash($runType, $context),
                    ],
                    'error'
                );
            } catch (Throwable) {
                // Alerting must never break recommendation generation.
            }
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function requestHash(string $runType, array $context): string
    {
        return hash('sha256', $runType . '|' . $this->configuration->getOpenAiModel() . '|' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
     * @param array<string,mixed> $data
     * @return array{impact_status:string,confidence:string,summary:string,next_action:string}|null
     */
    private function normalizeImpactEvaluation(array $data): ?array
    {
        $status = (string)($data['impact_status'] ?? '');
        if (!in_array($status, ['improved', 'neutral', 'declined', 'not_enough_data'], true)) {
            return null;
        }

        $confidence = (string)($data['confidence'] ?? 'low');
        if (!in_array($confidence, ['low', 'medium', 'high'], true)) {
            $confidence = 'low';
        }

        return [
            'impact_status' => $status,
            'confidence' => $confidence,
            'summary' => mb_substr(trim((string)($data['summary'] ?? '')), 0, 2000),
            'next_action' => mb_substr(trim((string)($data['next_action'] ?? '')), 0, 2000),
        ];
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
            'content_element_header' => mb_substr(trim((string)($payload['content_element_header'] ?? '')), 0, 120),
            'content_body_html' => mb_substr(trim((string)($payload['content_body_html'] ?? '')), 0, 5000),
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
        if (
            $actionType === ''
            && (
                (string)($payload['content_body_html'] ?? '') !== ''
                || (string)($payload['content_element_header'] ?? '') !== ''
                || (string)($payload['content_brief'] ?? '') !== ''
            )
        ) {
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
