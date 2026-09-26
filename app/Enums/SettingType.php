<?php

namespace App\Enums;

enum SettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Json = 'json';

    /**
     * Whether a native PHP value may be stored under this type.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => is_string($value),
            self::Integer => is_int($value),
            self::Float => is_int($value) || is_float($value),
            self::Boolean => is_bool($value),
            self::Json => is_array($value),
        };
    }

    /**
     * Serialise a native PHP value to the string stored in the `value` column.
     *
     * @throws \JsonException
     */
    public function serialize(mixed $value): string
    {
        return match ($this) {
            self::String => (string) $value,
            self::Integer => (string) $value,
            self::Float => (string) (float) $value,
            self::Boolean => $value ? '1' : '0',
            self::Json => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Cast a raw stored string back to its native PHP value.
     *
     * @throws \UnexpectedValueException
     */
    public function cast(string $raw): mixed
    {
        return match ($this) {
            self::String => $raw,
            self::Integer => $this->castInteger($raw),
            self::Float => $this->castFloat($raw),
            self::Boolean => $this->castBoolean($raw),
            self::Json => $this->castJson($raw),
        };
    }

    private function castInteger(string $raw): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new \UnexpectedValueException("Cannot cast [{$raw}] to an integer.");
        }

        return $value;
    }

    private function castFloat(string $raw): float
    {
        if (! is_numeric($raw)) {
            throw new \UnexpectedValueException("Cannot cast [{$raw}] to a float.");
        }

        return (float) $raw;
    }

    private function castBoolean(string $raw): bool
    {
        return match ($raw) {
            '1' => true,
            '0' => false,
            default => throw new \UnexpectedValueException("Cannot cast [{$raw}] to a boolean."),
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function castJson(string $raw): array
    {
        $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($value)) {
            throw new \UnexpectedValueException("Cannot cast [{$raw}] to a JSON array.");
        }

        return $value;
    }
}
