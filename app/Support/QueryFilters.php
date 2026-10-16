<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Validated GET filters for listing pages. Invalid values are dropped and
 * reported instead of failing the page.
 */
final class QueryFilters
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $ignored
     */
    private function __construct(public readonly array $values, public readonly array $ignored) {}

    /**
     * @param  array<string, mixed>  $rules
     */
    public static function from(Request $request, array $rules): self
    {
        $input = array_filter(
            $request->only(array_keys($rules)),
            fn ($value) => $value !== null && $value !== '',
        );

        $validator = Validator::make($input, $rules);

        return new self($validator->valid(), array_keys($validator->errors()->messages()));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function any(): bool
    {
        return $this->values !== [];
    }

    /**
     * Case-insensitive "contains" search across columns, with LIKE wildcards escaped.
     *
     * @param  list<string>  $columns
     */
    public function search(Builder $query, string $key, array $columns): void
    {
        if (! $this->has($key)) {
            return;
        }

        $term = '%'.addcslashes((string) $this->get($key), '\\%_').'%';

        $query->where(function (Builder $q) use ($columns, $term) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $term);
            }
        });
    }
}
