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
- `SEO_ASSISTANT_OPENAI_INPUT_COST_PER_MILLION` and `SEO_ASSISTANT_OPENAI_OUTPUT_COST_PER_MILLION`: optional USD overrides for cost estimates when your model is not in the local price map

The backend configuration also contains default limits for rendered URL snapshots, minimum impressions, recommendation candidates, and AI page analyses.

## Commands

```bash
vendor/bin/typo3 seo:gsc:sync
vendor/bin/typo3 seo:pages:snapshot
vendor/bin/typo3 seo:gsc:analyze-trends --sync
vendor/bin/typo3 seo:rendered:snapshot
vendor/bin/typo3 seo:recommendations:generate
vendor/bin/typo3 seo:jobs:run
vendor/bin/typo3 seo:recommendations:verify --all
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync
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

Every OpenAI call is logged in `tx_seoassistant_ai_call` with run type, model, success/failure
status, input/output tokens, total tokens, duration and an estimated USD cost. The backend module
shows current-month AI usage and recent calls. Cost is an estimate from the extension's local model
price map; unknown models still log tokens but show a zero-dollar estimate until pricing is mapped.

Stored impact evaluations are also summarized into future AI recommendation prompts, so the
assistant can learn from changes that improved, stayed neutral, declined, or did not have enough
data.

OpenAI, GSC and scheduled command failures are recorded in `tx_seoassistant_alert` and shown in
the backend module until resolved. This gives cron/worker failures a visible backend surface instead
of relying only on server logs.

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

For scheduled automation, recommendation generation can run the same safe automatic apply step
immediately after new drafts are generated:

```bash
vendor/bin/typo3 seo:recommendations:generate --limit=100 --ai-limit=5 --apply-automatic --apply-limit=100
```

Bulk apply only writes recommendations that are safe for database automation:
`safe_metadata`, `content_draft`, `image_alt`, `indexing_update`, and `structured_data`.
File/template changes are skipped. Bulk automatic apply publishes generated content when the
recommendation passes guardrails. Single `--uid` CLI-created content stays hidden unless
`--publish-content` is passed.

The backend module also shows an `Apply` button for every automatic recommendation and an
`Apply all automatic` button above the table. Every row also has a `Reject` button. Rejected
recommendations are marked `rejected`, hidden from the table, and excluded from future bulk apply
runs. Single backend apply runs immediately. Backend `Apply all automatic` is queued and processed
by `seo:jobs:run`, then writes a normal apply-history entry. Backend apply and scheduled
`--apply-automatic` publish generated content when the recommendation passes guardrails. Older
`manual_review` rows are no longer force-applied from the UI; use the CLI with `--force` only when
you intentionally want to convert a legacy row.

Recommendation statuses are `draft`, `approved`, `applied`, `verified`, `evaluating`, `improved`,
`neutral`, `declined`, `rejected`, and `rolled_back`.

Every backend or CLI write run is recorded in `tx_seoassistant_apply_history`. The backend module
shows the latest apply history entries with counts for applied, already implemented, skipped and
failed recommendations. Each entry has a `View changes` button that exports a Markdown audit
document with the per-recommendation result rows and raw JSON result. Skipped manual/template
recommendations include current CMS/rendered frontend state and the proposed target change, so the
download can be used as a local Codex brief before committing template changes.

Before any metadata, content, image-alt, indexing or dynamic-schema write, the previous values are
stored in `tx_seoassistant_recommendation_rollback`. The backend module exposes these snapshots and
adds rollback buttons for a single recommendation or a full apply-history run.

The same backend area also has a `Generate fresh recommendations` button. It runs the equivalent of
the page snapshot, rendered snapshot and recommendation generation commands as a queued backend job
with configurable render/recommendation/AI limits. Run the worker from cron or manually:

```bash
vendor/bin/typo3 seo:jobs:run --limit=5
```

The backend shows recent queued jobs with status, attempts, start/finish times and failure messages.

Metadata recommendations update `pages.seo_title` and `pages.description`. Direct CLI content-gap
recommendations create a hidden `seo_text` element by default:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes
```

To publish the created content immediately, pass `--publish-content`. Publishing requires a passed
recommendation quality check unless `--force` is also passed:

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

AI suggestions are validated before storage. Guardrails reject unsafe rows for overlong metadata,
unsupported ranking/performance claims, obvious keyword stuffing, missing internal-link targets and
invalid structured-data JSON previews. Repeated local keyword usage is stored as a warning so it can
still be reviewed. The quality result is stored with the recommendation as `quality_status`,
`quality_score` and `quality_json`.

Applied recommendation impact can be evaluated after enough Search Console data exists:

```bash
vendor/bin/typo3 seo:recommendations:evaluate-impact --sync --stage=first
```

Stage presets keep early signals separate from stronger/final evaluations:

- `early`: 14-day early signal, 7-day window after a 7-day buffer
- `first`: 35-day first evaluation, 28-day window after a 7-day buffer
- `stronger`: 63-day stronger evaluation, 56-day window after a 7-day buffer
- `final`: 90-day final evaluation, 83-day window after a 7-day buffer

The command stores clicks, impressions, CTR, average position, exact date windows, the
`evaluation_stage`, a rule-based status, and an optional OpenAI explanation in
`tx_seoassistant_impact_evaluation`.
Rows are shown in `Web > SEO Assistant` under `Impact Evaluations`.
Each evaluation also stores experiment diagnostics in `evidence_json`: sample quality, traffic
balance, confidence cap, and explicit limitations such as no holdout/control group and unisolated
seasonality or site changes.

Structured-data recommendations are stored in `tx_seoassistant_structured_data` and rendered by the
site package JSON-LD renderer. After deploying this feature, run TYPO3 extension setup/database
analysis once so the structured-data, apply-history, AI-call, impact-evaluation, rollback, alert
and job tables exist.

## Tests

Run the dependency-free SEO Assistant lifecycle checks with:

```bash
composer test:seo-assistant
```

The checks cover deterministic AI guardrails, early/final impact stage behavior, and source-level
contracts for apply, verify and evaluate lifecycle transitions.
