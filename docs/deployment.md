# WALDBYTE TYPO3 Deployment

Production currently lives at:

```text
/var/www/waldbytenew
```

The atomic release layout should be:

```text
/var/www/waldbytenew/
  current -> releases/20260704123456
  releases/
    20260704123456/
    20260704120000/
  shared/
    config/system/settings.php
    config/system/additional.php
    public/fileadmin/
    public/typo3temp/
    var/charset/
    var/labels/
    var/lock/
    var/log/
    var/session/
    var/transient/
```

The web server document root must point to:

```text
/var/www/waldbytenew/current/public
```

## What Goes Into `shared`

Put these files in `shared` because they are environment-specific or contain credentials:

```text
shared/config/system/settings.php
shared/config/system/additional.php
```

Put these directories in `shared` because they contain editor uploads or runtime data that must survive releases:

```text
shared/public/fileadmin
shared/public/typo3temp
shared/var/charset
shared/var/labels
shared/var/lock
shared/var/log
shared/var/session
shared/var/transient
```

Do not put these in `shared`:

```text
vendor
public/_assets
var/cache
packages
config/sites
composer.json
composer.lock
public/index.php
public/typo3
```

Reason:

- `vendor` is Composer-managed and installed per release.
- `public/_assets` is generated from Composer packages/extensions.
- `var/cache` is generated and should be rebuilt, not preserved.
- `packages`, `config/sites`, Composer files, and public entrypoints are deployable code/config and should come from Git.

## First-Time Migration From Existing Live Folder

Run this on the server before the first atomic deploy. Adjust ownership after copying if your deploy/web user differs.

```bash
cd /var/www
mv waldbytenew waldbytenew_legacy
mkdir -p waldbytenew/shared/config/system
mkdir -p waldbytenew/shared/public
mkdir -p waldbytenew/shared/var

cp -a waldbytenew_legacy/config/system/settings.php waldbytenew/shared/config/system/settings.php
if [ -f waldbytenew_legacy/config/system/additional.php ]; then
  cp -a waldbytenew_legacy/config/system/additional.php waldbytenew/shared/config/system/additional.php
else
  touch waldbytenew/shared/config/system/additional.php
fi

cp -a waldbytenew_legacy/public/fileadmin waldbytenew/shared/public/fileadmin
cp -a waldbytenew_legacy/public/typo3temp waldbytenew/shared/public/typo3temp

mkdir -p waldbytenew/shared/var/charset
mkdir -p waldbytenew/shared/var/labels
mkdir -p waldbytenew/shared/var/lock
mkdir -p waldbytenew/shared/var/log
mkdir -p waldbytenew/shared/var/session
mkdir -p waldbytenew/shared/var/transient

if [ -d waldbytenew_legacy/var/charset ]; then cp -a waldbytenew_legacy/var/charset/. waldbytenew/shared/var/charset/; fi
if [ -d waldbytenew_legacy/var/labels ]; then cp -a waldbytenew_legacy/var/labels/. waldbytenew/shared/var/labels/; fi
if [ -d waldbytenew_legacy/var/log ]; then cp -a waldbytenew_legacy/var/log/. waldbytenew/shared/var/log/; fi
if [ -d waldbytenew_legacy/var/session ]; then cp -a waldbytenew_legacy/var/session/. waldbytenew/shared/var/session/; fi
if [ -d waldbytenew_legacy/var/transient ]; then cp -a waldbytenew_legacy/var/transient/. waldbytenew/shared/var/transient/; fi
```

After the first successful deploy, keep `waldbytenew_legacy` until you have checked the website and backend.

## Required Server Tools

The production server needs:

- PHP 8.2 CLI and PHP-FPM/web runtime
- Composer 2
- rsync
- unzip
- MariaDB/MySQL access configured in `shared/config/system/settings.php`
- a deploy user with write access to `/var/www/waldbytenew`

Do not deploy with `root`. Create a `deploy` user and make the web server user/group able to write shared assets and TYPO3 runtime folders.

## GitHub Secrets

Create these repository or environment secrets:

```text
DEPLOY_HOST=waldbyte.de
DEPLOY_USER=deploy
DEPLOY_PORT=22
DEPLOY_PATH=/var/www/waldbytenew
SSH_PRIVATE_KEY=<private key for the deploy user>
```

Optional:

```text
DEPLOY_PHP=/usr/bin/php8.2
DEPLOY_COMPOSER=/usr/local/bin/composer
KEEP_RELEASES=5
TYPO3_SETTINGS_PHP_B64=<base64 encoded config/system/settings.php>
TYPO3_ADDITIONAL_PHP_B64=<base64 encoded config/system/additional.php>
```

If `TYPO3_SETTINGS_PHP_B64` is provided, the workflow writes it to:

```text
$DEPLOY_PATH/shared/config/system/settings.php
```

Create the secret from a local file with:

```bash
base64 -w0 config/system/settings.php
```

On macOS:

```bash
base64 -i config/system/settings.php | tr -d '\n'
```

## Deployment Flow

1. GitHub Actions checks out the repository.
2. Composer installs deployment dependencies.
3. The workflow connects to the server over SSH.
4. Deployer creates a new release under `releases/`.
5. Code is uploaded with rsync.
6. Shared files/folders are symlinked into the release.
7. Composer installs production dependencies on the server.
8. The deploy prepares TYPO3 runtime folders, then runs `extension:setup` and cache warmup.
9. `current` is switched atomically to the new release.
10. TYPO3 cache is flushed and old releases are cleaned.

The rsync excludes intentionally only block root-level SQL dumps (`/*.sql`, `/*.sql.gz`). Do not use a broad `*.sql` exclude here, because TYPO3 extension schema files such as `packages/site_package/ext_tables.sql` must reach the release for `extension:setup` to create or update custom columns.

## Schema Repair

If live reports an unknown `fieldname` column for `tx_sitepackage_*` tables, first deploy a release containing the extension `ext_tables.sql` files, then run:

```bash
cd /var/www/waldbytenew/current
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

## Rollback

From a machine with SSH access and project dependencies installed:

```bash
php vendor/deployer/deployer/bin/dep rollback production -f Build/deploy.php
```

Rollback only flips the `current` symlink back to the previous release. Database changes are not rolled back automatically.
