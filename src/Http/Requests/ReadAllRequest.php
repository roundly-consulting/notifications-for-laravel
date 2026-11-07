<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /inbox/read-all` with an optional `context` and `category`.
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
}
