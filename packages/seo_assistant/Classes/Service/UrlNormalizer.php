<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

final class UrlNormalizer
{
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return rtrim(strtolower($url), '/');
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        if ($path === '') {
            $path = '/';
        }

        return $host . $path;
    }
}
