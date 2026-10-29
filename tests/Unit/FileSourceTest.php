<?php

use Tiknil\Backups\Core\FileSource;

it('builds one source per folder', function (mixed $folder, array $expected) {
    $sources = FileSource::list(['disk' => 's3', 'folder' => $folder]);

    expect(array_map(fn (FileSource $s): string => $s->folder, $sources))->toBe($expected);
})->with([
    'missing' => [null, ['']],
    'blank' => ['', ['']],
    'string' => ['blog', ['blog']],
    'string with slashes' => ['/blog/', ['blog']],
    'list' => [['blog', 'uploads'], ['blog', 'uploads']],
    'list with slashes and duplicates' => [['/blog/', 'uploads', 'blog'], ['blog', 'uploads']],
    'empty list' => [[], ['']],
]);

it('builds the destination path and the scope', function (array $config, string $prefix, string $scope, string $path) {
    $source = FileSource::list($config)[0];

    expect($source->scope())->toBe($scope)
        ->and($source->destinationPath($prefix))->toBe($path);
})->with([
    'disk only' => [['disk' => 's3'], 'files', 's3', 'files/s3'],
    'disk and folder' => [['disk' => 's3', 'folder' => 'blog'], 'files', 's3/blog', 'files/s3/blog'],
    'folder with slashes' => [['disk' => 's3', 'folder' => '/blog/'], 'files', 's3/blog', 'files/s3/blog'],
    'prefix with slashes' => [['disk' => 's3', 'folder' => 'blog'], '/media/', 's3/blog', 'media/s3/blog'],
]);

it('scopes every folder of the same disk', function () {
    $sources = FileSource::list(['disk' => 's3', 'folder' => ['blog', 'uploads']]);

    expect(array_map(fn (FileSource $s): string => $s->destinationPath('files'), $sources))
        ->toBe(['files/s3/blog', 'files/s3/uploads']);
});

it('reads exclude patterns as a string or a list', function (mixed $exclude, array $expected) {
    $sources = FileSource::list(['disk' => 's3', 'exclude' => $exclude]);

    expect($sources[0]->excludes)->toBe($expected);
})->with([
    'missing' => [null, []],
    'string' => ['*.tmp', ['*.tmp']],
    'list' => [['*.tmp', '/cache/**'], ['*.tmp', '/cache/**']],
    'blanks and non strings' => [[' *.tmp ', '', 42], ['*.tmp']],
]);

it('applies the same excludes to every folder', function () {
    $sources = FileSource::list(['disk' => 's3', 'folder' => ['blog', 'uploads'], 'exclude' => 'cache']);

    expect(array_map(fn (FileSource $s): array => $s->excludes, $sources))
        ->toBe([['cache'], ['cache']]);
});

it('ignores entries without a disk name', function (array $config) {
    expect(FileSource::list($config))->toBe([]);
})->with([
    'empty' => [[]],
    'null' => [['disk' => null]],
    'blank' => [['disk' => '']],
    'not a string' => [['disk' => ['s3']]],
]);
