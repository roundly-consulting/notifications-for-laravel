<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /inbox/read-all` with an optional `context`, `category` and `type`.
 */
final class ReadAllRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'context' => ['sometimes', 'nullable', 'string', 'max:191'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function context(): ?string
    {
        $value = $this->validated('context');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function category(): ?string
    {
        $value = $this->validated('category');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function type(): ?string
    {
        $value = $this->validated('type');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
