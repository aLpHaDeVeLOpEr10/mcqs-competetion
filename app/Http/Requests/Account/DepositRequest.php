<?php

namespace App\Http\Requests\Account;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transact');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:20'],
            // One token per rendered form, so a double submit creates one deposit.
            'token' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('amount') && ! Money::parse((string) $this->input('amount'))) {
                $validator->errors()->add('amount', 'Enter an amount in rupees, e.g. 500 or 500.50.');
            }
        }];
    }

    public function amountMinor(): int
    {
        return (int) Money::parse((string) $this->validated('amount'));
    }
}
