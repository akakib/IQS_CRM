<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class OrderStatus extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean', 'is_final' => 'boolean', 'requires_reason' => 'boolean',
            'counts_as_sale' => 'boolean', 'is_active' => 'boolean',
        ];
    }

    /**
     * All statuses keyed by id, cached until a status is edited (statuses
     * change rarely and are read on every order screen).
     *
     * @return array<int, array{id: int, key: ?string, name: string, color: string, group: string, final: bool, edit_policy: string}>
     */
    public static function map(): array
    {
        return Cache::rememberForever('order_statuses:map', fn () => self::orderBy('sort_order')->get()
            ->mapWithKeys(fn ($s) => [$s->id => [
                'id' => $s->id, 'key' => $s->system_key, 'name' => $s->name_en, 'color' => $s->color,
                'group' => $s->stage_group, 'final' => $s->is_final, 'edit_policy' => $s->edit_policy,
                'requires_reason' => $s->requires_reason, 'active' => $s->is_active,
            ]])->all());
    }

    public static function idFor(string $key): int
    {
        foreach (self::map() as $id => $s) {
            if ($s['key'] === $key) {
                return $id;
            }
        }
        throw new \InvalidArgumentException("Unknown order status: {$key}");
    }

    /** @param list<string> $keys @return list<int> */
    public static function idsFor(array $keys): array
    {
        return array_values(array_map(fn ($s) => $s['id'], array_filter(self::map(), fn ($s) => in_array($s['key'], $keys, true))));
    }

    public static function forget(): void
    {
        Cache::forget('order_statuses:map');
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }
}
