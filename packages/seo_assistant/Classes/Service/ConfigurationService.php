<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ConfigurationService
{
    private const EXTENSION_KEY = 'seo_assistant';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getGscSiteUrl(?string $override = null): string
    {
        return $this->firstValue(
            $override,
            $this->env('SEO_ASSISTANT_GSC_SITE_URL'),
            $this->env('GSC_SITE_URL'),
            $this->extensionValue('gscSiteUrl'),
            'sc-domain:waldbyte.de'
        );
    }

    public function getBaseUrl(?string $override = null): string
    {
        $baseUrl = $this->firstValue(
            $override,
            $this->env('SEO_ASSISTANT_BASE_URL'),
            $this->env('TYPO3_SITE_BASE'),
            $this->extensionValue('baseUrl'),
            'https://waldbyte.de/'
        );

        return rtrim($baseUrl, '/') . '/';
    }

    /**
     * @return array{client_id:string,client_secret:string,refresh_token:string}
     */
    public function getGoogleOAuthConfig(): array
    {
        return [
            'client_id' => $this->firstValue(
                $this->env('SEO_ASSISTANT_GOOGLE_CLIENT_ID'),
                $this->env('GSC_OAUTH_CLIENT_ID'),
                $this->extensionValue('googleClientId')
            ),
            'client_secret' => $this->firstValue(
                $this->env('SEO_ASSISTANT_GOOGLE_CLIENT_SECRET'),
                $this->env('GSC_OAUTH_CLIENT_SECRET'),
                $this->extensionValue('googleClientSecret')
            ),
            'refresh_token' => $this->firstValue(
                $this->env('SEO_ASSISTANT_GOOGLE_REFRESH_TOKEN'),
                $this->env('GSC_OAUTH_REFRESH_TOKEN'),
                $this->extensionValue('googleRefreshToken')
            ),
        ];
    }

    public function hasGoogleOAuthConfig(): bool
    {
        $config = $this->getGoogleOAuthConfig();

        return $config['client_id'] !== ''
            && $config['client_secret'] !== ''
            && $config['refresh_token'] !== '';
    }

    public function getOpenAiApiKey(): string
    {
        return $this->firstValue(
            $this->env('SEO_ASSISTANT_OPENAI_API_KEY'),
            $this->env('OPENAI_API_KEY'),
            $this->extensionValue('openAiApiKey')
        );
    }

    public function getOpenAiModel(): string
    {
        return $this->firstValue(
            $this->env('SEO_ASSISTANT_OPENAI_MODEL'),
            $this->env('OPENAI_MODEL'),
            $this->extensionValue('openAiModel')
        );
    }

    public function getOpenAiResponsesUrl(): string
    {
        return $this->firstValue(
            $this->env('SEO_ASSISTANT_OPENAI_RESPONSES_URL'),
            $this->extensionValue('openAiResponsesUrl'),
            'https://api.openai.com/v1/responses'
        );
    }

    public function getOpenAiInputCostPerMillion(): float
    {
        return $this->nonNegativeFloat($this->firstValue(
            $this->env('SEO_ASSISTANT_OPENAI_INPUT_COST_PER_MILLION'),
            $this->extensionValue('openAiInputCostPerMillion')
        ));
    }

    public function getOpenAiOutputCostPerMillion(): float
    {
        return $this->nonNegativeFloat($this->firstValue(
            $this->env('SEO_ASSISTANT_OPENAI_OUTPUT_COST_PER_MILLION'),
            $this->extensionValue('openAiOutputCostPerMillion')
        ));
    }

    public function isAiConfigured(): bool
    {
        return $this->getOpenAiApiKey() !== '' && $this->getOpenAiModel() !== '';
    }

    public function getRenderedSnapshotLimit(int $fallback = 250): int
    {
        return $this->positiveInt($this->extensionValue('renderedSnapshotLimit'), $fallback);
    }

    public function getMinImpressions(int $fallback = 20): int
    {
        return $this->positiveInt($this->extensionValue('minImpressions'), $fallback);
    }

    public function getRecommendationLimit(int $fallback = 100): int
    {
        return $this->positiveInt($this->extensionValue('recommendationLimit'), $fallback);
    }

    public function getAiLimit(int $fallback = 10): int
    {
        return $this->positiveInt($this->extensionValue('aiLimit'), $fallback);
    }

    private function env(string $key): string
    {
        $value = getenv($key);
        if ($value === false) {
            return '';
        }

        return trim((string)$value);
    }

    private function extensionValue(string $path): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get(self::EXTENSION_KEY, $path));
        } catch (Throwable) {
            return '';
        }
    }

    private function positiveInt(string $value, int $fallback): int
    {
        $value = (int)$value;
        if ($value <= 0) {
            return $fallback;
        }

        return $value;
    }

    private function nonNegativeFloat(string $value): float
    {
        $value = (float)str_replace(',', '.', trim($value));

        return $value > 0 ? $value : 0.0;
    }

    private function firstValue(?string ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
