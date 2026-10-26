<?php

use Tiknil\Backups\Core\FileSource;

it('builds the destination path and the scope', function (array $config, string $prefix, string $scope, string $path) {
    $source = FileSource::fromConfig($config);

    expect($source)->not->toBeNull()
        ->and($source->scope())->toBe($scope)
        ->and($source->destinationPath($prefix))->toBe($path);
})->with([
    'disk only' => [['disk' => 's3'], 'files', 's3', 'files/s3'],
    'disk and folder' => [['disk' => 's3', 'folder' => 'blog'], 'files', 's3/blog', 'files/s3/blog'],
    'folder with slashes' => [['disk' => 's3', 'folder' => '/blog/'], 'files', 's3/blog', 'files/s3/blog'],
    'prefix with slashes' => [['disk' => 's3', 'folder' => 'blog'], '/media/', 's3/blog', 'media/s3/blog'],
]);

it('ignores entries without a disk name', function (array $config) {
    expect(FileSource::fromConfig($config))->toBeNull();
})->with([
    'empty' => [[]],
    'null' => [['disk' => null]],
    'blank' => [['disk' => '']],
    'not a string' => [['disk' => ['s3']]],
]);
