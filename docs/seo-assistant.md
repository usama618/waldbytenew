# SEO Assistant Setup

The SEO assistant is implemented as `app/seo-assistant` in `packages/seo_assistant`.

## Configuration

Configure the extension in the TYPO3 backend:

`Admin Tools > Settings > Extension Configuration > SEO Assistant`

There you can set:

- Search Console property
- Google OAuth client ID, client secret and refresh token
- public base URL
- rendered snapshot URL limit
- OpenAI API key/model/API URL
- minimum impressions and recommendation limits

Environment variables are still supported and take precedence over backend configuration. Use them on live if you do not want secrets stored in TYPO3 system configuration:

```bash
SEO_ASSISTANT_GSC_SITE_URL='sc-domain:waldbyte.de'
SEO_ASSISTANT_GOOGLE_CLIENT_ID='...'
SEO_ASSISTANT_GOOGLE_CLIENT_SECRET='...'
SEO_ASSISTANT_GOOGLE_REFRESH_TOKEN='...'
SEO_ASSISTANT_BASE_URL='https://waldbyte.de/'
SEO_ASSISTANT_OPENAI_API_KEY='...'
SEO_ASSISTANT_OPENAI_MODEL='...'
```

AI is optional. If `SEO_ASSISTANT_OPENAI_API_KEY` and `SEO_ASSISTANT_OPENAI_MODEL` are configured, recommendation generation is AI-first. The rule-based generator is only used when AI is disabled, not configured, or returns no usable recommendation.

AI runs keep compact memory in the database. The extension stores the latest 10 AI generation runs and sends those summaries into the next AI run as context.

Each AI run can also be exported from `Web > SEO Assistant` with the `Download suggestions`
button. The downloaded Markdown document contains the run summary, analyzed pages, matching
recommendations, metadata apply commands, content drafts, and manual template/schema/link/image
tasks. Use this document in the local TYPO3/DDEV installation to test code and content changes
before deploying them through the CI/CD pipeline.

Recommendations now include typed action metadata:

- `metadata_update`: safe apply candidate for `pages.seo_title` and/or `pages.description`
- `content_gap_brief`: applyable content draft for missing/weak page content
- `internal_link_suggestion`: manual internal-link direction
- `image_alt_suggestion`: applyable image alt text when TYPO3 file references can be matched safely
- `structured_data_suggestion`: schema idea for implementation through the site package renderer
- `technical_indexing_issue`: routing, robots, canonical or HTTP issue requiring manual review

`metadata_update` rows with `apply_capability=safe_metadata` can be applied automatically.
`content_gap_brief` rows with `apply_capability=content_draft` can create a TYPO3 content element
automatically, using the site package `seo_text` CType by default.
`image_alt_suggestion` rows with `apply_capability=image_alt` can update matched
`sys_file_reference.alternative` values automatically. If the rendered image URL cannot be matched
to exactly one TYPO3 file reference, that image is skipped instead of guessed.

## First run

After deployment, run TYPO3 extension setup so the tables from `ext_tables.sql` exist:

```bash
vendor/bin/typo3 extension:setup
```

Then open the backend Extension Configuration once and save the SEO Assistant values.

Then build the data pipeline:

```bash
vendor/bin/typo3 seo:gsc:sync
vendor/bin/typo3 seo:pages:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:gsc:analyze-trends --sync
vendor/bin/typo3 seo:rendered:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:recommendations:generate
```

Open the TYPO3 backend module `Web > SEO Assistant` to review AI run memory, drafts, rendered URL issues, and CMS content snapshots centrally.

Search Console can still contain URLs from the previous website. These are intentionally ignored
unless they match a URL from the current TYPO3 page snapshot. Run `seo:pages:snapshot` first so the
assistant knows which URLs belong to the current website.

## GSC Trend Analysis

Use this to understand which content is working or not working over time:

```bash
vendor/bin/typo3 seo:gsc:analyze-trends --sync
```

By default it fetches and compares two exact 28-day Search Console windows: the latest complete
28 days against the previous 28 days. The analysis stores page/query insights for current TYPO3
pages only, including:

- `content_working`: organic clicks are increasing
- `content_declining`: organic clicks are decreasing
- `visibility_opportunity`: impressions are increasing but clicks are not following
- `content_not_working`: impressions exist but organic clicks stay very weak
- `striking_distance`: rankings are within reach and need stronger content/snippets/internal links

For custom windows:

```bash
vendor/bin/typo3 seo:gsc:analyze-trends --sync \
  --previous-start=2026-05-11 --previous-end=2026-06-07 \
  --current-start=2026-06-08 --current-end=2026-07-05
```

## Apply flow

Applying is per recommendation. The first command is always a dry run:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes
```

You can also process all automatic recommendations in one run:

```bash
vendor/bin/typo3 seo:recommendations:apply --all
vendor/bin/typo3 seo:recommendations:apply --all --yes
```

`--all` is intentionally conservative. It applies only `safe_metadata`, `content_draft`, and
`image_alt` recommendations. Manual template/schema/internal-link/indexing suggestions are
reported as skipped. Content recommendations create hidden drafts unless `--publish-content` is
also passed.

The backend module `Web > SEO Assistant` has the same behavior: every automatic recommendation has
an `Apply` button, and the recommendations table has an `Apply all automatic` button.

For metadata recommendations, the second command writes `pages.seo_title` and/or
`pages.description`, records the applied field values, and sets verification to pending.

For content-gap recommendations, the second command creates a hidden `tt_content` element:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes
```

To publish the new content immediately:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes --publish-content
```

Publishing requires AI-generated `content_body_html`. Older/rule-based recommendations can still
create hidden drafts; use `--force --publish-content` only if you intentionally want to publish the
fallback draft body.

After applying metadata, verify against the rendered frontend output:

```bash
vendor/bin/typo3 seo:recommendations:verify --uid=123 --refresh
vendor/bin/typo3 seo:recommendations:verify --all --refresh
```

The `--refresh` option re-runs a rendered snapshot for the affected URL before comparing applied
metadata or image alt text with the current frontend HTML.

The recommendation table hides rows that are already implemented. The check uses TYPO3 page
metadata, visible content text, matched file-reference alt text, and the latest rendered JSON-LD
snapshot where applicable. Bulk apply also marks already satisfied recommendations as implemented
instead of writing them again.

## Suggested cron

Run this daily or weekly on the live server:

```bash
cd /var/www/waldbytenew/current
vendor/bin/typo3 seo:gsc:sync
vendor/bin/typo3 seo:pages:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:gsc:analyze-trends --sync
vendor/bin/typo3 seo:rendered:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:recommendations:generate --limit=100 --ai-limit=10
vendor/bin/typo3 seo:recommendations:verify --all
```

The rendered snapshot command crawls only the configured same-host URLs. It uses CMS page snapshots and Search Console page URLs as its source list.
