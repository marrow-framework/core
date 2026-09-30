<?php

declare(strict_types=1);

namespace Marrow\Http;

use Marrow\Exceptions\HttpException;
use Marrow\Validation\Attributes\RuleAttribute;
use Marrow\Validation\ValidationException;
use Marrow\Validation\ValidatorFactory;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Base class for typed, auto-validated form / API requests.
 *
 * Usage:
 *   class StorePostRequest extends FormRequest {
 *       public function rules(): array { return ['title' => 'required|string']; }
 *   }
 *
 * The Router calls validateResolved() automatically before the action runs.
 * File uploads are included in validation data when the FormRequest is built
 * from a real HTTP request via createFrom().
 */
abstract class FormRequest extends Request
{
    private bool  $isValidated   = false;
    private array $validatedData = [];

    // ── Contract ──────────────────────────────────────────────────────

    abstract public function rules(): array;

    public function messages(): array
    {
        return [];
    }

    /** Return false to abort with 403. */
    public function authorize(): bool
    {
        return true;
    }

    /** Hook called before validation — use merge() or unset fields. */
    public function prepareForValidation(): void
    {
    }

    /** Called after successful validation. */
    public function passedValidation(): void
    {
    }

    // ── Auto-validation ───────────────────────────────────────────────

    /**
     * Called by the Router right after the FormRequest is injected.
     * Runs authorization then validation; throws on failure.
     *
     * @throws HttpException 403 if authorize() returns false.
     * @throws \Marrow\Validation\ValidationException If validation fails.
     */
    public function validateResolved(): void
    {
        if ($this->isValidated) {
            return;
        }

        if (!$this->authorize()) {
            throw new HttpException(403, 'Cette action n\'est pas autorisée.');
        }

        $this->prepareForValidation();

        // Merge text input, JSON body, and uploaded files
        $textData  = $this->isJson() ? array_merge($this->all(), (array) $this->json()) : $this->all();
        $data      = array_merge($textData, $this->allFiles());

        $factory   = new ValidatorFactory();
        $validator = $factory->make($data, $this->rules(), $this->messages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $this->isValidated   = true;
        $this->validatedData = $validator->validated();

        $this->passedValidation();
    }

    /**
     * Returns only the data that passed validation.
     * Calls validateResolved() if not yet done.
     */
    public function validated(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->isValidated) {
            $this->validateResolved();
        }

        if ($key !== null) {
            return $this->validatedData[$key] ?? $default;
        }

        return $this->validatedData;
    }

    /**
     * Merge extra data into the request's input bag (useful in prepareForValidation).
     */
    public function merge(array $data): void
    {
        $this->request->add($data);
    }

    /**
     * Builds a rules array from #[Required]/#[Email]/... attributes declared
     * on this class's properties — an alternative to writing rules() by hand:
     *
     *   class StorePostRequest extends FormRequest
     *   {
     *       #[Required, StringType, Max(255)]
     *       public string $title;
     *
     *       public function rules(): array
     *       {
     *           return $this->rulesFromAttributes();
     *       }
     *   }
     *
     * Only reflects the class definition — these properties never need to
     * hold real submitted data, since rules() runs before validation, not
     * after (unlike Model, FormRequest has no __get()/__set() a declared
     * property could shadow, so a real property here is safe).
     *
     * @return array<string, string>
     */
    protected function rulesFromAttributes(): array
    {
        $rules = [];
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties() as $property) {
            $fragments = [];
            foreach ($property->getAttributes(RuleAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $fragments[] = $attribute->newInstance()->toRule();
            }
            if ($fragments !== []) {
                $rules[$property->getName()] = implode('|', $fragments);
            }
        }

        return $rules;
    }

    // ── Factory ───────────────────────────────────────────────────────

    /**
     * Build a FormRequest subclass from an existing Request.
     * Copies all parameter bags, including uploaded files.
     */
    public static function createFrom(Request $request): static
    {
        /** @var static $instance */
        $instance = new static(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),   // propagate uploaded files
            $request->server->all(),
            $request->getContent()
        );
        $instance->setRouteParams($request->allRouteParams());
        return $instance;
    }
}
