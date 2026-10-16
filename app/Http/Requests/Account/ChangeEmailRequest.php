<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Concerns\NormalizesAccountInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ChangeEmailRequest extends FormRequest
{
    use NormalizesAccountInput;

    protected $errorBag = 'email';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->user());
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAccountInput();
    }

    /**
     * Uniqueness is checked only when the new address confirms the change,
     * so this form never reveals whether another account uses an address.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (strcasecmp((string) $value, $this->user()->email) === 0) {
                        $fail('That is already your email address.');
                    }
                },
            ],
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The password is incorrect.',
        ];
    }
}
