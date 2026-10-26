<?php

use Tiknil\Backups\Core\PathList;

it('streams the appended paths and counts them', function () {
    $list = new PathList;
    $list->append('folder/new.txt');
    $list->append('folder/è "spaced".txt');
    $list->append('empty.txt');

    expect($list->count())->toBe(3)
        ->and(iterator_to_array($list))->toBe(['folder/new.txt', 'folder/è "spaced".txt', 'empty.txt'])
        // The list can be iterated more than once.
        ->and(iterator_to_array($list))->toHaveCount(3);
});

it('handles an empty list', function () {
    $list = new PathList;

    expect($list->count())->toBe(0)
        ->and(iterator_to_array($list))->toBe([]);
});

it('rejects access after closing', function () {
    $list = new PathList;
    $list->close();

    expect(fn () => $list->append('file.txt'))->toThrow(RuntimeException::class, 'closed')
        ->and(fn () => iterator_to_array($list))->toThrow(RuntimeException::class, 'closed');
});

it('removes the temporary file when released', function () {
    $list = new PathList;
    $property = new ReflectionProperty(PathList::class, 'stream');
    $path = stream_get_meta_data($property->getValue($list))['uri'];

    unset($list);

    clearstatcache(true, $path);

    expect(is_file($path))->toBeFalse();
});

it('streams large lists without retaining paths in memory', function () {
    $list = new PathList;
    memory_reset_peak_usage();
    $before = memory_get_usage();

    for ($i = 0; $i < 100000; $i++) {
        $list->append("folder/{$i}.txt");
    }

    $count = 0;
    foreach ($list as $path) {
        $count++;
    }

    expect($count)->toBe(100000)
        ->and($list->count())->toBe(100000)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(2 * 1024 * 1024);
});
