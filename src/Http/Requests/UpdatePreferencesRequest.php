<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use RoundlyConsulting\Notifications\DataTransferObjects\DigestPreferenceData;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;

/**
 * `PATCH /preferences`: `channels[<type>][<channel>]=bool`, `categories[<category>][<channel>]=bool`,
 * `digest{frequency, time}`, `quiet_hours{start, end}|null`, `timezone`.
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
            'channels.*' => ['array'],
            'channels.*.*' => ['boolean'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['array'],
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
     * @return array<string, array<string, bool>>
     */
    public function channels(): array
    {
        return self::channelMap($this->validated('channels', []));
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function categories(): array
    {
        return self::channelMap($this->validated('categories', []));
    }

    public function digest(): ?DigestPreferenceData
    {
        $frequency = $this->validated('digest.frequency');

        if (! is_string($frequency)) {
            return null;
        }

        $time = $this->validated('digest.time');

        return new DigestPreferenceData(DigestFrequency::from($frequency), is_string($time) ? $time : null);
    }

    public function quietHours(): ?QuietHours
    {
        $start = $this->validated('quiet_hours.start');
        $end = $this->validated('quiet_hours.end');

        return is_string($start) && is_string($end) ? new QuietHours($start, $end) : null;
    }

    public function timezone(): ?string
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
                if (in_array($channel, ['inbox', 'mail'], true)) {
                    $map[(string) $key][(string) $channel] = filter_var($enabled, FILTER_VALIDATE_BOOL);
                }
            }
        }

        return $map;
    }
}
