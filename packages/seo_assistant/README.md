# SEO Assistant

This TYPO3 extension imports Google Search Console data, snapshots TYPO3 page SEO fields and content, and creates reviewable SEO recommendations.

## Environment variables

You can configure the extension in the TYPO3 backend under:

`Admin Tools > Settings > Extension Configuration > SEO Assistant`

Environment variables are still supported and take precedence over backend configuration. Use them on live if you do not want secrets stored in TYPO3 system configuration:

- `SEO_ASSISTANT_GSC_SITE_URL`: Search Console property, for example `sc-domain:waldbyte.de` or `https://waldbyte.de/`
- `SEO_ASSISTANT_GOOGLE_CLIENT_ID`
- `SEO_ASSISTANT_GOOGLE_CLIENT_SECRET`
- `SEO_ASSISTANT_GOOGLE_REFRESH_TOKEN`
- `SEO_ASSISTANT_BASE_URL`: public base URL used for page snapshots, for example `https://waldbyte.de/`
- `SEO_ASSISTANT_OPENAI_API_KEY`: optional, enables AI-first recommendation generation
- `SEO_ASSISTANT_OPENAI_MODEL`: optional but required for AI mode, for example the model you want to use with the OpenAI Responses API

The backend configuration also contains default limits for rendered URL snapshots, minimum impressions, recommendation candidates, and AI page analyses.

## Commands

```bash
vendor/bin/typo3 seo:gsc:sync
vendor/bin/typo3 seo:pages:snapshot
vendor/bin/typo3 seo:rendered:snapshot
vendor/bin/typo3 seo:recommendations:generate
```

Use `--dry-run` on sync/snapshot commands to see what would be processed without writing rows.

`seo:pages:snapshot` reads TYPO3 page records, `tt_content` fields, and site-package inline records. `seo:rendered:snapshot` crawls the actual frontend HTML for the collected URLs and stores titles, descriptions, H1-H6 headings, visible text, internal links, images and alt text, robots/canonical data, and JSON-LD structured data.

When OpenAI is configured, `seo:recommendations:generate` uses AI-first generation. The rule-based generator is only used when AI is disabled, not configured, or produces no usable recommendation.

AI generation stores compact run memory in the database and keeps the latest 10 runs. Each new AI run receives those summaries as context so it can avoid repeating the same work.

Recommendations are stored as drafts. Applying a recommendation is intentionally manual:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes
```

Only `pages.seo_title` and `pages.description` are updated by the apply command.
