<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\DigestSchedule;
use RoundlyConsulting\Notifications\Support\Durations;
use RoundlyConsulting\Notifications\Support\QuietHoursClock;

function utc(string $instant): CarbonImmutable
{
    return CarbonImmutable::parse($instant, 'UTC');
}

it('knows quiet hours, crossing midnight or not', function (string $now, string $start, string $end, bool $quiet): void {
    expect(QuietHoursClock::isQuiet(utc($now), 'UTC', new QuietHours($start, $end)))->toBe($quiet);
})->with([
    ['2026-11-09 23:30', '22:00', '07:00', true],
    ['2026-11-09 06:59', '22:00', '07:00', true],
    ['2026-11-09 07:00', '22:00', '07:00', false],
    ['2026-11-09 12:00', '22:00', '07:00', false],
    ['2026-11-09 12:00', '09:00', '17:00', true],
    ['2026-11-09 17:00', '09:00', '17:00', false],
]);

it('finds the end of the quiet window in the user\'s timezone', function (string $now, string $timezone, string $end): void {
    expect(QuietHoursClock::end(utc($now), $timezone, new QuietHours('22:00', '07:00'))->format('Y-m-d H:i'))->toBe($end);
})->with([
    'before midnight' => ['2026-11-09 22:30', 'UTC', '2026-11-10 07:00'],
    'after midnight' => ['2026-11-10 02:00', 'UTC', '2026-11-10 07:00'],
    'bratislava' => ['2026-11-09 22:30', 'Europe/Bratislava', '2026-11-10 06:00'],
    'new york' => ['2026-11-10 04:00', 'America/New_York', '2026-11-10 12:00'],
]);

it('moves a quiet end inside a DST gap forward (C-42)', function (): void {
    // Europe/Bratislava springs forward at 02:00 local on 2026-03-29: 02:30 does not exist.
    $end = QuietHoursClock::end(utc('2026-03-28 22:00'), 'Europe/Bratislava', new QuietHours('22:00', '02:30'));

    expect($end->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-03-29 03:30');
});

it('sends a digest slot on the fall-back day once (C-43)', function (): void {
    // America/New_York falls back at 02:00 local on 2026-11-01: 01:30 happens twice.
    [$first, $period] = DigestSchedule::next(utc('2026-11-01 04:00'), 'America/New_York', DigestFrequency::Daily, '01:30', null);
    [$second, $again] = DigestSchedule::next($first->addMinute(), 'America/New_York', DigestFrequency::Daily, '01:30', null);

    expect($period)->toBe('2026-11-01')
        ->and($first->format('Y-m-d H:i'))->toBe('2026-11-01 05:30')
        ->and($again)->toBe('2026-11-02')
        ->and($second->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-02 01:30');
});

it('computes daily and weekly slots and periods', function (): void {
    config()->set('notifications.digest.weekly_day', 'friday');

    [$daily, $dailyPeriod] = DigestSchedule::next(utc('2026-11-09 09:00'), 'UTC', DigestFrequency::Daily, '08:00', null);
    [$weekly, $weeklyPeriod] = DigestSchedule::next(utc('2026-11-09 09:00'), 'UTC', DigestFrequency::Weekly, '08:00', null);
    [$sameDay] = DigestSchedule::next(utc('2026-11-13 07:00'), 'UTC', DigestFrequency::Weekly, '08:00', null);

    expect($daily->format('Y-m-d H:i'))->toBe('2026-11-10 08:00')
        ->and($dailyPeriod)->toBe('2026-11-10')
        ->and($weekly->format('Y-m-d H:i'))->toBe('2026-11-13 08:00')
        ->and($weeklyPeriod)->toBe('2026-W46')
        ->and($sameDay->format('Y-m-d H:i'))->toBe('2026-11-13 08:00')
        ->and(DigestSchedule::flushPeriod(utc('2026-11-09 09:05')))->toBe('off-202611090905');
});

it('moves a digest slot inside quiet hours to their end, keeping the period', function (): void {
    [$due, $period] = DigestSchedule::next(utc('2026-11-09 09:00'), 'UTC', DigestFrequency::Daily, '06:00', new QuietHours('22:00', '07:00'));

    expect($due->format('Y-m-d H:i'))->toBe('2026-11-10 07:00')->and($period)->toBe('2026-11-10');
});

it('keeps the local time of a calendar offset across DST (C-78)', function (): void {
    config()->set('app.timezone', 'Europe/Bratislava');

    // Anchor 2026-04-02 09:00 local (CEST, UTC+2); 7 days before is 2026-03-26 09:00 local (CET, UTC+1).
    $anchor = CarbonImmutable::parse('2026-04-02 09:00', 'Europe/Bratislava')->utc();

    expect(ReminderRule::dueAt($anchor, '-P7D')->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-03-26 09:00')
        ->and(ReminderRule::dueAt($anchor, '-PT1H')->format('Y-m-d H:i'))->toBe('2026-04-02 06:00')
        ->and(ReminderRule::dueAt($anchor, 'P1DT1H')->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-04-03 10:00');
});

it('reads durations strictly or leniently', function (): void {
    expect(Durations::iso('PT5M')?->i)->toBe(5)
        ->and(Durations::iso('5 minutes'))->toBeNull()
        ->and(Durations::lenient('10 minutes')?->i)->toBe(10)
        ->and(Durations::lenient('PT0S'))->toBeNull()
        ->and(Durations::lenient('garbage'))->toBeNull()
        ->and(Durations::seconds(Durations::iso('P1D')))->toBe(86400)
        ->and(Durations::toIso(Durations::iso('P1Y2M3DT4H5M6S')))->toBe('P1Y2M3DT4H5M6S')
        ->and(Durations::toIso(CarbonInterval::seconds(0)))->toBe('PT0S');
});

it('treats an unparsable or zero CarbonInterval as no duration', function (): void {
    expect(Durations::iso('P99999999999999999999D'))->toBeNull()
        ->and(Durations::lenient('P99999999999999999999D'))->toBeNull()
        ->and(Durations::lenient(CarbonInterval::seconds(0)))->toBeNull()
        ->and(Durations::lenient(CarbonInterval::minutes(5))?->i)->toBe(5);
});

it('applies month offsets without overflow', function (string $anchor, string $offset, string $due): void {
    expect(ReminderRule::dueAt(CarbonImmutable::parse($anchor, 'UTC'), $offset)->format('Y-m-d H:i'))->toBe($due);
})->with([
    'a month before 31 March' => ['2026-03-31 09:00:00', '-P1M', '2026-02-28 09:00'],
    'a month after 31 January' => ['2026-01-31 09:00:00', 'P1M', '2026-02-28 09:00'],
    'a month before 31 May' => ['2026-05-31 09:00:00', '-P1M', '2026-04-30 09:00'],
    'a year after 29 February' => ['2028-02-29 09:00:00', 'P1Y', '2029-02-28 09:00'],
    'a year before 29 February' => ['2028-02-29 09:00:00', '-P1Y', '2027-02-28 09:00'],
    'a month and a day before 31 March' => ['2026-03-31 09:00:00', '-P1M1D', '2026-02-27 09:00'],
    'days are unchanged' => ['2026-03-31 09:00:00', '-P30D', '2026-03-01 09:00'],
    'time stays absolute' => ['2026-03-31 09:00:00', '-P1MT2H', '2026-02-28 07:00'],
]);
