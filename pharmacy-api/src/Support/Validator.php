<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lightweight rule-based input validator.
 *
 * Usage:
 *   $v = new Validator($data, [
 *       'email'    => 'required|email',
 *       'password' => 'required|min:8',
 *       'age'      => 'integer|min:0|max:120',
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    private array $errors = [];

    public function __construct(
        private readonly array $data,
        private readonly array $rules
    ) {
        $this->run();
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        return array_intersect_key($this->data, $this->rules);
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $required = in_array('required', $rules, true);

            if (!$required && ($value === null || $value === '')) {
                continue;
            }

            $isNumericField = in_array('numeric', $rules, true) || in_array('integer', $rules, true);

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $this->applyRule($field, $value, $name, $param, $isNumericField);
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule, ?string $param, bool $isNumericField = false): void
    {
        $label = ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                    $this->addError($field, "{$label} is required.");
                }
                break;

            case 'email':
                if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "{$label} must be a valid email address.");
                }
                break;

            case 'min':
                if ($isNumericField) {
                    if ((float) $value < (float) $param) {
                        $this->addError($field, "{$label} must be at least {$param}.");
                    }
                } elseif (mb_strlen((string) $value) < (int) $param) {
                    $this->addError($field, "{$label} must be at least {$param} characters.");
                }
                break;

            case 'max':
                if ($isNumericField) {
                    if ((float) $value > (float) $param) {
                        $this->addError($field, "{$label} may not be greater than {$param}.");
                    }
                } elseif (mb_strlen((string) $value) > (int) $param) {
                    $this->addError($field, "{$label} may not be greater than {$param} characters.");
                }
                break;

            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, "{$label} must be an integer.");
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, "{$label} must be numeric.");
                }
                break;

            case 'string':
                if (!is_string($value)) {
                    $this->addError($field, "{$label} must be a string.");
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $param);
                if (!in_array((string) $value, $allowed, true)) {
                    $this->addError($field, "{$label} is invalid.");
                }
                break;

            case 'regex':
                if ($param !== null && !preg_match($param, (string) $value)) {
                    $this->addError($field, "{$label} format is invalid.");
                }
                break;

            case 'confirmed':
                if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                    $this->addError($field, "{$label} confirmation does not match.");
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
