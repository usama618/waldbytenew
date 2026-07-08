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
SEO_ASSISTANT_OPENAI_INPUT_COST_PER_MILLION='0.00'
SEO_ASSISTANT_OPENAI_OUTPUT_COST_PER_MILLION='0.00'
```

AI is optional. If `SEO_ASSISTANT_OPENAI_API_KEY` and `SEO_ASSISTANT_OPENAI_MODEL` are configured, recommendation generation is AI-first. The rule-based generator is only used when AI is disabled, not configured, or returns no usable recommendation.

AI runs keep compact memory in the database. The extension stores the latest 10 AI generation runs and sends those summaries into the next AI run as context.

Every OpenAI API call is also logged in `tx_seoassistant_ai_call` with run type, model,
success/failure status, input/output/total tokens, duration and an estimated USD cost. The backend
module shows current-month usage and recent calls. Cost is an estimate based on the extension's
local model price map; unknown models still log tokens but show a zero-dollar estimate until the map
is updated.

Failures from OpenAI calls, GSC sync/trend analysis and scheduled SEO Assistant commands are stored
in `tx_seoassistant_alert`. The backend module shows open alerts and lets an operator resolve them
after the failed cron/worker issue has been handled.

Impact evaluations are fed back into future AI recommendation prompts. Recent improved, neutral,
declined and no-data outcomes are summarized with the action type and metric deltas so future
recommendations can favor patterns that worked and avoid repeating weak patterns.

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

`--all` is intentionally conservative. It applies only database-backed changes:
`safe_metadata`, `content_draft`, `image_alt`, `indexing_update`, and `structured_data`.
File/template changes are reported as skipped. Content recommendations create hidden drafts unless
`--publish-content` is also passed.

The backend module `Web > SEO Assistant` has the same behavior, but the buttons are optimized for
one-click use: every automatic recommendation has an `Apply` button, and the recommendations table
has an `Apply all automatic` button. Every row also has a `Reject` button. Rejected rows are marked
`rejected`, hidden from the table, and excluded from `Apply all automatic`. Single backend apply
runs immediately. Backend `Apply all automatic` is queued and processed by `seo:jobs:run`, then
writes a normal apply-history entry. Backend apply creates generated content as hidden drafts;
immediate publishing remains a deliberate CLI action with `--publish-content`. Older
`manual_review` rows are no longer force-applied from the UI. Use the CLI with `--force` only when
you intentionally want to convert a legacy database-backed row.

Recommendation lifecycle statuses are:

- `draft`: generated but not applied
- `approved`: explicitly approved and still applyable
- `applied`: database-backed change was written and awaits verification/evaluation
- `verified`: the rendered frontend or current CMS state matches the recommendation
- `evaluating`: impact is still early or has insufficient data
- `improved`, `neutral`, `declined`: impact evaluation result
- `rejected`: manually rejected
- `rolled_back`: previous database-backed values have been restored

Every write run from the backend or CLI is stored in `tx_seoassistant_apply_history`. The backend
module shows the latest history entries and each row has a `View changes` button. The exported
Markdown file contains the apply summary, per-recommendation statuses, messages and the raw result
JSON, so live and local runs each keep their own auditable record. Manual/template recommendations
also include the current CMS/rendered frontend state and the recommended target state, making the
download usable as a local implementation brief before pushing code through CI/CD.

Before the extension writes metadata, content, image alt text, indexing fields or dynamic schema
rows, it stores the previous values in `tx_seoassistant_recommendation_rollback`. The backend module
shows rollback snapshots and provides buttons to roll back one recommendation or a full apply-history
run.

The backend also has a `Generate fresh recommendations` button. It runs page snapshot, rendered
snapshot and AI recommendation generation as a queued backend job with editable limits, so fresh
suggestions can be requested without a long web request. Process queued jobs with:

```bash
vendor/bin/typo3 seo:jobs:run --limit=5
```

The backend shows recent jobs with queued/running/completed/failed status, attempts and errors.

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

## Impact evaluation

Impact evaluation checks whether applied recommendations appear to work after enough Search Console
data exists:

```bash
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync
```

Default stage:

- `first`: 35-day first evaluation, comparing 28 days before apply with 28 days after a 7-day buffer

Additional stages:

- `early`: 14-day early signal, comparing 7 days before apply with 7 days after a 7-day buffer
- `stronger`: 63-day stronger evaluation, comparing 56 days before apply with 56 days after a 7-day buffer
- `final`: 90-day final evaluation, comparing 83 days before apply with 83 days after a 7-day buffer

Examples:

```bash
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=early
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=first
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=stronger
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=final
```

The command stores exact dates, clicks, impressions, CTR, average position, rule-based impact and
optional OpenAI analysis in `tx_seoassistant_impact_evaluation`. Each stored row has an
`evaluation_stage`, so early results are visible as early signals and never confused with final
results. If the after-window is not complete yet, the recommendation stays pending. If impressions
are too low, the result is `not_enough_data` and the recommendation status stays `evaluating`
instead of a premature success/failure judgment.

Each stored evaluation also includes experiment diagnostics in `evidence_json`: sample quality,
traffic balance, confidence cap and limitations such as no holdout/control group, unisolated
seasonality and other site changes. This is still observational SEO evidence, but the stored
metadata makes that limitation explicit.

The recommendation table hides rows that are already implemented. The check uses TYPO3 page
metadata, visible content text, matched file-reference alt text, and the latest rendered JSON-LD
snapshot where applicable. Bulk apply also marks already satisfied recommendations as implemented
instead of writing them again.

AI recommendations are validated before they are stored. Rows fail guardrails when they contain
overlong SEO titles or descriptions, unsupported ranking/performance claims, obvious keyword
stuffing, non-existing internal-link targets or invalid structured-data JSON previews. Repeated
local keyword usage is kept as a warning. The backend stores `quality_status`, `quality_score` and
`quality_json` for each row.

Dynamic structured data is stored in `tx_seoassistant_structured_data` and rendered through the
site-package JSON-LD renderer. After deploying a version that adds this table, run TYPO3 extension
setup/database analysis once on the target environment. The same setup step creates
`tx_seoassistant_apply_history` for downloadable apply history and
`tx_seoassistant_ai_call` for token/cost logging and `tx_seoassistant_impact_evaluation` for
stage-based impact checks. It also creates rollback, alert and queued-job tables.

## Suggested cron

Run this daily or weekly on the live server:

```bash
cd /var/www/waldbytenew/current
vendor/bin/typo3 seo:gsc:sync
vendor/bin/typo3 seo:pages:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:gsc:analyze-trends --sync
vendor/bin/typo3 seo:rendered:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:recommendations:generate --limit=100 --ai-limit=5
vendor/bin/typo3 seo:jobs:run --limit=5
vendor/bin/typo3 seo:recommendations:verify --all
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=early
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=first
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=stronger
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=final
```

The rendered snapshot command crawls only the configured same-host URLs. It uses CMS page snapshots and Search Console page URLs as its source list.

## Tests

The extension has a dependency-free lifecycle check runner:

```bash
composer test:seo-assistant
```

It covers deterministic AI suggestion guardrails, early/final impact stage behavior and source
contracts for apply, verify and evaluate status transitions. A full TYPO3 database-backed functional
test suite would still be the next step if you want fixture-level coverage of real writes.
