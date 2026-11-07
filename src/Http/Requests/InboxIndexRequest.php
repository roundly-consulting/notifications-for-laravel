<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * `GET /inbox?filter[status]=unread&filter[context]=company:3&filter[category]=…&filter[type]=…&per_page=…`
 */
final class InboxIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', 'in:active,unread,read,archived,all'],
            'filter.context' => ['sometimes', 'string', 'max:191'],
            'filter.category' => ['sometimes', 'string', 'max:100'],
            'filter.type' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.NotificationsConfig::maxPerPage()],
        ];
    }

    public function status(): string
    {
        return $this->filter('status') ?? 'active';
    }

    public function filter(string $key): ?string
    {
        $value = $this->validated('filter.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function perPage(): ?int
    {
        $value = $this->validated('per_page');

        return is_numeric($value) ? (int) $value : null;
    }
}
