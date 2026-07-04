<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

final class GoogleTokenService
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly RequestFactory $requestFactory,
    ) {}

    public function getAccessToken(): string
    {
        if (!$this->configuration->hasGoogleOAuthConfig()) {
            throw new RuntimeException(
                'Missing Google OAuth config. Set SEO_ASSISTANT_GOOGLE_CLIENT_ID, SEO_ASSISTANT_GOOGLE_CLIENT_SECRET and SEO_ASSISTANT_GOOGLE_REFRESH_TOKEN.',
                1760000001
            );
        }

        $config = $this->configuration->getGoogleOAuthConfig();
        $response = $this->requestFactory->request(
            'https://oauth2.googleapis.com/token',
            'POST',
            [
                'form_params' => [
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'refresh_token' => $config['refresh_token'],
                    'grant_type' => 'refresh_token',
                ],
                'timeout' => 20,
            ]
        );

        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload) || empty($payload['access_token']) || !is_string($payload['access_token'])) {
            throw new RuntimeException('Google OAuth response did not contain an access_token.', 1760000002);
        }

        return $payload['access_token'];
    }
}
