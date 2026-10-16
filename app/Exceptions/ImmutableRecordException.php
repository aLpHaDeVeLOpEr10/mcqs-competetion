<?php

namespace App\Exceptions;

use LogicException;

class ImmutableRecordException extends LogicException
{
    public static function for(object $model, string $operation): self
    {
        return new self(sprintf('%s records are append-only; %s is not allowed.', class_basename($model), $operation));
    }
}
