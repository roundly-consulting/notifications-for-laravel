<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\DefinedType;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Exceptions\DuplicateNotificationTypeException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationTypeException;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * The registered types, per process. Types listed in `notifications.types` load lazily on first
 * use; `Notifications::register()` and `define()` add more. Registration validates without I/O,
 * so `package:discover` works before migrating.
 *
 * @internal
 */
final class TypeRegistry
{
    public const string KEY_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/';

    /** @var array<string, NotificationType> */
    private array $types = [];

    /** @var array<string, string> key => source label */
    private array $sources = [];

    private bool $loaded = false;

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<NotificationType>  ...$classes
     */
    public function register(string ...$classes): void
    {
        $this->load();

        foreach ($classes as $class) {
            $this->add($this->make($class), class_basename($class));
        }
    }

    public function add(NotificationType $type, ?string $source = null): void
    {
        $this->load();

        $this->put($type, $source ?? ($type instanceof DefinedType ? 'define()' : class_basename($type)));

        // Escalation level types may be registered later during boot: those are checked once
        // everything has booted; a type added after that is checked at once.
        if (app()->isBooted()) {
            $this->validateEscalations($type);
        }
    }

    /**
     * Checks the `type:` of every escalation level of the type's rules (or of every type): it is
     * registered and takes the type's subject — or the level would die at fire time.
     */
    public function validateEscalations(?NotificationType $only = null): void
    {
        foreach ($only === null ? $this->all() : [$only] as $type) {
            foreach ($type->reminders() as $rule) {
                foreach ($rule->escalations() as $level) {
                    $subject = $type->subject();

                    if ($level->type !== null && ($subject === null || ! $this->takes($level->type, $subject))) {
                        throw InvalidReminderRuleException::escalationType($type->key(), $level->type);
                    }
                }
            }
        }
    }

    /**
     * Whether a type can be sent about a subject of this class: it is registered and its subject
     * class is that class or a parent of it.
     *
     * @param  class-string<Model>  $subject
     */
    public function takes(string $type, string $subject): bool
    {
        $expected = $this->get($type)?->subject();

        return $expected !== null && is_a($subject, $expected, true);
    }

    public function find(string $key): NotificationType
    {
        return $this->get($key) ?? throw UnknownNotificationTypeException::forKey(str_contains($key, '\\') ? class_basename($key) : $key);
    }

    /**
     * A type by key — or by its class, when a class-string is given.
     */
    public function get(string $key): ?NotificationType
    {
        $this->load();

        if (isset($this->types[$key])) {
            return $this->types[$key];
        }

        if (str_contains($key, '\\') && class_exists($key)) {
            foreach ($this->types as $type) {
                if ($type instanceof $key) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * The key of a type given by key or class; an unregistered value comes back as given.
     */
    public function keyOf(string $type): string
    {
        return $this->get($type)?->key() ?? $type;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Whether a registered type uses the category.
     */
    public function hasCategory(string $category): bool
    {
        foreach ($this->all() as $type) {
            if ($type->category() === $category) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<NotificationType>
     */
    public function all(): array
    {
        $this->load();

        return array_values($this->types);
    }

    /**
     * The types whose subject class is the model's class or one of its parents.
     *
     * @return list<NotificationType>
     */
    public function forSubject(Model $subject): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (NotificationType $type): bool => $type->subject() !== null && is_a($subject, $type->subject()),
        ));
    }

    /**
     * Forget every registration (tests, Octane workers that re-register on boot).
     */
    public function flush(): void
    {
        $this->types = [];
        $this->sources = [];
        $this->loaded = false;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach (NotificationsConfig::types() as $class) {
            $this->put($this->make($class), class_basename($class));
        }
    }

    /**
     * @param  class-string<NotificationType>  $class
     */
    private function make(string $class): NotificationType
    {
        $type = $this->container->make($class);

        if (! $type instanceof NotificationType) {
            throw InvalidNotificationTypeException::invalidKey(class_basename($class));
        }

        return $type;
    }

    private function put(NotificationType $type, string $source): void
    {
        $key = $type->key();

        if (isset($this->types[$key])) {
            throw DuplicateNotificationTypeException::forKey($key, $this->sources[$key], $source);
        }

        $this->validate($type);

        $this->types[$key] = $type;
        $this->sources[$key] = $source;
    }

    private function validate(NotificationType $type): void
    {
        $key = $type->key();

        if (strlen($key) > 100 || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw InvalidNotificationTypeException::invalidKey($key);
        }

        $category = $type->category();

        if (strlen($category) > 100 || preg_match(self::KEY_PATTERN, $category) !== 1) {
            throw InvalidNotificationTypeException::invalidCategory($key, $category);
        }

        if ($type->digestible() && $type->priority() !== Priority::Low) {
            throw InvalidNotificationTypeException::digestibleNotLow($key);
        }

        Windows::of($type);

        $channels = $type->channels();

        if ($channels === []) {
            throw InvalidNotificationTypeException::noChannels($key);
        }

        $subject = $type->subject();

        if ($subject !== null && ! is_subclass_of($subject, Model::class)) {
            throw InvalidNotificationTypeException::invalidSubject($key);
        }

        $this->validateReminders($type);
    }

    private function validateReminders(NotificationType $type): void
    {
        $rules = $type->reminders();

        if ($rules === []) {
            return;
        }

        $key = $type->key();

        if ($type->subject() === null) {
            throw InvalidReminderRuleException::invalid($key, 'reminders need a subject() class');
        }

        $seen = [];

        foreach ($rules as $rule) {
            if ($rule->direction === 'at') {
                throw InvalidReminderRuleException::invalid($key, 'Reminder::at() is for ad-hoc schedules only');
            }

            if ($rule->attribute === null || trim($rule->attribute) === '') {
                throw InvalidReminderRuleException::invalid($key, 'the anchor attribute is empty');
            }

            if ($rule->offsets === [] || count($rule->offsets) > 25) {
                throw InvalidReminderRuleException::invalid($key, 'a rule needs between 1 and 25 offsets');
            }

            foreach ($rule->signedOffsets() as $offset) {
                if ($offset === null) {
                    throw InvalidReminderRuleException::invalid($key, 'every offset must be a positive ISO-8601 duration');
                }
            }

            $ruleKey = $rule->ruleKey();

            if (strlen($ruleKey) > 100 || isset($seen[$ruleKey])) {
                throw InvalidReminderRuleException::invalid($key, 'rule keys must be unique per type (set ->key() on rules sharing an attribute and direction)');
            }

            $seen[$ruleKey] = true;
        }
    }
}
