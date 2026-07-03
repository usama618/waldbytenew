<?php

namespace Deployer;

require_once 'recipe/common.php';

$rsyncRecipe = dirname(__DIR__) . '/vendor/deployer/deployer/contrib/rsync.php';
if (!is_file($rsyncRecipe)) {
    throw new \RuntimeException('Missing Deployer rsync recipe. Run composer install with dev dependencies before deploying.');
}
require_once $rsyncRecipe;

set('application', 'waldbyte');
set('default_stage', 'production');
set('keep_releases', (int)(getenv('KEEP_RELEASES') ?: 5));
set('default_timeout', 900);

set('typo3_webroot', 'public');
set('bin/typo3', 'vendor/bin/typo3');
set('bin/php', getenv('DEPLOY_PHP') ?: 'php');
set('bin/composer', getenv('DEPLOY_COMPOSER') ?: 'composer');
set('composer_options', '--no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader');
set('log_files', 'var/log/typo3_*.log');

host('production')
    ->set('hostname', getenv('DEPLOY_HOST') ?: 'waldbyte.de')
    ->set('remote_user', getenv('DEPLOY_USER') ?: 'deploy')
    ->set('port', (int)(getenv('DEPLOY_PORT') ?: 22))
    ->set('deploy_path', getenv('DEPLOY_PATH') ?: '/var/www/waldbytenew')
    ->set('writable_mode', getenv('WRITABLE_MODE') ?: 'chmod');

set('shared_dirs', [
    '{{typo3_webroot}}/fileadmin',
    '{{typo3_webroot}}/typo3temp',
    'var/charset',
    'var/labels',
    'var/lock',
    'var/log',
    'var/session',
    'var/transient',
]);

set('shared_files', [
    'config/system/settings.php',
    'config/system/additional.php',
]);

set('writable_dirs', [
    '{{typo3_webroot}}/fileadmin',
    '{{typo3_webroot}}/typo3temp',
    'var',
]);

set('clear_paths', [
    '.ddev',
    '.github',
    '.gitignore',
    'Build',
    'hobby-db-backup-*.sql',
    'waldbytefinal.sql',
]);

set('rsync_src', dirname(__DIR__));
set('rsync_dest', '{{release_path}}');
set('rsync', [
    'exclude' => [
        '.DS_Store',
        '.ddev',
        '.dep',
        '.git',
        '.github',
        '.idea',
        'Build/servers*.yml',
        'db-dumps',
        'hobby-db-backup-*.sql',
        'node_modules',
        'public/_assets',
        'public/fileadmin',
        'public/typo3temp',
        'var',
        'vendor',
        'waldbytefinal.sql',
        '*.sql',
        '*.sql.gz',
        '*.zip',
    ],
    'exclude-file' => false,
    'include' => [],
    'include-file' => false,
    'filter' => [],
    'filter-file' => false,
    'filter-perdir' => false,
    'flags' => 'az',
    'options' => ['delete', 'delete-after', 'force'],
    'timeout' => 900,
]);

task('deploy:check_shared_config', function () {
    $settings = '{{deploy_path}}/shared/config/system/settings.php';

    if (!test("[ -s $settings ]")) {
        throw new \RuntimeException(
            'Missing shared TYPO3 config. Create ' . $settings . ' or provide TYPO3_SETTINGS_PHP_B64 in CI.'
        );
    }
});

desc('TYPO3 extension setup');
task('typo3:extension:setup', function () {
    cd('{{release_path}}');
    run('{{bin/php}} {{bin/typo3}} extension:setup');
});

desc('TYPO3 folder structure check');
task('typo3:fixfolderstructure', function () {
    cd('{{release_path}}');
    run('{{bin/php}} {{bin/typo3}} install:fixfolderstructure');
});

desc('TYPO3 system cache warmup');
task('typo3:cache:warmup', function () {
    cd('{{release_path}}');
    run('{{bin/php}} {{bin/typo3}} cache:warmup --group system');
});

desc('TYPO3 cache flush after release switch');
task('typo3:cache:flush', function () {
    cd('{{current_path}}');
    run('{{bin/php}} {{bin/typo3}} cache:flush');
});

desc('Deploys WALDBYTE TYPO3 with atomic releases');
task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:check_shared_config',
    'deploy:lock',
    'deploy:release',
    'rsync',
    'deploy:shared',
    'deploy:writable',
    'deploy:vendors',
    'typo3:fixfolderstructure',
    'typo3:extension:setup',
    'typo3:cache:warmup',
    'deploy:clear_paths',
    'deploy:symlink',
    'deploy:unlock',
    'typo3:cache:flush',
    'deploy:cleanup',
    'deploy:success',
]);

after('deploy:failed', 'deploy:unlock');
