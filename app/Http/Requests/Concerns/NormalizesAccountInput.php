<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

trait NormalizesAccountInput
{
    /**
     * Trim and normalize the account fields present on the request.
     */
    protected function normalizeAccountInput(): void
    {
        $normalized = [];

        if (is_string($this->input('email'))) {
            $normalized['email'] = Str::lower(trim($this->input('email')));
        }

        if (is_string($this->input('name'))) {
            $normalized['name'] = Str::squish($this->input('name'));
        }

        if (is_string($this->input('display_name'))) {
            $normalized['display_name'] = trim($this->input('display_name'));
        }

        $this->merge($normalized);
    }
}
