<?php

declare(strict_types=1);

namespace Marrow\Validation;

use RuntimeException;

/**
 * Thrown when validation fails.
 * The HTTP kernel catches this and either redirects with errors (web)
 * or returns a 422 JSON response (API).
 */
class ValidationException extends RuntimeException
{
    public function __construct(private readonly ValidatorInstance $validator)
    {
        parent::__construct('The given data was invalid.');
    }

    public function errors(): array
    {
        return $this->validator->errors();
    }

    public function getValidator(): ValidatorInstance
    {
        return $this->validator;
    }
}
