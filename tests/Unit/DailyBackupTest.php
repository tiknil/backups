<?php

use Spatie\Backup\Config\Config as SpatieConfig;
use Spatie\DbDumper\Compressors\Bzip2Compressor;
use Tiknil\Backups\Commands\DailyBackup;

function backupConfigFor(string $path): SpatieConfig
{
    $method = new ReflectionMethod(DailyBackup::class, 'backupConfig');

    return $method->invoke(new DailyBackup, $path);
}

it('keeps the package defaults', function () {
    config(['backup' => require dirname(__DIR__, 2).'/vendor/spatie/laravel-backup/config/backup.php']);

    $config = backupConfigFor('daily/2026-01-01');

    expect($config->backup->destination->disks)->toBe(['backups'])
        ->and($config->backup->source->databases)->toBe(['mysql'])
        ->and($config->backup->databaseDumpCompressor)->toBe(Bzip2Compressor::class);
});

it('overrides the spatie config with the package one', function () {
    config(['backup' => require dirname(__DIR__, 2).'/vendor/spatie/laravel-backup/config/backup.php']);
    config([
        'tk-backups.laravel-backups.backup.password' => 'secret',
        'tk-backups.laravel-backups.backup.source.databases' => ['sqlite'],
    ]);

    $config = backupConfigFor('daily/2026-01-01');

    expect($config->backup->password)->toBe('secret')
        ->and($config->backup->source->databases)->toBe(['sqlite'])
        ->and($config->backup->databaseDumpCompressor)->toBe(Bzip2Compressor::class);
});

it('uses the daily path as backup name', function () {
    config(['backup' => require dirname(__DIR__, 2).'/vendor/spatie/laravel-backup/config/backup.php']);

    expect(backupConfigFor('daily/2026-01-01')->backup->name)->toBe('daily/2026-01-01');
});
