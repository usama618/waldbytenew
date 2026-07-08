<?php

declare(strict_types=1);

namespace App\SitePackage\Seo;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageInformation;

#[Autoconfigure(public: true)]
final class StructuredDataRenderer
{
    public function render(string $_, array $configuration, ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        $pageInformation = $request->getAttribute('frontend.page.information');
        if (!$site instanceof Site || !$pageInformation instanceof PageInformation) {
            return '';
        }

        $page = $pageInformation->getPageRecord();
        $currentUrl = $this->pageUrl($site, (int)$page['uid']);
        $organizationId = 'https://waldbyte.de/#organization';
        $websiteId = 'https://waldbyte.de/#website';

        $graph = [
            [
                '@type' => ['Organization', 'ProfessionalService'],
                '@id' => $organizationId,
                'name' => 'Waldbyte',
                'url' => 'https://waldbyte.de/',
                'email' => 'info@waldbyte.de',
                'telephone' => '+49 176 58824334',
                'description' => 'Waldbyte entwickelt moderne Websites, TYPO3-Systeme, WordPress-Websites und E-Commerce-Lösungen für Unternehmen in Deutschland und der DACH-Region. Das Unternehmen ist in der TechnologieRegion Karlsruhe ansässig und arbeitet standortunabhängig für professionelle Webprojekte.',
                'areaServed' => [
                    'Deutschland',
                    'DACH-Region',
                    'Baden-Württemberg',
                    'Karlsruhe',
                    'Stuttgart',
                    'Rastatt',
                    'Baden-Baden',
                    'Ettlingen',
                    'Pforzheim',
                    'Bruchsal',
                    'Rheinland-Pfalz',
                    'Hessen',
                    'Bayern',
                    'Nordrhein-Westfalen',
                    'Berlin',
                    'Hamburg',
                ],
                'knowsAbout' => [
                    'Webdesign',
                    'TYPO3 Entwicklung',
                    'TYPO3 Installation',
                    'TYPO3 Extension Entwicklung',
                    'WordPress Websites',
                    'PrestaShop Entwicklung',
                    'E-Commerce Entwicklung',
                    'Technische SEO',
                    'Website Relaunch',
                    'Wartung und Hosting',
                ],
                'hasOfferCatalog' => [
                    '@type' => 'OfferCatalog',
                    'name' => 'Webentwicklung und E-Commerce Leistungen',
                    'itemListElement' => [
                        [
                            '@type' => 'Offer',
                            'itemOffered' => [
                                '@type' => 'Service',
                                'name' => 'TYPO3 Installation, Updates, Migration und Extension Entwicklung',
                            ],
                        ],
                        [
                            '@type' => 'Offer',
                            'itemOffered' => [
                                '@type' => 'Service',
                                'name' => 'PrestaShop Updates, Module, Migration und EU-Compliance Funktionen',
                            ],
                        ],
                        [
                            '@type' => 'Offer',
                            'itemOffered' => [
                                '@type' => 'Service',
                                'name' => 'Technische SEO, Website Relaunch und Performance Optimierung',
                            ],
                        ],
                        [
                            '@type' => 'Offer',
                            'itemOffered' => [
                                '@type' => 'Service',
                                'name' => 'Wartung, Hosting und laufender Support',
                            ],
                        ],
                    ],
                ],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $websiteId,
                'url' => 'https://waldbyte.de/',
                'name' => 'WALDBYTE',
                'publisher' => [
                    '@id' => $organizationId,
                ],
                'inLanguage' => 'de-DE',
            ],
        ];

        $breadcrumb = $this->breadcrumb($pageInformation, $site);
        if ($breadcrumb !== null) {
            $graph[] = $breadcrumb;
        }

        $service = $this->serviceSchema($page, $currentUrl, $organizationId);
        if ($service !== null) {
            $graph[] = $service;
        }

        $faq = $this->faqSchema($page, $currentUrl);
        if ($faq !== null) {
            $graph[] = $faq;
        }

        foreach ($this->dynamicStructuredData((int)$page['uid'], $currentUrl) as $dynamicSchema) {
            $graph[] = $dynamicSchema;
        }

        if ((int)($page['doktype'] ?? 0) === 137) {
            $graph[] = $this->blogPosting($page, $currentUrl, $organizationId, $websiteId, $site);
        }

        $json = json_encode(
            [
                '@context' => 'https://schema.org',
                '@graph' => $graph,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
    }

    private function serviceSchema(array $page, string $currentUrl, string $organizationId): ?array
    {
        $services = [
            '/leistungen/webentwicklung' => [
                'name' => 'Webentwicklung für Unternehmen',
                'serviceType' => 'Webentwicklung',
                'description' => 'Individuelle Websites, Landingpages und Webanwendungen mit sauberer Technik, schneller Ladezeit und klarer Conversion-Struktur.',
            ],
            '/leistungen/e-commerce' => [
                'name' => 'E-Commerce Entwicklung für PrestaShop & Shopware',
                'serviceType' => 'E-Commerce Entwicklung',
                'description' => 'Waldbyte entwickelt performante Online-Shops mit PrestaShop, Shopware und individueller E-Commerce-Logik.',
            ],
            '/leistungen/performance-seo' => [
                'name' => 'SEO & technische Optimierung für Websites',
                'serviceType' => 'Technische SEO und Performance-Optimierung',
                'description' => 'Technische SEO, Content-Struktur, Ladezeit, Indexierung und Conversion-Optimierung für bessere Sichtbarkeit.',
            ],
            '/leistungen/wartung-hosting' => [
                'name' => 'Wartung & Hosting für Websites und Shops',
                'serviceType' => 'Website-Wartung, Hosting, Monitoring, Backups und Recovery',
                'description' => 'Waldbyte betreut Websites, Shops und Portale mit Updates, Monitoring, Backups, Recovery-Prozessen und Hosting.',
                'areaServed' => [
                    'Deutschland',
                    'Region Karlsruhe',
                ],
            ],
            '/technologien/typo3' => [
                'name' => 'TYPO3 Agentur für Installation, Extensions und Support',
                'serviceType' => 'TYPO3 Agentur',
                'description' => 'TYPO3 Installation, Extension Entwicklung, Migration, Updates, Hosting, Support und Extranet-Lösungen für professionelle Unternehmenswebsites.',
            ],
            '/technologien/wordpress' => [
                'name' => 'WordPress Websites für Unternehmen',
                'serviceType' => 'WordPress Entwicklung',
                'description' => 'Moderne WordPress-Websites mit sauberer Struktur, schneller Performance, DSGVO-Basis und einfacher Pflege.',
            ],
            '/technologien/prestashop' => [
                'name' => 'PrestaShop Agentur für Module, Updates und Migration',
                'serviceType' => 'PrestaShop Agentur',
                'description' => 'PrestaShop Entwicklung, Module, Updates, Migration, EU-Compliance, Widerrufsbutton, Checkout-Optimierung und technische Shop-Betreuung.',
            ],
        ];

        $slug = '/' . trim((string)($page['slug'] ?? ''), '/');
        $service = $services[$slug] ?? null;
        if ($service === null) {
            return null;
        }

        return [
            '@type' => 'Service',
            '@id' => $currentUrl . '#service',
            'name' => $service['name'],
            'description' => $service['description'],
            'serviceType' => $service['serviceType'],
            'provider' => [
                '@id' => $organizationId,
            ],
            'areaServed' => $service['areaServed'] ?? 'Deutschland',
            'url' => $currentUrl,
        ];
    }

    private function faqSchema(array $page, string $currentUrl): ?array
    {
        $faqs = [
            '/leistungen/webentwicklung' => [
                [
                    'question' => 'Wann lohnt sich TYPO3?',
                    'answer' => 'TYPO3 ist sinnvoll, wenn Inhalte, Rollen, Sprachen und langfristige Erweiterbarkeit wichtig sind.',
                ],
                [
                    'question' => 'Wann reicht WordPress?',
                    'answer' => 'WordPress passt gut zu fokussierten Unternehmensseiten, Landingpages und redaktionellen Projekten mit klarer Struktur.',
                ],
                [
                    'question' => 'Wie wird die Website sichtbar?',
                    'answer' => 'Sichtbarkeit entsteht durch technische SEO, sinnvolle Inhalte, saubere interne Verlinkung und eine klare Conversion-Struktur.',
                ],
            ],
            '/leistungen/e-commerce' => [
                [
                    'question' => 'Was muss vor dem Start eines Shop-Projekts geklärt sein?',
                    'answer' => 'Vor dem Start sollten Sortiment, Zielgruppen, Zahlungsarten, Versandlogik, Produktdaten, Rechtstexte, Tracking, SEO-Struktur und spätere Wartung geklärt sein.',
                ],
                [
                    'question' => 'Wie wird ein Online-Shop sichtbar?',
                    'answer' => 'Wichtige Hebel sind schnelle Kategorie- und Produktseiten, sprechende URLs, strukturierte Inhalte, interne Verlinkung und technische SEO.',
                ],
                [
                    'question' => 'Was passiert nach dem Livegang eines Shops?',
                    'answer' => 'Updates, Monitoring, Sicherheitschecks, Performance-Optimierung und gezielte Weiterentwicklung bleiben nach dem Livegang wichtig.',
                ],
            ],
            '/leistungen/performance-seo' => [
                [
                    'question' => 'Wann lohnt sich ein technischer SEO-Audit?',
                    'answer' => 'Ein technischer SEO-Audit lohnt sich, wenn Rankings stagnieren, Seiten langsam laden, ein Relaunch geplant ist oder die Website zwar Besucher hat, aber zu wenige Anfragen erzeugt.',
                ],
                [
                    'question' => 'Hilft SEO auch lokalen Unternehmen?',
                    'answer' => 'Ja. Für regionale und fachliche B2B-Suchen braucht es klare lokale Signale, gute Leistungsseiten und passende Inhalte.',
                ],
                [
                    'question' => 'Wie hängt SEO mit Webentwicklung zusammen?',
                    'answer' => 'Saubere Templates, schnelle Komponenten und klare CMS-Strukturen sind die technische Grundlage für nachhaltige SEO.',
                ],
            ],
            '/leistungen/wartung-hosting' => [
                [
                    'question' => 'Warum sind Updates wichtig?',
                    'answer' => 'Veraltete Systeme erhöhen Sicherheitsrisiken und können Performance, Kompatibilität und Datenschutz beeinträchtigen.',
                ],
                [
                    'question' => 'Kann bestehende Technik übernommen werden?',
                    'answer' => 'Ja, nach einer technischen Prüfung von Systemzustand, Erweiterungen, Hosting, Backups und Risiken.',
                ],
                [
                    'question' => 'Welche Systeme betreut Waldbyte?',
                    'answer' => 'Waldbyte betreut unter anderem TYPO3, WordPress, PrestaShop und individuelle Webplattformen.',
                ],
            ],
            '/technologien/typo3' => [
                [
                    'question' => 'Übernimmt Waldbyte TYPO3 Installationen?',
                    'answer' => 'Ja. Waldbyte richtet TYPO3 mit Composer, Sitepackage, Seitenstruktur, Rollen, Sprachen, SEO-Grundlage und Deployment-Prozess ein.',
                ],
                [
                    'question' => 'Entwickelt Waldbyte eigene TYPO3 Extensions?',
                    'answer' => 'Ja. Waldbyte entwickelt individuelle Extensions und Content-Elemente für fachliche Anforderungen, Schnittstellen, Backend-Prozesse und geschützte Bereiche.',
                ],
                [
                    'question' => 'Kann Waldbyte TYPO3 von einer alten Version aktualisieren?',
                    'answer' => 'Ja. Waldbyte prüft Version, Extensions, Templates, PHP-Kompatibilität und Datenstruktur und plant Update, Migration oder Relaunch pragmatisch.',
                ],
                [
                    'question' => 'Bietet Waldbyte TYPO3 Support an?',
                    'answer' => 'Ja. Waldbyte unterstützt bestehende TYPO3 Systeme bei Fehlern, Updates, Performance, Sicherheit, Hosting und Weiterentwicklung.',
                ],
                [
                    'question' => 'Kann TYPO3 für ein Extranet genutzt werden?',
                    'answer' => 'Ja. TYPO3 ist stark für rollenbasierte Inhalte, Login-Bereiche, Downloads und redaktionell gepflegte Portale.',
                ],
            ],
            '/technologien/wordpress' => [
                [
                    'question' => 'Ist WordPress sicher?',
                    'answer' => 'Ja, wenn Updates, Hosting, Plugin-Auswahl, Backups und Benutzerrechte sauber betreut werden.',
                ],
                [
                    'question' => 'Kann WordPress schnell sein?',
                    'answer' => 'Ja. Schlanke Templates, optimierte Bilder, Caching und saubere Frontend-Entwicklung sind entscheidend.',
                ],
                [
                    'question' => 'Kann Waldbyte bestehende WordPress Websites verbessern?',
                    'answer' => 'Ja, zum Beispiel bei Struktur, Ladezeit, technischer SEO, Relaunch oder Wartung.',
                ],
            ],
            '/technologien/prestashop' => [
                [
                    'question' => 'Übernimmt Waldbyte PrestaShop Updates?',
                    'answer' => 'Ja. Waldbyte prüft Version, Module, Theme, PHP-Kompatibilität und Datenbank und plant Updates mit Testumgebung und Backup-Strategie.',
                ],
                [
                    'question' => 'Entwickelt Waldbyte individuelle PrestaShop Module?',
                    'answer' => 'Ja. Waldbyte entwickelt Module für Schnittstellen, Produktlogik, Checkout, Backend-Prozesse, B2B-Funktionen und individuelle Workflows.',
                ],
                [
                    'question' => 'Kann Waldbyte einen Widerrufsbutton in PrestaShop umsetzen?',
                    'answer' => 'Ja. Waldbyte unterstützt bei der technischen Umsetzung einer elektronischen Widerrufsfunktion inklusive Bestellzuordnung, Formularlogik, E-Mail-Bestätigung und Backend-Prozess.',
                ],
                [
                    'question' => 'Migriert Waldbyte von anderen Shopsystemen zu PrestaShop?',
                    'answer' => 'Ja. Waldbyte plant Migrationen von Produktdaten, Kategorien, Kunden, Bestellungen, Bildern, URLs, SEO-Daten und Weiterleitungen.',
                ],
                [
                    'question' => 'Kann Waldbyte auch von PrestaShop zu einem anderen Shopsystem migrieren?',
                    'answer' => 'Ja. Wenn ein anderes System strategisch besser passt, unterstützt Waldbyte beim Wechsel und achtet auf Datenqualität, Redirects, SEO und stabile Prozesse.',
                ],
                [
                    'question' => 'Wie wird ein PrestaShop sichtbarer?',
                    'answer' => 'Wichtige Hebel sind schnelle Kategorie- und Produktseiten, saubere URLs, strukturierte Produktdaten, interne Verlinkung, technische SEO und ein stabiler Checkout.',
                ],
            ],
        ];

        $slug = '/' . trim((string)($page['slug'] ?? ''), '/');
        $pageFaqs = $faqs[$slug] ?? null;
        if ($pageFaqs === null) {
            return null;
        }

        $mainEntity = [];
        foreach ($pageFaqs as $faq) {
            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ];
        }

        return [
            '@type' => 'FAQPage',
            '@id' => $currentUrl . '#faq',
            'mainEntity' => $mainEntity,
        ];
    }

    private function blogPosting(array $page, string $currentUrl, string $organizationId, string $websiteId, Site $site): array
    {
        $article = [
            '@type' => 'BlogPosting',
            '@id' => $currentUrl . '#article',
            'mainEntityOfPage' => $currentUrl,
            'isPartOf' => [
                '@id' => $websiteId,
            ],
            'headline' => $this->pageTitle($page),
            'description' => (string)($page['description'] ?? ''),
            'author' => [
                '@id' => $organizationId,
            ],
            'publisher' => [
                '@id' => $organizationId,
            ],
            'inLanguage' => 'de-DE',
        ];

        $imageUrl = $this->pageImageUrl((int)$page['uid'], $site);
        if ($imageUrl !== null) {
            $article['image'] = [$imageUrl];
        }

        $published = (int)($page['publish_date'] ?: $page['crdate'] ?? 0);
        if ($published > 0) {
            $article['datePublished'] = date(DATE_ATOM, $published);
        }

        $modified = (int)($page['SYS_LASTCHANGED'] ?: $page['tstamp'] ?? 0);
        if ($modified > 0) {
            $article['dateModified'] = date(DATE_ATOM, $modified);
        }

        return $article;
    }

    private function breadcrumb(PageInformation $pageInformation, Site $site): ?array
    {
        $rootLine = $pageInformation->getRootLine();
        if ($rootLine === []) {
            return null;
        }

        $currentPage = $pageInformation->getPageRecord();
        $rootLine = array_reverse($rootLine);

        $items = [];
        foreach ($rootLine as $page) {
            if ((int)($page['uid'] ?? 0) <= 0 || (int)($page['doktype'] ?? 0) === 254 || (int)($page['hidden'] ?? 0) === 1) {
                continue;
            }
            $items[] = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
                'name' => $this->pageTitle($page),
                'item' => $this->pageUrl($site, (int)$page['uid']),
            ];
        }

        if (count($items) < 2) {
            return null;
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $this->pageUrl($site, (int)$currentPage['uid']) . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function dynamicStructuredData(int $pageId, string $currentUrl): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_seoassistant_structured_data');
        $tableNames = $connection->getSchemaInformation()->listTableNames();
        if (!in_array('tx_seoassistant_structured_data', $tableNames, true)) {
            return [];
        }

        $queryBuilder = $connection->createQueryBuilder();
        $rows = $queryBuilder
            ->select('json_ld')
            ->from('tx_seoassistant_structured_data')
            ->where(
                $queryBuilder->expr()->eq('enabled', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('page_url', $queryBuilder->createNamedParameter($currentUrl))
                )
            )
            ->orderBy('crdate', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $items = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string)($row['json_ld'] ?? ''), true);
            if (is_array($decoded) && $decoded !== []) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    private function pageTitle(array $page): string
    {
        return trim((string)($page['nav_title'] ?: $page['seo_title'] ?: $page['title'] ?? ''));
    }

    private function pageUrl(Site $site, int $pageId): string
    {
        return (string)$site->getRouter()->generateUri($pageId, [], '', RouterInterface::ABSOLUTE_URL);
    }

    private function pageImageUrl(int $pageId, Site $site): ?string
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');
        $row = $queryBuilder
            ->select('file.identifier')
            ->from('sys_file_reference', 'ref')
            ->join('ref', 'sys_file', 'file', 'file.uid = ref.uid_local')
            ->where(
                $queryBuilder->expr()->eq('ref.uid_foreign', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('ref.tablenames', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->in(
                    'ref.fieldname',
                    [
                        $queryBuilder->createNamedParameter('featured_image'),
                        $queryBuilder->createNamedParameter('og_image'),
                    ]
                )
            )
            ->orderBy('ref.sorting_foreign')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $identifier = trim((string)($row['identifier'] ?? ''));
        if ($identifier === '') {
            return null;
        }

        return rtrim((string)$site->getBase(), '/') . '/fileadmin' . $identifier;
    }
}
