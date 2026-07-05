<?php

declare(strict_types=1);

namespace App\SeoAssistant\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;

final class RenderedSnapshotService
{
    private const GSC_TABLE = 'tx_seoassistant_gsc_row';
    private const PAGE_SNAPSHOT_TABLE = 'tx_seoassistant_page_snapshot';
    private const RENDERED_SNAPSHOT_TABLE = 'tx_seoassistant_rendered_snapshot';

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly ConnectionPool $connectionPool,
        private readonly RequestFactory $requestFactory,
        private readonly UrlNormalizer $urlNormalizer,
    ) {}

    /**
     * @param list<string> $explicitUrls
     * @return array{processed:int,stored:int,failed:int,baseUrl:string}
     */
    public function snapshot(
        ?string $baseUrl = null,
        array $explicitUrls = [],
        int $limit = 250,
        bool $dryRun = false,
    ): array {
        $baseUrl = $this->configuration->getBaseUrl($baseUrl);
        $urls = $this->collectUrls($baseUrl, $explicitUrls, $limit);
        $stored = 0;
        $failed = 0;

        foreach ($urls as $url) {
            $snapshot = $this->snapshotUrl($url, $baseUrl);
            if ($snapshot['http_status'] >= 400 || $snapshot['http_status'] === 0) {
                $failed++;
            }
            if (!$dryRun) {
                $stored += $this->storeSnapshot($snapshot);
            }
        }

        return [
            'processed' => count($urls),
            'stored' => $stored,
            'failed' => $failed,
            'baseUrl' => $baseUrl,
        ];
    }

    /**
     * @param list<string> $explicitUrls
     * @return list<string>
     */
    private function collectUrls(string $baseUrl, array $explicitUrls, int $limit): array
    {
        $urls = [];
        foreach ($explicitUrls as $url) {
            $this->addUrl($urls, $this->absolutizeUrl($url, $baseUrl), $baseUrl);
        }

        $connection = $this->connectionPool->getConnectionForTable(self::PAGE_SNAPSHOT_TABLE);
        foreach ($connection->createQueryBuilder()
            ->select('page_url')
            ->from(self::PAGE_SNAPSHOT_TABLE)
            ->where('page_url <> :empty')
            ->setParameter('empty', '')
            ->executeQuery()
            ->fetchFirstColumn() as $url) {
            $this->addUrl($urls, (string)$url, $baseUrl);
        }

        $connection = $this->connectionPool->getConnectionForTable(self::GSC_TABLE);
        foreach ($connection->createQueryBuilder()
            ->select('page_url')
            ->addSelectLiteral('MAX(impressions) AS max_impressions')
            ->from(self::GSC_TABLE)
            ->where('page_url <> :empty')
            ->groupBy('page_url')
            ->orderBy('max_impressions', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('empty', '')
            ->executeQuery()
            ->fetchFirstColumn() as $url) {
            $this->addUrl($urls, (string)$url, $baseUrl);
        }

        return array_slice(array_values($urls), 0, max(1, $limit));
    }

    /**
     * @param array<string,string> $urls
     */
    private function addUrl(array &$urls, string $url, string $baseUrl): void
    {
        if (!$this->isCrawlableUrl($url, $baseUrl)) {
            return;
        }

        $urls[$this->urlNormalizer->normalize($url)] = $url;
    }

    private function isCrawlableUrl(string $url, string $baseUrl): bool
    {
        $urlParts = parse_url($url);
        $baseParts = parse_url($baseUrl);
        if (!is_array($urlParts) || !is_array($baseParts)) {
            return false;
        }

        $scheme = strtolower((string)($urlParts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string)($urlParts['host'] ?? ''));
        $baseHost = strtolower((string)($baseParts['host'] ?? ''));

        return $host !== '' && $baseHost !== '' && $host === $baseHost;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotUrl(string $url, string $baseUrl): array
    {
        $baseData = [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'url' => $url,
            'url_hash' => hash('sha256', $this->urlNormalizer->normalize($url)),
            'http_status' => 0,
            'final_url' => $url,
            'html_title' => '',
            'meta_description' => '',
            'canonical_url' => '',
            'robots' => '',
            'headings_json' => '[]',
            'links_json' => '[]',
            'images_json' => '[]',
            'structured_data_json' => '[]',
            'visible_text' => '',
            'word_count' => 0,
            'h1_count' => 0,
            'image_count' => 0,
            'missing_alt_count' => 0,
            'internal_link_count' => 0,
            'external_link_count' => 0,
            'issues_json' => '[]',
        ];

        try {
            $response = $this->requestFactory->request(
                $url,
                'GET',
                [
                    'headers' => [
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'User-Agent' => 'WALDBYTE SEO Assistant (+https://waldbyte.de/)',
                    ],
                    'allow_redirects' => true,
                    'http_errors' => false,
                    'timeout' => 25,
                ]
            );
            $html = (string)$response->getBody();
            $baseData['http_status'] = $response->getStatusCode();
        } catch (Throwable $exception) {
            $baseData['issues_json'] = $this->json([
                [
                    'code' => 'fetch_failed',
                    'severity' => 'critical',
                    'message' => $exception->getMessage(),
                ],
            ]);
            return $baseData;
        }

        if ($html === '') {
            $baseData['issues_json'] = $this->json([
                [
                    'code' => 'empty_response',
                    'severity' => 'critical',
                    'message' => 'The URL returned an empty response body.',
                ],
            ]);
            return $baseData;
        }

        return array_merge($baseData, $this->parseHtml($html, $url, $baseUrl, (int)$baseData['http_status']));
    }

    /**
     * @return array<string,mixed>
     */
    private function parseHtml(string $html, string $url, string $baseUrl, int $httpStatus): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $htmlTitle = $this->firstNodeText($xpath, '//title');
        $metaDescription = $this->metaContent($xpath, 'description');
        $robots = $this->metaContent($xpath, 'robots');
        $canonical = $this->canonicalHref($xpath, $url);
        $headings = $this->extractHeadings($xpath);
        $images = $this->extractImages($xpath, $url);
        $links = $this->extractLinks($xpath, $url, $baseUrl);
        $structuredData = $this->extractStructuredData($xpath);
        $visibleText = $this->extractVisibleText($dom, $xpath);
        $wordCount = $this->countWords($visibleText);
        $h1Count = count(array_filter($headings, static fn(array $heading): bool => $heading['level'] === 'h1'));
        $missingAltCount = count(array_filter($images, static fn(array $image): bool => $image['missing_alt']));
        $internalLinkCount = count(array_filter($links, static fn(array $link): bool => $link['internal']));
        $externalLinkCount = count($links) - $internalLinkCount;

        $issues = $this->buildIssues(
            $httpStatus,
            $htmlTitle,
            $metaDescription,
            $canonical,
            $robots,
            $wordCount,
            $h1Count,
            count($images),
            $missingAltCount,
            $structuredData
        );

        return [
            'html_title' => mb_substr($htmlTitle, 0, 512),
            'meta_description' => $metaDescription,
            'canonical_url' => mb_substr($canonical, 0, 2048),
            'robots' => mb_substr($robots, 0, 128),
            'headings_json' => $this->json($headings),
            'links_json' => $this->json(array_slice($links, 0, 250)),
            'images_json' => $this->json(array_slice($images, 0, 250)),
            'structured_data_json' => $this->json($structuredData),
            'visible_text' => $visibleText,
            'word_count' => $wordCount,
            'h1_count' => $h1Count,
            'image_count' => count($images),
            'missing_alt_count' => $missingAltCount,
            'internal_link_count' => $internalLinkCount,
            'external_link_count' => $externalLinkCount,
            'issues_json' => $this->json($issues),
        ];
    }

    /**
     * @return list<array{level:string,text:string}>
     */
    private function extractHeadings(DOMXPath $xpath): array
    {
        $headings = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $text = $this->cleanText($node->textContent);
            if ($text !== '') {
                $headings[] = [
                    'level' => strtolower($node->tagName),
                    'text' => mb_substr($text, 0, 240),
                ];
            }
        }

        return $headings;
    }

    /**
     * @return list<array{src:string,alt:string,missing_alt:bool}>
     */
    private function extractImages(DOMXPath $xpath, string $pageUrl): array
    {
        $images = [];
        foreach ($xpath->query('//img') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $src = $this->resolveUrl($node->getAttribute('src') ?: $node->getAttribute('data-src'), $pageUrl);
            $alt = $this->cleanText($node->getAttribute('alt'));
            $images[] = [
                'src' => mb_substr($src, 0, 2048),
                'alt' => mb_substr($alt, 0, 255),
                'missing_alt' => $alt === '',
            ];
        }

        return $images;
    }

    /**
     * @return list<array{href:string,text:string,internal:bool}>
     */
    private function extractLinks(DOMXPath $xpath, string $pageUrl, string $baseUrl): array
    {
        $links = [];
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = $this->resolveUrl($node->getAttribute('href'), $pageUrl);
            if ($href === '' || !str_starts_with($href, 'http')) {
                continue;
            }
            $host = strtolower((string)(parse_url($href, PHP_URL_HOST) ?: ''));
            $links[] = [
                'href' => mb_substr($href, 0, 2048),
                'text' => mb_substr($this->cleanText($node->textContent), 0, 160),
                'internal' => $host !== '' && $host === $baseHost,
            ];
        }

        return $links;
    }

    /**
     * @return list<mixed>
     */
    private function extractStructuredData(DOMXPath $xpath): array
    {
        $items = [];
        foreach ($xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "application/ld+json")]') ?: [] as $node) {
            $json = trim($node->textContent);
            if ($json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            $items[] = $decoded ?? $json;
        }

        return $items;
    }

    private function extractVisibleText(DOMDocument $dom, DOMXPath $xpath): string
    {
        foreach ($xpath->query('//script|//style|//noscript|//svg') ?: [] as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        return $this->cleanText($body->textContent);
    }

    /**
     * @return list<array{code:string,severity:string,message:string}>
     */
    private function buildIssues(
        int $httpStatus,
        string $htmlTitle,
        string $metaDescription,
        string $canonical,
        string $robots,
        int $wordCount,
        int $h1Count,
        int $imageCount,
        int $missingAltCount,
        array $structuredData,
    ): array {
        $issues = [];
        if ($httpStatus >= 400 || $httpStatus === 0) {
            $issues[] = ['code' => 'http_error', 'severity' => 'critical', 'message' => 'HTTP status is ' . $httpStatus . '.'];
        }
        if (trim($htmlTitle) === '') {
            $issues[] = ['code' => 'missing_title', 'severity' => 'critical', 'message' => 'No rendered title tag found.'];
        } elseif (mb_strlen($htmlTitle) > 60) {
            $issues[] = ['code' => 'long_title', 'severity' => 'warning', 'message' => 'Rendered title is longer than 60 characters.'];
        }
        if (trim($metaDescription) === '') {
            $issues[] = ['code' => 'missing_meta_description', 'severity' => 'critical', 'message' => 'No rendered meta description found.'];
        } elseif (mb_strlen($metaDescription) > 155) {
            $issues[] = ['code' => 'long_meta_description', 'severity' => 'warning', 'message' => 'Rendered meta description is longer than 155 characters.'];
        }
        if ($h1Count === 0) {
            $issues[] = ['code' => 'missing_h1', 'severity' => 'critical', 'message' => 'No rendered H1 found.'];
        } elseif ($h1Count > 1) {
            $issues[] = ['code' => 'multiple_h1', 'severity' => 'warning', 'message' => 'More than one rendered H1 found.'];
        }
        if ($wordCount > 0 && $wordCount < 250) {
            $issues[] = ['code' => 'thin_content', 'severity' => 'warning', 'message' => 'Rendered visible text has fewer than 250 words.'];
        }
        if ($imageCount > 0 && $missingAltCount > 0) {
            $issues[] = ['code' => 'missing_image_alt', 'severity' => 'warning', 'message' => $missingAltCount . ' rendered image(s) have empty alt text.'];
        }
        if (trim($canonical) === '') {
            $issues[] = ['code' => 'missing_canonical', 'severity' => 'notice', 'message' => 'No rendered canonical URL found.'];
        }
        if (str_contains(mb_strtolower($robots), 'noindex')) {
            $issues[] = ['code' => 'noindex', 'severity' => 'critical', 'message' => 'Rendered robots meta contains noindex.'];
        }
        if ($structuredData === []) {
            $issues[] = ['code' => 'missing_structured_data', 'severity' => 'notice', 'message' => 'No rendered JSON-LD structured data found.'];
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function storeSnapshot(array $snapshot): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::RENDERED_SNAPSHOT_TABLE);
        $existingUid = (int)$connection->createQueryBuilder()
            ->select('uid')
            ->from(self::RENDERED_SNAPSHOT_TABLE)
            ->where('url_hash = :urlHash')
            ->setParameter('urlHash', (string)$snapshot['url_hash'])
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $types = [
            'pid' => Connection::PARAM_INT,
            'tstamp' => Connection::PARAM_INT,
            'crdate' => Connection::PARAM_INT,
            'http_status' => Connection::PARAM_INT,
            'word_count' => Connection::PARAM_INT,
            'h1_count' => Connection::PARAM_INT,
            'image_count' => Connection::PARAM_INT,
            'missing_alt_count' => Connection::PARAM_INT,
            'internal_link_count' => Connection::PARAM_INT,
            'external_link_count' => Connection::PARAM_INT,
        ];

        if ($existingUid > 0) {
            unset($snapshot['crdate']);
            unset($types['crdate']);
            $connection->update(self::RENDERED_SNAPSHOT_TABLE, $snapshot, ['uid' => $existingUid], $types);
            return 1;
        }

        $connection->insert(self::RENDERED_SNAPSHOT_TABLE, $snapshot, $types);
        return 1;
    }

    private function firstNodeText(DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);
        return $node !== null ? $this->cleanText($node->textContent) : '';
    }

    private function metaContent(DOMXPath $xpath, string $name): string
    {
        $query = '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . strtolower($name) . '"]/@content';
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);
        return $node !== null ? $this->cleanText($node->nodeValue ?? '') : '';
    }

    private function canonicalHref(DOMXPath $xpath, string $pageUrl): string
    {
        $nodes = $xpath->query('//link[contains(concat(" ", normalize-space(translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")), " "), " canonical ")]/@href');
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);
        if ($node === null) {
            return '';
        }

        return $this->resolveUrl($node->nodeValue ?? '', $pageUrl);
    }

    private function resolveUrl(string $url, string $contextUrl): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return '';
        }
        if (str_starts_with($url, '//')) {
            $scheme = (string)(parse_url($contextUrl, PHP_URL_SCHEME) ?: 'https');
            return $scheme . ':' . $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parts = parse_url($contextUrl);
        if (!is_array($parts)) {
            return $url;
        }

        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        if ($host === '') {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $port . $url;
        }

        $path = (string)($parts['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $scheme . '://' . $host . $port . ($directory === '' ? '' : $directory) . '/' . $url;
    }

    private function absolutizeUrl(string $url, string $baseUrl): string
    {
        return $this->resolveUrl($url, $baseUrl) ?: $url;
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function countWords(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param mixed $value
     */
    private function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
