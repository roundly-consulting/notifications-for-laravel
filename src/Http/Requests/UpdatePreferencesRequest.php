<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use RoundlyConsulting\Notifications\DataTransferObjects\DigestPreferenceData;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesUpdate;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Support\PreferenceUpdates;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * `PATCH /preferences`: `channels[<type>][<channel>]=bool`, `categories[<category>][<channel>]=bool`,
 * `digest{frequency, time}`, `quiet_hours{start, end}|null`, `timezone`. Unknown types,
 * categories and channels and a mandatory type switched off are validation errors under their
 * field (`channels.security.alert.mail`), so a refused payload writes nothing.
 */
final class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['array:inbox,mail'],
            'channels.*.*' => ['boolean'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['array:inbox,mail'],
            'categories.*.*' => ['boolean'],
            'digest' => ['sometimes', 'array'],
            'digest.frequency' => ['required_with:digest', 'string', 'in:off,daily,weekly'],
            'digest.time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_hours' => ['sometimes', 'nullable', 'array'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * The domain rules the update action enforces, reported per field.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(TypeRegistry $types): array
    {
        return [
            function (Validator $validator) use ($types): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (PreferenceUpdates::problems($this->toUpdate(), $types) as $field => $problem) {
                    $validator->errors()->add($field, $problem->getMessage());
                }
            },
        ];
    }

    public function toUpdate(): PreferencesUpdate
    {
        return new PreferencesUpdate(
            channels: self::channelMap($this->validated('channels', [])),
            categories: self::channelMap($this->validated('categories', [])),
            digest: $this->digest(),
            setsQuietHours: $this->has('quiet_hours'),
            quietHours: $this->quietHours(),
            setsTimezone: $this->has('timezone'),
            timezone: $this->timezone(),
        );
    }

    private function digest(): ?DigestPreferenceData
    {
        $frequency = $this->validated('digest.frequency');

        if (! is_string($frequency)) {
            return null;
        }

        $time = $this->validated('digest.time');

        return new DigestPreferenceData(DigestFrequency::from($frequency), is_string($time) ? $time : null);
    }

    private function quietHours(): ?QuietHours
    {
        $start = $this->validated('quiet_hours.start');
        $end = $this->validated('quiet_hours.end');

        return is_string($start) && is_string($end) ? new QuietHours($start, $end) : null;
    }

    private function timezone(): ?string
    {
        $timezone = $this->validated('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private static function channelMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $channels) {
            foreach (is_array($channels) ? $channels : [] as $channel => $enabled) {
                $map[(string) $key][(string) $channel] = filter_var($enabled, FILTER_VALIDATE_BOOL);
            }
        }

        return $map;
    }
}
