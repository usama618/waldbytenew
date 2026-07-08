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
vendor/bin/typo3 seo:gsc:analyze-trends --sync
vendor/bin/typo3 seo:rendered:snapshot
vendor/bin/typo3 seo:recommendations:generate
vendor/bin/typo3 seo:recommendations:verify --all
```

Use `--dry-run` on sync/snapshot commands to see what would be processed without writing rows.

`seo:pages:snapshot` reads TYPO3 page records, `tt_content` fields, and site-package inline records. `seo:rendered:snapshot` crawls the actual frontend HTML for the collected URLs and stores titles, descriptions, H1-H6 headings, visible text, internal links, images and alt text, robots/canonical data, and JSON-LD structured data.

Search Console URLs are only crawled and used for recommendations when they still match a URL in
the current TYPO3 page snapshot. Old indexed URLs from a previous website are skipped so they do not
create 404/error recommendations for pages that no longer belong to the current site.

`seo:gsc:analyze-trends` compares two exact historical Search Console windows for current TYPO3
pages and stores content performance insights. Use `--sync` to fetch both windows from Google first:

```bash
vendor/bin/typo3 seo:gsc:analyze-trends --sync
```

By default this compares the latest complete 28 days against the previous 28 days. It identifies
content that is working, declining, getting impressions without clicks, improving in striking
distance, or not matching search intent well enough.

When OpenAI is configured, `seo:recommendations:generate` uses AI-first generation. The rule-based generator is only used when AI is disabled, not configured, or produces no usable recommendation.

AI generation stores compact run memory in the database and keeps the latest 10 runs. Each new AI run receives those summaries as context so it can avoid repeating the same work.

In the backend module, every AI memory run has a `Download suggestions` button. It downloads a
Markdown document for that run with analyzed pages, AI recommendations, content drafts, metadata
commands, image alt/link/schema suggestions, and local DDEV workflow notes. Use that file as the
handoff document for local TYPO3 testing before committing template/schema/content changes and
deploying through CI/CD.

Recommendations are stored as drafts with a typed action payload. Rows marked with
`apply_capability=safe_metadata` update page metadata. Rows marked with
`apply_capability=content_draft` can create a TYPO3 `tt_content` element, using the site's
`seo_text` CType by default. Rows marked with `apply_capability=image_alt` update matched TYPO3
`sys_file_reference.alternative` values. Internal link, structured data and technical indexing
actions still stay manual review items.

Applying a recommendation is intentionally explicit:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes
```

To review or apply all automatic recommendations from the CLI:

```bash
vendor/bin/typo3 seo:recommendations:apply --all
vendor/bin/typo3 seo:recommendations:apply --all --yes
```

Bulk apply only writes recommendations that are safe for database automation:
`safe_metadata`, `content_draft`, `image_alt`, `indexing_update`, and `structured_data`.
File/template changes are skipped. CLI-created content stays hidden unless `--publish-content` is
passed. In the backend module, the Apply buttons publish generated content sections directly.

The backend module also shows an `Apply` button for every automatic recommendation and an
`Apply all automatic` button above the table. Every row also has a `Reject` button. Rejected
recommendations are marked `dismissed`, hidden from the table, and excluded from future bulk apply
runs. The buttons can also handle older `manual_review`
rows when the extension can convert them safely from their recommendation type, for example long
titles, long meta descriptions, thin content, missing H1, indexing/canonical suggestions and
structured-data suggestions.

The same backend area also has a `Generate fresh recommendations` button. It runs the equivalent of
the page snapshot, rendered snapshot and recommendation generation commands with configurable
render/recommendation/AI limits, so editors can refresh AI suggestions without SSH access.
Long-running backend actions show a blocking progress overlay until TYPO3 finishes the request and
reloads the module.

Metadata recommendations update `pages.seo_title` and `pages.description`. Content-gap
recommendations create a hidden `seo_text` element by default:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes
```

To publish the created content immediately, pass `--publish-content`. Publishing requires an
AI-generated `content_body_html` payload unless `--force` is also passed:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes --publish-content
```

The command records the applied field values or inserted content UID. After applying metadata,
image alt text, or published content, refresh the rendered snapshot and verify that the frontend
HTML contains the change:

```bash
vendor/bin/typo3 seo:recommendations:verify --uid=123 --refresh
```

Before showing or bulk-applying rows, the extension checks whether a recommendation is already
implemented. It hides rows when the current TYPO3 metadata already matches, suggested content is
already visible, image alt text is already stored on matching file references, or the requested
JSON-LD type is already present in the latest rendered snapshot.

Structured-data recommendations are stored in `tx_seoassistant_structured_data` and rendered by the
site package JSON-LD renderer. After deploying this feature, run TYPO3 extension setup/database
analysis once so the new table exists.
