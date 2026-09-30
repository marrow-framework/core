<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Http\FormRequest;
use Marrow\Validation\Attributes\Confirmed;
use Marrow\Validation\Attributes\Email;
use Marrow\Validation\Attributes\In;
use Marrow\Validation\Attributes\Max;
use Marrow\Validation\Attributes\Nullable;
use Marrow\Validation\Attributes\Required;
use Marrow\Validation\Attributes\Rule;
use Marrow\Validation\Attributes\StringType;

class AttributeFormRequest extends FormRequest
{
    #[Required, StringType, Max(255)]
    public string $title;

    #[Required, Email]
    public string $email;

    #[Required, In(['draft', 'published'])]
    public string $status;

    #[Nullable, Rule('min:8')]
    public ?string $password = null;

    #[Confirmed]
    public string $newPassword;

    /** No validation attribute — must not appear in the resolved rules. */
    public string $internalTrackingId;

    public function rules(): array
    {
        return $this->rulesFromAttributes();
    }
}
