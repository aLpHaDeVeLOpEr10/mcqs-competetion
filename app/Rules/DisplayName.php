<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Public alias format: 3–30 letters, digits, dot, dash or underscore,
 * starting with a letter or digit, and not a reserved staff-like name.
 */
class DisplayName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,29}$/', $value)) {
            $fail('The display name must be 3–30 characters: letters, numbers, dots, dashes or underscores, starting with a letter or number.');

            return;
        }

        $normalized = strtolower(preg_replace('/[_.-]/', '', $value));

        foreach (config('competition.accounts.reserved_display_names') as $reserved) {
            if ($normalized === $reserved || str_starts_with($normalized, $reserved.'team')) {
                $fail('That display name is reserved. Please choose another.');

                return;
            }
        }
    }
}
