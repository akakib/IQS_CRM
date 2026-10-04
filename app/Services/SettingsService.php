<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Typed global settings. All stored values are loaded with one query and
 * cached until the next save, so settings('x') costs nothing per call.
 */
class SettingsService
{
    private const CACHE_KEY = 'settings:all';

    private ?array $values = null;

    public function get(string $key): mixed
    {
        $definition = $this->definition($key);
        $this->values ??= Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')->all());

        return array_key_exists($key, $this->values)
            ? $this->cast($this->values[$key], $definition[0])
            : $definition[1];
    }

    /** @param array<string, mixed> $values key => value */
    public function set(array $values, ?int $userId = null): array
    {
        $changes = ['before' => [], 'after' => []];

        foreach ($values as $key => $value) {
            $type = $this->definition($key)[0];
            $old = $this->get($key);
            $new = $this->cast($this->serialize($value, $type), $type);

            if ($old === $new) {
                continue;
            }

            DB::table('settings')->updateOrInsert(['key' => $key], [
                'value' => $this->serialize($value, $type),
                'type' => $type,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);
            $changes['before'][$key] = $old;
            $changes['after'][$key] = $new;
        }

        Cache::forget(self::CACHE_KEY);
        $this->values = null;

        return $changes;
    }

    /** @return array{0: string, 1: mixed, 2: string, 3: string, 4: array} */
    public function definition(string $key): array
    {
        // Keys contain dots, so look them up in the array, not via dot notation.
        return config('settings')[$key] ?? throw new InvalidArgumentException("Unknown setting: {$key}");
    }

    private function cast(?string $raw, string $type): mixed
    {
        return match ($type) {
            'bool' => (bool) (int) $raw,
            'int' => (int) $raw,
            'decimal' => round((float) $raw, 2),
            'json' => $raw === null ? null : json_decode($raw, true),
            default => (string) $raw,
        };
    }

    private function serialize(mixed $value, string $type): ?string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode(array_values((array) $value)),
            default => $value === null ? null : (string) $value,
        };
    }
}
