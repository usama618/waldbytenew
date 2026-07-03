# TYPO3 Project Base

This repository is now a neutral TYPO3 13 project base for building a new site.

## What remains

- TYPO3 core project structure
- local site package at `packages/site_package`
- generic page rendering setup
- backend layout and RTE preset

## What was removed

- previous site-specific branding and content model
- portfolio/contact/experience custom content elements
- demo frontend template assets

## Next steps

1. Run `composer update` to refresh the lock file for the renamed local package.
2. Configure your site in TYPO3 and set the site title.
3. Build the new design in `packages/site_package/Resources/Private` and `Resources/Public`.
