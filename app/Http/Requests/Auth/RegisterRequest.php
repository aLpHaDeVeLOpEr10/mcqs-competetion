<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesAccountInput;
use App\Rules\DisplayName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use NormalizesAccountInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAccountInput();
    }

    /**
     * The email is deliberately not checked for uniqueness here, so the form
     * never reveals whether an address is registered (see RegistrationService).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\pL\pM' .-]+$/u"],
            'display_name' => ['required', 'string', new DisplayName, Rule::unique('users', 'display_name')],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
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
            'terms.accepted' => 'You must accept the Terms of use to create an account.',
        ];
    }
}
