<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

final class ConfigurationService
{
    public function getGscSiteUrl(?string $override = null): string
    {
        return $this->firstValue(
            $override,
            $this->env('SEO_ASSISTANT_GSC_SITE_URL'),
            $this->env('GSC_SITE_URL'),
            'sc-domain:waldbyte.de'
        );
    }

    public function getBaseUrl(?string $override = null): string
    {
        $baseUrl = $this->firstValue(
            $override,
            $this->env('SEO_ASSISTANT_BASE_URL'),
            $this->env('TYPO3_SITE_BASE'),
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
                $this->env('GSC_OAUTH_CLIENT_ID')
            ),
            'client_secret' => $this->firstValue(
                $this->env('SEO_ASSISTANT_GOOGLE_CLIENT_SECRET'),
                $this->env('GSC_OAUTH_CLIENT_SECRET')
            ),
            'refresh_token' => $this->firstValue(
                $this->env('SEO_ASSISTANT_GOOGLE_REFRESH_TOKEN'),
                $this->env('GSC_OAUTH_REFRESH_TOKEN')
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
            $this->env('OPENAI_API_KEY')
        );
    }

    public function getOpenAiModel(): string
    {
        return $this->firstValue(
            $this->env('SEO_ASSISTANT_OPENAI_MODEL'),
            $this->env('OPENAI_MODEL')
        );
    }

    public function getOpenAiResponsesUrl(): string
    {
        return $this->firstValue(
            $this->env('SEO_ASSISTANT_OPENAI_RESPONSES_URL'),
            'https://api.openai.com/v1/responses'
        );
    }

    public function isAiConfigured(): bool
    {
        return $this->getOpenAiApiKey() !== '' && $this->getOpenAiModel() !== '';
    }

    private function env(string $key): string
    {
        $value = getenv($key);
        if ($value === false) {
            return '';
        }

        return trim((string)$value);
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
