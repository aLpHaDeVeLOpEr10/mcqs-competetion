<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;

/**
 * Application-level guard for append-only tables. The database enforces the
 * same rule with triggers (see App\Support\Database\SchemaConstraints).
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(fn ($model) => throw ImmutableRecordException::for($model, 'update'));
        static::deleting(fn ($model) => throw ImmutableRecordException::for($model, 'delete'));
    }

    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }
}
