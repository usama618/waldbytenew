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
        if (!$this->isConfigured()) {
            return null;
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
                            'Du bist ein technischer SEO-Redakteur fuer eine deutsche Webagentur.',
                            'Erstelle nur konkrete, faktenbasierte Empfehlungen.',
                            'Keine erfundenen Leistungsversprechen, keine Rankings garantieren.',
                            'Meta Titles maximal 60 Zeichen, Meta Descriptions maximal 155 Zeichen.',
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
                                'name' => 'seo_recommendation',
                                'strict' => true,
                                'schema' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => [
                                        'issue',
                                        'recommendation',
                                        'proposed_seo_title',
                                        'proposed_description',
                                        'priority',
                                    ],
                                    'properties' => [
                                        'issue' => [
                                            'type' => 'string',
                                        ],
                                        'recommendation' => [
                                            'type' => 'string',
                                        ],
                                        'proposed_seo_title' => [
                                            'type' => 'string',
                                        ],
                                        'proposed_description' => [
                                            'type' => 'string',
                                        ],
                                        'priority' => [
                                            'type' => 'integer',
                                            'minimum' => 1,
                                            'maximum' => 100,
                                        ],
                                    ],
                                ],
                            ],
                            'verbosity' => 'low',
                        ],
                        'max_output_tokens' => 700,
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

            $recommendation = json_decode($outputText, true);
            if (!is_array($recommendation)) {
                return null;
            }

            return [
                'issue' => (string)($recommendation['issue'] ?? ''),
                'recommendation' => (string)($recommendation['recommendation'] ?? ''),
                'proposed_seo_title' => (string)($recommendation['proposed_seo_title'] ?? ''),
                'proposed_description' => (string)($recommendation['proposed_description'] ?? ''),
                'priority' => max(1, min(100, (int)($recommendation['priority'] ?? 50))),
            ];
        } catch (Throwable) {
            return null;
        }
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
