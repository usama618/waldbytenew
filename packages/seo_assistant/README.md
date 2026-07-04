# SEO Assistant

This TYPO3 extension imports Google Search Console data, snapshots TYPO3 page SEO fields and content, and creates reviewable SEO recommendations.

## Environment variables

Set these on the live server or in DDEV, not in Git:

- `SEO_ASSISTANT_GSC_SITE_URL`: Search Console property, for example `sc-domain:waldbyte.de` or `https://waldbyte.de/`
- `SEO_ASSISTANT_GOOGLE_CLIENT_ID`
- `SEO_ASSISTANT_GOOGLE_CLIENT_SECRET`
- `SEO_ASSISTANT_GOOGLE_REFRESH_TOKEN`
- `SEO_ASSISTANT_BASE_URL`: public base URL used for page snapshots, for example `https://waldbyte.de/`
- `SEO_ASSISTANT_OPENAI_API_KEY`: optional, enables AI text suggestions
- `SEO_ASSISTANT_OPENAI_MODEL`: optional but required for AI mode, for example the model you want to use with the OpenAI Responses API

## Commands

```bash
vendor/bin/typo3 seo:gsc:sync
vendor/bin/typo3 seo:pages:snapshot
vendor/bin/typo3 seo:recommendations:generate
```

Use `--dry-run` on sync/snapshot commands to see what would be processed without writing rows.

Recommendations are stored as drafts. Applying a recommendation is intentionally manual:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes
```

Only `pages.seo_title` and `pages.description` are updated by the apply command.
