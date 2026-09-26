<?php

namespace App\Services;

use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final class SettingsService
{
    /**
     * @var array<string, array{value: ?string, type: string}>|null
     */
    private ?array $memo = null;

    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * The stored, cast value for a setting, or its code-defined default.
     *
     * Never throws: a missing table, a mismatched stored type, or an
     * uncastable value all fall back to the default.
     */
    public function get(SettingKey $key): mixed
    {
        $row = $this->load()[$key->value] ?? null;

        if ($row === null || $row['value'] === null) {
            return $key->default();
        }

        if ($row['type'] !== $key->type()->value) {
            return $key->default();
        }

        try {
            return $key->type()->cast($row['value']);
        } catch (\Throwable) {
            return $key->default();
        }
    }

    public function string(SettingKey $key): ?string
    {
        $this->assertType($key, SettingType::String);

        return $this->get($key);
    }

    public function integer(SettingKey $key): int
    {
        $this->assertType($key, SettingType::Integer);

        return $this->get($key);
    }

    public function float(SettingKey $key): float
    {
        $this->assertType($key, SettingType::Float);

        return (float) $this->get($key);
    }

    public function boolean(SettingKey $key): bool
    {
        $this->assertType($key, SettingType::Boolean);

        return (bool) $this->get($key);
    }

    /**
     * Store a value. A `null` value forgets the setting (reverts to default).
     *
     * @throws \InvalidArgumentException when the value does not match the key's type.
     */
    public function set(SettingKey $key, mixed $value): void
    {
        if ($value === null) {
            $this->forget($key);

            return;
        }

        $type = $key->type();

        if (! $type->accepts($value)) {
            throw new \InvalidArgumentException("The value for setting [{$key->value}] must be a {$type->value}.");
        }

        Setting::query()->updateOrCreate(
            ['key' => $key->value],
            ['value' => $type->serialize($value), 'type' => $type, 'group' => $key->group()],
        );

        $this->memo = null;
    }

    /**
     * Store several settings atomically: every value is validated before
     * anything is written, and the writes happen in one transaction.
     *
     * @param  array<string, mixed>  $values  keyed by SettingKey value, e.g. 'editor.font_size'
     *
     * @throws \ValueError when a key is not a known SettingKey.
     * @throws \InvalidArgumentException when a value does not match its key's type.
     */
    public function setMany(array $values): void
    {
        $prepared = [];

        foreach ($values as $rawKey => $value) {
            $key = SettingKey::from($rawKey);

            if ($value === null) {
                $prepared[] = ['key' => $key, 'value' => null];

                continue;
            }

            $type = $key->type();

            if (! $type->accepts($value)) {
                throw new \InvalidArgumentException("The value for setting [{$key->value}] must be a {$type->value}.");
            }

            $prepared[] = ['key' => $key, 'value' => $type->serialize($value)];
        }

        $this->database->connection()->transaction(function () use ($prepared): void {
            foreach ($prepared as $entry) {
                /** @var SettingKey $key */
                $key = $entry['key'];

                if ($entry['value'] === null) {
                    Setting::query()->where('key', $key->value)->delete();

                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key->value],
                    ['value' => $entry['value'], 'type' => $key->type(), 'group' => $key->group()],
                );
            }
        });

        $this->memo = null;
    }

    public function forget(SettingKey $key): void
    {
        Setting::query()->where('key', $key->value)->delete();

        $this->memo = null;
    }

    /**
     * Whether a row exists for this key (a value was explicitly set).
     */
    public function has(SettingKey $key): bool
    {
        return array_key_exists($key->value, $this->load());
    }

    /**
     * Every key of a group, keyed by its field name, with defaults filled in.
     *
     * @return array<string, mixed>
     */
    public function group(SettingGroup $group): array
    {
        $values = [];

        foreach (SettingKey::cases() as $key) {
            if ($key->group() !== $group) {
                continue;
            }

            $values[$key->field()] = $this->get($key);
        }

        return $values;
    }

    private function assertType(SettingKey $key, SettingType $expected): void
    {
        if ($key->type() !== $expected) {
            throw new \LogicException("Setting [{$key->value}] is a {$key->type()->value} setting, not a {$expected->value} setting.");
        }
    }

    /**
     * Load and memoise every stored setting row for the lifetime of this
     * (request-scoped) instance. Falls back to an empty memo, so reads
     * degrade to defaults, when the table is unavailable.
     *
     * @return array<string, array{value: ?string, type: string}>
     */
    private function load(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $rows = Setting::query()->get(['key', 'value', 'type']);
        } catch (QueryException $e) {
            report($e);

            return $this->memo = [];
        }

        $memo = [];

        foreach ($rows as $row) {
            $memo[$row->key] = [
                'value' => $row->value,
                'type' => $row->type->value,
            ];
        }

        return $this->memo = $memo;
    }
}
