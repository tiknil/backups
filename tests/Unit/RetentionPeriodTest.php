<?php

use Tiknil\Backups\Core\RetentionPeriod;

it('builds the path of each period', function (RetentionPeriod $period, string $at, string $expected) {
    expect($period->basePath(new DateTimeImmutable($at)))->toBe($expected);
})->with([
    'daily' => [RetentionPeriod::Daily, '2026-03-07', 'daily/2026-03-07'],
    'weekly' => [RetentionPeriod::Weekly, '2026-03-07', 'weekly/2026-W10'],
    'monthly' => [RetentionPeriod::Monthly, '2026-03-07', 'monthly/2026-03'],
    'yearly' => [RetentionPeriod::Yearly, '2026-03-07', 'yearly/2026'],
]);

it('uses the ISO year for weeks spanning two years', function () {
    // 2025-12-29 is the Monday of ISO week 2026-W01.
    expect(RetentionPeriod::Weekly->basePath(new DateTimeImmutable('2025-12-29')))
        ->toBe('weekly/2026-W01');
});

it('reads the retention days from the config', function () {
    expect(RetentionPeriod::Daily->retentionDays())->toBe(8)
        ->and(RetentionPeriod::Weekly->retentionDays())->toBe(35)
        ->and(RetentionPeriod::Monthly->retentionDays())->toBe(100)
        ->and(RetentionPeriod::Yearly->retentionDays())->toBe(400);

    config(['tk-backups.retention_days.daily' => 3]);

    expect(RetentionPeriod::Daily->retentionDays())->toBe(3);
});

it('promotes only weekly, monthly and yearly', function () {
    expect(RetentionPeriod::promotable())->toBe([
        RetentionPeriod::Weekly,
        RetentionPeriod::Monthly,
        RetentionPeriod::Yearly,
    ]);
});
