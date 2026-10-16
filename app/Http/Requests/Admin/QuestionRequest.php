<?php

namespace App\Http\Requests\Admin;

use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a question. The form sends up to max_options option fields
 * and the index of the correct one; empty fields are dropped and the
 * correct index is re-mapped onto the remaining options.
 */
class QuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('content.manage');
    }

    protected function prepareForValidation(): void
    {
        $options = collect((array) $this->input('options', []))
            ->map(fn ($o) => is_string($o) ? Str::squish($o) : $o)
            ->all();

        $this->merge([
            'body' => is_string($this->input('body')) ? trim($this->input('body')) : $this->input('body'),
            'options' => $options,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $q = config('competition.questions');

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'body' => ['required', 'string', 'max:2000'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'difficulty' => ['required', Rule::enum(QuestionDifficulty::class)],
            'status' => ['required', Rule::in([QuestionStatus::Draft->value, QuestionStatus::Active->value])],
            'default_time_limit_seconds' => ['nullable', 'integer', "between:{$q['min_time_limit']},{$q['max_time_limit']}"],
            'tags' => ['nullable', 'string', 'max:255'],
            'options' => ['required', 'array', "max:{$q['max_options']}"],
            'options.*' => ['nullable', 'string', 'max:500'],
            'correct' => ['required', 'integer', 'min:0', 'max:'.($q['max_options'] - 1)],
            'image' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', "max:{$q['image_max_kb']}",
                "dimensions:max_width={$q['image_max_dimension']},max_height={$q['image_max_dimension']}"],
            'image_alt' => ['nullable', 'string', 'max:255'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['options', 'correct'])) {
                return;
            }

            $filled = array_filter((array) $this->input('options'), fn ($o) => is_string($o) && $o !== '');
            $min = (int) config('competition.questions.min_options');

            if (count($filled) < $min) {
                $validator->errors()->add('options', "Enter at least {$min} answer options.");
            }

            if (count(array_unique(array_map('mb_strtolower', $filled))) !== count($filled)) {
                $validator->errors()->add('options', 'Answer options must all be different.');
            }

            if (! array_key_exists((int) $this->input('correct'), $filled)) {
                $validator->errors()->add('correct', 'Choose which filled-in option is correct.');
            }
        });
    }

    /**
     * Validated data in the shape QuestionService expects.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        $filled = array_filter($data['options'], fn ($o) => is_string($o) && $o !== '');

        return [
            'category_id' => (int) $data['category_id'],
            'body' => $data['body'],
            'explanation' => $data['explanation'] ?? null,
            'image_alt' => $data['image_alt'] ?? null,
            'difficulty' => $data['difficulty'],
            'status' => $data['status'],
            'default_time_limit_seconds' => $data['default_time_limit_seconds'] ?? null,
            'tags' => $data['tags'] ?? '',
            'options' => array_values($filled),
            'correct' => array_search((int) $data['correct'], array_keys($filled), true),
        ];
    }
}
