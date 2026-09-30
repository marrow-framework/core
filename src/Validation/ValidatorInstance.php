<?php

declare(strict_types=1);

namespace Marrow\Validation;

use Marrow\Database\Connection;
use Marrow\Http\UploadedFile;

/**
 * Validates an array of data against a set of rules.
 *
 * Text rules:
 *   required, email, string, int/integer, numeric, boolean, url, date,
 *   min, max, in, confirmed, regex, nullable, unique:table,column
 *
 * File rules (value must be an UploadedFile instance):
 *   file                      — valid uploaded file
 *   image                     — image (jpeg png gif bmp webp svg)
 *   mimes:jpg,png,pdf         — allowed client-declared extension(s)
 *   mime_types:image/jpeg,... — allowed actual MIME type(s)
 *   max:2048                  — max size in KB  (strings: max length)
 *   min:1                     — min size in KB  (strings: min length)
 *   dimensions:key=val,...    — image dimension constraints:
 *                               min_width, max_width, min_height, max_height,
 *                               width (exact), height (exact)
 */
class ValidatorInstance
{
    private array $errors         = [];
    private array $validated      = [];
    private bool  $validated_flag = false;

    /** Image extensions recognised by the `image` rule. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];

    public function __construct(
        private readonly array      $data,
        private readonly array      $rules,
        private readonly array      $messages = [],
        private readonly ?Connection $db       = null
    ) {
    }

    /** @throws \InvalidArgumentException If $rules names a rule this validator doesn't recognise. */
    public function fails(): bool
    {
        if (!$this->validated_flag) {
            $this->validate();
        }
        return !empty($this->errors);
    }

    /** @throws \InvalidArgumentException See fails(). */
    public function passes(): bool
    {
        return !$this->fails();
    }

    /** @throws \InvalidArgumentException See fails(). */
    public function errors(): array
    {
        if (!$this->validated_flag) {
            $this->validate();
        }
        return $this->errors;
    }

    /** @throws \InvalidArgumentException See fails(). */
    public function validated(): array
    {
        if (!$this->validated_flag) {
            $this->validate();
        }
        return $this->validated;
    }

    // ── Core ─────────────────────────────────────────────────────────

    private function validate(): void
    {
        $this->validated_flag = true;

        foreach ($this->rules as $field => $ruleString) {
            $rules    = is_string($ruleString) ? explode('|', $ruleString) : (array) $ruleString;
            $value    = $this->getValue($field);
            $nullable = in_array('nullable', $rules, true);

            $this->validated[$field] = $value;

            foreach ($rules as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }

                if ($nullable && ($value === null || $value === '')) {
                    break;
                }

                $this->applyRule($field, $value, $rule);
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        [$ruleName, $param] = array_pad(explode(':', $rule, 2), 2, null);

        $isFile = $value instanceof UploadedFile;

        $passed = match ($ruleName) {
            // ── Text rules ──────────────────────────────────────────
            'required'  => $this->validateRequired($value),
            'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'string'    => is_string($value),
            'int',
            'integer'   => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric'   => is_numeric($value),
            'boolean'   => in_array($value, [true, false, 0, 1, '0', '1'], true),
            'url'       => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'date'      => strtotime((string) $value) !== false,
            'confirmed' => $this->getValue($field . '_confirmation') === $value,
            'regex'     => (bool) preg_match((string) $param, (string) $value),
            'in'        => in_array($value, explode(',', (string) $param), true),
            'not_in'    => !in_array($value, explode(',', (string) $param), true),
            'unique'    => $this->validateUnique($value, (string) $param),
            'exists'    => $this->validateExists($value, (string) $param),
            'nullable'  => true,

            // ── Extended text rules ──────────────────────────────────
            'alpha'      => (bool) preg_match('/^[\pL]+$/u', (string) $value),
            'alpha_num'  => (bool) preg_match('/^[\pL\pN]+$/u', (string) $value),
            'alpha_dash' => (bool) preg_match('/^[\pL\pN_-]+$/u', (string) $value),
            'uuid'       => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value),
            'ip'         => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'json'       => $this->isJson($value),
            'array'      => is_array($value),
            'digits'     => ctype_digit((string) $value) && mb_strlen((string) $value) === (int) $param,
            'starts_with' => $this->matchesAny((string) $value, (string) $param, 'starts'),
            'ends_with'   => $this->matchesAny((string) $value, (string) $param, 'ends'),
            'same'       => $value === $this->getValue((string) $param),
            'different'  => $value !== $this->getValue((string) $param),
            'gt'         => (float) $value >  (float) $this->getValue((string) $param),
            'gte'        => (float) $value >= (float) $this->getValue((string) $param),
            'lt'         => (float) $value <  (float) $this->getValue((string) $param),
            'lte'        => (float) $value <= (float) $this->getValue((string) $param),
            'between'    => $this->validateBetween($value, (string) $param),

            // ── Size rules (file-aware) ──────────────────────────────
            'min' => $isFile
                ? ($value->getSize() >= ((int) $param * 1024))
                : (is_string($value)
                    ? mb_strlen($value) >= (int) $param
                    : (float) $value >= (float) $param),

            'max' => $isFile
                ? ($value->getSize() <= ((int) $param * 1024))
                : (is_string($value)
                    ? mb_strlen($value) <= (int) $param
                    : (float) $value <= (float) $param),

            // ── File rules ───────────────────────────────────────────
            'file'       => $isFile && $value->isValid(),

            'image'      => $isFile && $value->isValid()
                && in_array(
                    strtolower($value->guessClientExtension() ?? $value->getClientOriginalExtension()),
                    self::IMAGE_EXTENSIONS,
                    true
                ),

            'mimes'      => $isFile && $value->isValid()
                && in_array(
                    strtolower($value->getClientOriginalExtension()),
                    array_map('trim', explode(',', (string) $param)),
                    true
                ),

            'mime_types' => $isFile && $value->isValid()
                && in_array(
                    $value->getMimeType() ?? $value->getClientMimeType(),
                    array_map('trim', explode(',', (string) $param)),
                    true
                ),

            'dimensions' => $isFile && $value->isValid()
                && $this->validateDimensions($value, (string) $param),

            default => throw new \InvalidArgumentException("Unknown validation rule [{$ruleName}]."),
        };

        if (!$passed) {
            $this->addError($field, $ruleName, $param);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function validateRequired(mixed $value): bool
    {
        if ($value instanceof UploadedFile) {
            return $value->isValid();
        }
        return $value !== null && $value !== '' && $value !== [];
    }

    private function isJson(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function matchesAny(string $value, string $param, string $mode): bool
    {
        foreach (array_map('trim', explode(',', $param)) as $needle) {
            if ($mode === 'starts' && str_starts_with($value, $needle)) {
                return true;
            }
            if ($mode === 'ends' && str_ends_with($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function validateBetween(mixed $value, string $param): bool
    {
        [$min, $max] = array_pad(explode(',', $param, 2), 2, 0);
        $size = is_string($value) ? mb_strlen($value) : (float) $value;
        return $size >= (float) $min && $size <= (float) $max;
    }

    /** exists:table,column — value must already be present in the table. */
    private function validateExists(mixed $value, string $param): bool
    {
        if ($this->db === null || $value === null || $value === '') {
            return true;
        }
        [$table, $column] = array_pad(explode(',', $param, 2), 2, 'id');
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM {$table} WHERE {$column} = ?",
            [$value]
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function validateUnique(mixed $value, string $param): bool
    {
        if ($this->db === null || $value === null || $value === '') {
            return true;
        }
        [$table, $column] = array_pad(explode(',', $param, 2), 2, 'id');
        $count = $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM {$table} WHERE {$column} = ?",
            [$value]
        );
        return ((int) ($count['cnt'] ?? 0)) === 0;
    }

    /**
     * Validate image dimensions.
     *
     * Param format: "min_width=100,max_width=2000,min_height=100,max_height=2000"
     * Or exact:     "width=800,height=600"
     */
    private function validateDimensions(UploadedFile $file, string $param): bool
    {
        $dims = $file->dimensions();
        if ($dims === null) {
            return false;
        }

        [$width, $height] = $dims;
        $constraints = [];

        foreach (explode(',', $param) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $constraints[trim($k)] = (int) trim($v);
            }
        }

        if (isset($constraints['width'])     && $width  !== $constraints['width'])     return false;
        if (isset($constraints['height'])    && $height !== $constraints['height'])     return false;
        if (isset($constraints['min_width']) && $width  <  $constraints['min_width'])  return false;
        if (isset($constraints['max_width']) && $width  >  $constraints['max_width'])  return false;
        if (isset($constraints['min_height'])&& $height <  $constraints['min_height']) return false;
        if (isset($constraints['max_height'])&& $height >  $constraints['max_height']) return false;

        return true;
    }

    private function addError(string $field, string $rule, ?string $param): void
    {
        $key     = "{$field}.{$rule}";
        $message = $this->messages[$key]
            ?? $this->messages[$field]
            ?? $this->defaultMessage($field, $rule, $param);

        $this->errors[$field][] = $message;
    }

    private function defaultMessage(string $field, string $rule, ?string $param): string
    {
        $label = str_replace('_', ' ', $field);

        return match ($rule) {
            'required'   => "Le champ {$label} est obligatoire.",
            'email'      => "Le champ {$label} doit être une adresse email valide.",
            'string'     => "Le champ {$label} doit être une chaîne de caractères.",
            'int',
            'integer'    => "Le champ {$label} doit être un entier.",
            'numeric'    => "Le champ {$label} doit être numérique.",
            'boolean'    => "Le champ {$label} doit être vrai ou faux.",
            'url'        => "Le champ {$label} doit être une URL valide.",
            'date'       => "Le champ {$label} doit être une date valide.",
            'min'        => "Le champ {$label} doit avoir au minimum {$param}.",
            'max'        => "Le champ {$label} ne doit pas dépasser {$param}.",
            'in'         => "La valeur de {$label} n'est pas valide.",
            'regex'      => "Le format de {$label} est invalide.",
            'confirmed'  => "La confirmation de {$label} ne correspond pas.",
            'unique'     => "Cette valeur est déjà prise pour {$label}.",
            'exists'     => "La valeur de {$label} n'existe pas.",
            'not_in'     => "La valeur de {$label} n'est pas autorisée.",
            'alpha'      => "Le champ {$label} ne doit contenir que des lettres.",
            'alpha_num'  => "Le champ {$label} ne doit contenir que des lettres et des chiffres.",
            'alpha_dash' => "Le champ {$label} ne doit contenir que des lettres, chiffres, tirets et underscores.",
            'uuid'       => "Le champ {$label} doit être un UUID valide.",
            'ip'         => "Le champ {$label} doit être une adresse IP valide.",
            'json'       => "Le champ {$label} doit être du JSON valide.",
            'array'      => "Le champ {$label} doit être un tableau.",
            'digits'     => "Le champ {$label} doit comporter {$param} chiffres.",
            'starts_with' => "Le champ {$label} doit commencer par : {$param}.",
            'ends_with'  => "Le champ {$label} doit se terminer par : {$param}.",
            'same'       => "Le champ {$label} doit correspondre à {$param}.",
            'different'  => "Le champ {$label} doit être différent de {$param}.",
            'gt'         => "Le champ {$label} doit être supérieur à {$param}.",
            'gte'        => "Le champ {$label} doit être supérieur ou égal à {$param}.",
            'lt'         => "Le champ {$label} doit être inférieur à {$param}.",
            'lte'        => "Le champ {$label} doit être inférieur ou égal à {$param}.",
            'between'    => "Le champ {$label} doit être compris entre {$param}.",
            'file'       => "Le champ {$label} doit être un fichier valide.",
            'image'      => "Le champ {$label} doit être une image (jpeg, png, gif, bmp, webp).",
            'mimes'      => "Le champ {$label} doit être un fichier de type : {$param}.",
            'mime_types' => "Le champ {$label} a un type MIME non autorisé.",
            'dimensions' => "Le champ {$label} ne respecte pas les dimensions requises.",
            default      => "Le champ {$label} est invalide.",
        };
    }

    private function getValue(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }
}
