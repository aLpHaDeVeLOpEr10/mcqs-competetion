<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per-paper overrides for one question.
 */
class PaperItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('content.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $min = config('competition.questions.min_time_limit');
        $max = config('competition.questions.max_time_limit');

        return [
            'time_limit_seconds' => ['nullable', 'integer', "between:{$min},{$max}"],
            'points' => ['required', 'integer', 'between:1,100'],
        ];
    }
}
