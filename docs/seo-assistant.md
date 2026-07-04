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

AI is optional. If `SEO_ASSISTANT_OPENAI_API_KEY` or `SEO_ASSISTANT_OPENAI_MODEL` is missing, the generator falls back to rule-based recommendations.

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
vendor/bin/typo3 seo:rendered:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:recommendations:generate
```

Open the TYPO3 backend module `Web > SEO Assistant` to review drafts, rendered URL issues, and CMS content snapshots centrally.

## Safe apply flow

Applying is per recommendation and only changes `pages.seo_title` plus `pages.description`:

```bash
vendor/bin/typo3 seo:recommendations:apply --uid=123
vendor/bin/typo3 seo:recommendations:apply --uid=123 --yes
```

The first command is a dry run. The second command writes the metadata.

## Suggested cron

Run this daily or weekly on the live server:

```bash
cd /var/www/waldbytenew/current
vendor/bin/typo3 seo:gsc:sync
vendor/bin/typo3 seo:pages:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:rendered:snapshot --base-url=https://waldbyte.de/
vendor/bin/typo3 seo:recommendations:generate --limit=100 --ai-limit=10
```

The rendered snapshot command crawls only the configured same-host URLs. It uses CMS page snapshots and Search Console page URLs as its source list.
