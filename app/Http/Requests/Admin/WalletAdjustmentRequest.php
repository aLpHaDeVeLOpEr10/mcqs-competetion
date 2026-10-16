<?php

namespace App\Http\Requests\Admin;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class WalletAdjustmentRequest extends FormRequest
{
    protected $errorBag = 'adjust';

    public function authorize(): bool
    {
        return $this->user()->can('adjust', $this->route('wallet'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
            // One token per rendered form, so a double submit posts once.
            'token' => ['required', 'uuid'],
            'confirm' => ['accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('amount') && ! Money::parse((string) $this->input('amount'))) {
                $validator->errors()->add('amount', 'Enter an amount in rupees greater than zero, e.g. 250 or 250.50.');
            }
        }];
    }

    /**
     * Signed amount in minor units (credit > 0, debit < 0).
     */
    public function signedAmountMinor(): int
    {
        $amount = (int) Money::parse((string) $this->validated('amount'));

        return $this->validated('direction') === 'credit' ? $amount : -$amount;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['confirm.accepted' => 'Tick the box to confirm this adjustment.'];
    }
}
