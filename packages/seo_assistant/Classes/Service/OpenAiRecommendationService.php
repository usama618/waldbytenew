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
     * @return array{issue:string,recommendation:string,proposed_seo_title:string,proposed_description:string,priority:int}|null
     */
    public function createRecommendation(array $context): ?array
    {
        $recommendations = $this->createRecommendations($context, 1);
        return $recommendations[0] ?? null;
    }

    /**
     * @param array<string,mixed> $context
     * @return list<array{recommendation_type:string,query_text:string,issue:string,recommendation:string,proposed_seo_title:string,proposed_description:string,priority:int}>
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
                        'max_output_tokens' => 1800,
                    ],
                    'timeout' => 45,
                ]
            );

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                return null;
            }

            $outputText = $this->extractOutputText($payload);
            if ($outputText === '') {
                return null;
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
     * @return list<array{recommendation_type:string,query_text:string,issue:string,recommendation:string,proposed_seo_title:string,proposed_description:string,priority:int}>
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

            $recommendations[] = [
                'recommendation_type' => trim((string)($item['recommendation_type'] ?? 'ai_recommendation')) ?: 'ai_recommendation',
                'query_text' => trim((string)($item['query_text'] ?? '')),
                'issue' => $issue,
                'recommendation' => $recommendation,
                'proposed_seo_title' => mb_substr(trim((string)($item['proposed_seo_title'] ?? '')), 0, 60),
                'proposed_description' => mb_substr(trim((string)($item['proposed_description'] ?? '')), 0, 155),
                'priority' => max(1, min(100, (int)($item['priority'] ?? 50))),
            ];

            if (count($recommendations) >= max(1, $maxRecommendations)) {
                break;
            }
        }

        return $recommendations;
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
