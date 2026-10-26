<?php

use Tiknil\Backups\Core\DiffReport;

it('counts and streams changes and deletions separately', function () {
    $report = new DiffReport;
    $report->append(['status' => '+', 'path' => 'new.txt']);
    $report->append(['status' => '-', 'path' => 'removed.txt']);
    $report->append(['status' => '*', 'path' => 'folder/è "changed".txt']);

    expect($report->countChanged())->toBe(2)
        ->and($report->countDeleted())->toBe(1)
        ->and(iterator_to_array($report->changed()))->toBe(['new.txt', 'folder/è "changed".txt'])
        ->and(iterator_to_array($report->deleted()))->toBe(['removed.txt'])
        ->and(iterator_to_array($report->changed()))->toBe(['new.txt', 'folder/è "changed".txt']);
});

it('handles an empty report', function () {
    $report = new DiffReport;

    expect($report->countChanged())->toBe(0)
        ->and($report->countDeleted())->toBe(0)
        ->and(iterator_to_array($report))->toBe([]);
});

it('removes the temporary file when closed or released', function () {
    $report = new DiffReport;
    $property = new ReflectionProperty(DiffReport::class, 'stream');
    $path = stream_get_meta_data($property->getValue($report))['uri'];

    expect(is_file($path))->toBeTrue();

    $report->close();

    // Svuoto la cache per il file, altrimenti potrei ottenere risultati "stantiti"
    clearstatcache(true, $path);

    expect(is_file($path))->toBeFalse();

    $report = new DiffReport;
    $path = stream_get_meta_data($property->getValue($report))['uri'];
    unset($report);

    expect(is_file($path))->toBeFalse();
});

it('rejects access after closing', function () {
    $report = new DiffReport;
    $report->close();

    expect(fn () => iterator_to_array($report))->toThrow(RuntimeException::class, 'closed')
        ->and(fn () => $report->append(['status' => '+', 'path' => 'file']))->toThrow(RuntimeException::class, 'closed');
});

it('streams large reports without retaining entries in memory', function () {
    $report = new DiffReport;
    memory_reset_peak_usage();
    $before = memory_get_usage();

    for ($i = 0; $i < 100000; $i++) {
        $report->append(['status' => $i % 2 === 0 ? '+' : '-', 'path' => "folder/{$i}.txt"]);
    }

    $count = 0;
    foreach ($report->changed() as $path) {
        $count++;
    }
    foreach ($report->deleted() as $path) {
        $count++;
    }

    expect($count)->toBe(100000)
        ->and($report->countChanged())->toBe(50000)
        ->and($report->countDeleted())->toBe(50000)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(2 * 1024 * 1024);
});
