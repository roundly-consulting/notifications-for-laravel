<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Reminders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Durations;

/**
 * A reminder rule, built with `Reminder::before()` / `after()` / `at()` / `every()` and the fluent
 * methods.
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

    /** Repeating rules only (`Reminder::every()`). */
    private ?CarbonInterval $every = null;

    private ?string $from = null;

    private ?CarbonInterval $startingAfter = null;

    private ?int $times = null;

    private CarbonInterface|Closure|null $until = null;

    /**
     * @param  'before'|'after'|'at'|'every'  $direction
     * @param  list<CarbonInterval|string>  $offsets
     */
    public function __construct(
        public readonly string $direction,
        public readonly ?string $attribute,
        public readonly array $offsets,
        public readonly ?CarbonInterface $at = null,
    ) {}

    /**
     * @internal build it with `Reminder::every()`
     *
     * @throws InvalidReminderRuleException
     */
    public static function repeating(CarbonInterval|string $interval): self
    {
        $rule = new self('every', null, []);
        $rule->every = Durations::lenient($interval) ?? throw InvalidReminderRuleException::invalidRepeat(sprintf('the interval "%s" is not a positive duration such as P7D or "7 days"', self::describe($interval)));

        return $rule;
    }

    /**
     * Repeating rules: anchor the chain to a date attribute of the subject (default: when the
     * chain was scheduled). A later anchor (a republished policy) re-arms ended chains; a moved
     * one re-times the pending reminder.
     *
     * @throws InvalidReminderRuleException on a one-off rule
     */
    public function from(string $attribute): self
    {
        $this->repeatOnly('from');
        $this->from = $attribute;

        return $this;
    }

    /**
     * Repeating rules: the first reminder goes out this long after the anchor (default: one
     * interval). Zero sends the first one at the anchor.
     *
     * @throws InvalidReminderRuleException on a one-off rule or an unreadable / negative delay
     */
    public function startingAfter(CarbonInterval|string $delay): self
    {
        $this->repeatOnly('startingAfter');

        try {
            $interval = $delay instanceof CarbonInterval ? $delay : (Durations::iso($delay) ?? CarbonInterval::make($delay));
        } catch (\Throwable) {
            $interval = null;
        }

        if (! $interval instanceof CarbonInterval || Durations::seconds($interval) < 0) {
            throw InvalidReminderRuleException::invalidRepeat(sprintf('the first delay "%s" is not a duration of zero or more', self::describe($delay)));
        }

        $this->startingAfter = $interval;

        return $this;
    }

    /**
     * Repeating rules: at most `$max` reminders per recipient.
     *
     * @throws InvalidReminderRuleException on a one-off rule or a max below one
     */
    public function times(int $max): self
    {
        $this->repeatOnly('times');

        if ($max < 1) {
            throw InvalidReminderRuleException::invalidRepeat('times() needs at least 1');
        }

        $this->times = $max;

        return $this;
    }

    /**
     * Repeating rules: no reminder due after this instant — or `fn (Model $subject): ?CarbonInterface`,
     * read whenever the next reminder is scheduled (null: no end).
     *
     * @throws InvalidReminderRuleException on a one-off rule
     */
    public function until(CarbonInterface|Closure $end): self
    {
        $this->repeatOnly('until');
        $this->until = $end;

        return $this;
    }

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
     * An unreadable or non-positive delay throws here. Type rules only: an ad-hoc reminder
     * refuses a rule with an escalation.
     *
     * On a repeating rule each level is armed once per recipient chain: a delay counts from the
     * chain's first reminder; an int `$after` escalates after that many reminders went out
     * unacknowledged — one interval after the last of them.
     *
     * @param  RecipientSpec|Model|Closure|iterable<mixed>  $to
     *
     * @throws InvalidReminderRuleException
     */
    public function escalate(CarbonInterval|string|int $after, RecipientSpec|Model|Closure|iterable $to, ?string $type = null): self
    {
        $to = $to instanceof Closure ? $to : Recipients::from($to);

        if (is_int($after)) {
            if ($this->every === null) {
                throw InvalidReminderRuleException::invalidRepeat('escalating after a number of reminders needs Reminder::every()');
            }

            if ($after < 1) {
                throw InvalidReminderRuleException::invalidRepeat('escalate() after a number of reminders needs at least 1');
            }

            $this->escalations[] = new EscalationRule($this->every, $to, $type, $after);

            return $this;
        }

        $this->escalations[] = new EscalationRule(Durations::lenient($after) ?? throw InvalidReminderRuleException::invalidDelay($after), $to, $type);

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

        return match ($this->direction) {
            'at' => 'at:'.($this->at === null ? '-' : Clock::format($this->at)),
            'every' => ($this->from ?? 'scheduled').':every',
            default => $this->attribute.':'.$this->direction,
        };
    }

    public function repeats(): bool
    {
        return $this->every !== null;
    }

    /**
     * The repeat interval as ISO-8601 (`P7D`); null on a one-off rule.
     */
    public function interval(): ?string
    {
        return $this->every === null ? null : Durations::toIso($this->every);
    }

    /**
     * The date attribute a repeating chain is anchored to; null when it is anchored to when it
     * was scheduled.
     */
    public function anchorAttribute(): ?string
    {
        return $this->from;
    }

    public function maxTimes(): ?int
    {
        return $this->times;
    }

    /**
     * The instant after which a repeating chain sends nothing more, for this subject.
     */
    public function endFor(Model $subject): ?CarbonImmutable
    {
        $end = $this->until instanceof Closure ? ($this->until)($subject) : $this->until;

        return $end instanceof CarbonInterface ? Clock::utc($end) : null;
    }

    /**
     * The first reminder of a repeating chain due strictly after `$after`: the anchor plus the
     * first delay plus whole intervals, on the anchor's wall clock (see dueAt()). Slots are
     * always counted from the anchor, so a late sweep never drifts the chain or sends a burst.
     */
    public function nextDue(CarbonImmutable $anchor, CarbonImmutable $after): CarbonImmutable
    {
        $first = self::dueAt($anchor, $this->slotOffset(0));

        if ($first->greaterThan($after)) {
            return $first;
        }

        $step = max(1, Durations::seconds($this->every ?? CarbonInterval::seconds(1)));
        $k = max(1, intdiv($after->getTimestamp() - $first->getTimestamp(), $step));

        while ($k > 1 && self::dueAt($anchor, $this->slotOffset($k - 1))->greaterThan($after)) {
            $k--;
        }

        while (! ($due = self::dueAt($anchor, $this->slotOffset($k)))->greaterThan($after)) {
            $k++;
        }

        return $due;
    }

    /**
     * The anchor-relative offset of slot `$k`: the first delay plus `$k` intervals.
     */
    private function slotOffset(int $k): string
    {
        $start = $this->startingAfter ?? $this->every ?? CarbonInterval::seconds(0);
        $every = $this->every ?? CarbonInterval::seconds(0);

        return Durations::toIso(CarbonInterval::create(
            $start->y + $k * $every->y,
            $start->m + $k * $every->m,
            0,
            $start->d + $k * $every->d,
            $start->h + $k * $every->h,
            $start->i + $k * $every->i,
            $start->s + $k * $every->s,
        ));
    }

    /**
     * @throws InvalidReminderRuleException
     */
    private function repeatOnly(string $method): void
    {
        if ($this->every === null) {
            throw InvalidReminderRuleException::invalidRepeat($method.'() is for repeating rules (Reminder::every())');
        }
    }

    private static function describe(CarbonInterval|string $value): string
    {
        return $value instanceof CarbonInterval ? $value->spec() : $value;
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
     * stays 09:00 local across a DST change. Months and years clamp to the month's last day.
     */
    public static function dueAt(CarbonImmutable $anchor, string $signedOffset): CarbonImmutable
    {
        $before = str_starts_with($signedOffset, '-');
        $interval = Durations::iso(ltrim($signedOffset, '-+')) ?? CarbonInterval::seconds(0);

        $timezone = config('app.timezone');
        $local = $anchor->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');

        // Months and years without overflow (a month before 31 March is 28 February, not 3 March),
        // then the days.
        $months = $interval->y * 12 + $interval->m;
        $local = $before
            ? $local->subMonthsNoOverflow($months)->subDays($interval->d)
            : $local->addMonthsNoOverflow($months)->addDays($interval->d);

        $seconds = $interval->h * 3600 + $interval->i * 60 + $interval->s;

        return Clock::utc($local)->addSeconds($before ? -$seconds : $seconds);
    }
}
