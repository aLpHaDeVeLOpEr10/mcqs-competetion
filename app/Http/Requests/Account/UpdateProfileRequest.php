<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Concerns\NormalizesAccountInput;
use App\Rules\DisplayName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only name and display name are editable here. Email changes go through
 * confirmation; role and status are never accepted from players.
 */
class UpdateProfileRequest extends FormRequest
{
    use NormalizesAccountInput;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->user());
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAccountInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\pL\pM' .-]+$/u"],
            'display_name' => ['required', 'string', new DisplayName, Rule::unique('users', 'display_name')->ignore($this->user()->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Your name may only contain letters, spaces, apostrophes, dots and dashes.',
            'display_name.unique' => 'That display name is already taken.',
        ];
    }
}
