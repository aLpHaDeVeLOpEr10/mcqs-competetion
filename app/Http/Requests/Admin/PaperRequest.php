<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PaperRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_time_limit_seconds' => ['required', 'integer', "between:{$min},{$max}"],
            'target_question_count' => ['nullable', 'integer', 'between:1,'.config('competition.papers.max_questions')],
        ];
    }
}
