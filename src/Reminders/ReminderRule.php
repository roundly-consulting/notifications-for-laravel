<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Reminders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Durations;

/**
 * A reminder rule, built with `Reminder::before()` / `after()` / `at()` and the fluent methods.
 */
final class ReminderRule
{
    private ?string $key = null;

    private RecipientSpec|Closure|null $to = null;

    private ?Closure $condition = null;

    /** @var list<EscalationRule> */
    private array $escalations = [];

    private bool $acknowledgedOnRead = false;

    private bool $rearm = true;

    /**
     * @param  'before'|'after'|'at'  $direction
     * @param  list<CarbonInterval|string>  $offsets
     */
    public function __construct(
        public readonly string $direction,
        public readonly ?string $attribute,
        public readonly array $offsets,
        public readonly ?CarbonInterface $at = null,
    ) {}

    /**
     * A stable rule key (default `{attribute}:{direction}`). Two rules on one attribute and
     * direction need distinct keys.
     */
    public function key(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Who is reminded. Rules without recipients are templates for `schedule($type, to: …)`.
     *
     * @param  RecipientSpec|Model|Closure|iterable<mixed>  $recipients
     */
    public function to(RecipientSpec|Model|Closure|iterable $recipients): self
    {
        $this->to = $recipients instanceof Closure ? $recipients : Recipients::from($recipients);

        return $this;
    }

    /**
     * fn (Model $subject): bool — evaluated at fire time with the fresh subject; false cancels.
     */
    public function while(Closure $condition): self
    {
        $this->condition = $condition;

        return $this;
    }

    /**
     * If not acknowledged `after` the reminder went out, notify `to`. Repeat for more levels.
     */
    /**
     * @param  RecipientSpec|Model|Closure|iterable<mixed>  $to
     */
    public function escalate(CarbonInterval|string $after, RecipientSpec|Model|Closure|iterable $to, ?string $type = null): self
    {
        $this->escalations[] = new EscalationRule(Durations::lenient($after) ?? CarbonInterval::seconds(0), $to instanceof Closure ? $to : Recipients::from($to), $type);

        return $this;
    }

    /**
     * Reading the reminder's inbox item acknowledges it (and stops its escalation).
     */
    public function acknowledgedOnRead(bool $on = true): self
    {
        $this->acknowledgedOnRead = $on;

        return $this;
    }

    /**
     * Re-arm sent reminders when the anchor moves later (a renewal). On by default.
     */
    public function rearm(bool $rearm = true): self
    {
        $this->rearm = $rearm;

        return $this;
    }

    public function ruleKey(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        return $this->direction === 'at'
            ? 'at:'.($this->at === null ? '-' : Clock::format($this->at))
            : $this->attribute.':'.$this->direction;
    }

    public function hasExplicitKey(): bool
    {
        return $this->key !== null;
    }

    public function recipients(): RecipientSpec|Closure|null
    {
        return $this->to;
    }

    public function condition(): ?Closure
    {
        return $this->condition;
    }

    /**
     * @return list<EscalationRule>
     */
    public function escalations(): array
    {
        return $this->escalations;
    }

    public function isAcknowledgedOnRead(): bool
    {
        return $this->acknowledgedOnRead;
    }

    public function rearms(): bool
    {
        return $this->rearm;
    }

    public function holds(Model $subject): bool
    {
        return $this->condition === null || (bool) ($this->condition)($subject);
    }

    /**
     * The signed ISO offsets this rule stores (`-P7D` before, `P7D` after); null entries are
     * unreadable offsets (refused at registration).
     *
     * @return list<string|null>
     */
    public function signedOffsets(): array
    {
        return array_map(function (CarbonInterval|string $offset): ?string {
            $interval = Durations::lenient($offset);

            if ($interval === null) {
                return null;
            }

            return ($this->direction === 'before' ? '-' : '').Durations::toIso($interval);
        }, $this->offsets);
    }

    /**
     * The due instant (UTC) for an anchor and a signed offset. Time offsets are absolute; calendar
     * offsets are applied on the anchor's wall clock in the app timezone, so "7 days before 09:00"
     * stays 09:00 local across a DST change.
     */
    public static function dueAt(CarbonImmutable $anchor, string $signedOffset): CarbonImmutable
    {
        $before = str_starts_with($signedOffset, '-');
        $interval = Durations::iso(ltrim($signedOffset, '-+')) ?? CarbonInterval::seconds(0);

        $timezone = config('app.timezone');
        $local = $anchor->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');

        $calendar = CarbonInterval::create($interval->y, $interval->m, 0, $interval->d);
        $local = $before ? $local->sub($calendar) : $local->add($calendar);

        $seconds = $interval->h * 3600 + $interval->i * 60 + $interval->s;

        return Clock::utc($local)->addSeconds($before ? -$seconds : $seconds);
    }
}
