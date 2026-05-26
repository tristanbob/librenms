<?php

namespace LibreNMS\Graph;

class GraphVariableDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly mixed $default = null,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly array $allowed = [],
    ) {}

    public static function integer(string $name, int $default, ?int $min = null, ?int $max = null): self
    {
        return new self($name, 'int', $default, $min, $max);
    }

    public static function boolean(string $name, bool $default = false): self
    {
        return new self($name, 'bool', $default);
    }

    public static function string(string $name, string $default = '', array $allowed = []): self
    {
        return new self($name, 'string', $default, allowed: $allowed);
    }

    public function resolve(array $options): mixed
    {
        $value = $options[$this->name] ?? $this->default;

        return match ($this->type) {
            'int' => $this->resolveInteger($value),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $this->default,
            'string' => $this->resolveString($value),
            default => $value,
        };
    }

    private function resolveInteger(mixed $value): int
    {
        $value = is_numeric($value) ? (int) $value : (int) $this->default;
        if ($this->min !== null) {
            $value = max($this->min, $value);
        }
        if ($this->max !== null) {
            $value = min($this->max, $value);
        }

        return $value;
    }

    private function resolveString(mixed $value): string
    {
        $value = (string) $value;
        if ($this->allowed !== [] && ! in_array($value, $this->allowed, true)) {
            return (string) $this->default;
        }

        return $value;
    }
}
