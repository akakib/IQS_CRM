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
        // Read once per request: a page asks for it dozens of times, and the cache lives in the database on the server.
        $app = app();
        if ($app->bound('order-statuses.map')) {
            return $app->make('order-statuses.map');
        }
        $map = Cache::rememberForever('order_statuses:map', fn () => self::orderBy('sort_order')->get()
            ->mapWithKeys(fn ($s) => [$s->id => [
                'id' => $s->id, 'key' => $s->system_key, 'name' => $s->name_en, 'color' => $s->color,
                'group' => $s->stage_group, 'final' => $s->is_final, 'edit_policy' => $s->edit_policy,
                'requires_reason' => $s->requires_reason, 'active' => $s->is_active,
            ]])->all());
        $app->instance('order-statuses.map', $map);

        return $map;
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

    /**
     * The status keys of one named group, so every report reads the same list:
     * delivered = the customer kept it (all or part); packaging = in the shop; closed = nothing more happens.
     *
     * @return list<string>
     */
    public static function group(string $name): array
    {
        return match ($name) {
            'delivered' => ['delivered', 'partial_delivered'],
            'packaging' => ['ready_for_packaging', 'packed', 'ready_for_pickup'],
            'with_courier' => ['handed_over', 'in_transit'],
            'confirmed_or_later' => ['confirmed', 'ready_for_packaging', 'packed', 'ready_for_pickup', 'handed_over', 'in_transit', 'delivered', 'partial_delivered', 'returned'],
            'closed' => ['delivered', 'partial_delivered', 'returned', 'cancelled'],
        };
    }

    /** @return list<int> */
    public static function groupIds(string $name): array
    {
        return self::idsFor(self::group($name));
    }

    public static function forget(): void
    {
        Cache::forget('order_statuses:map');
        app()->forgetInstance('order-statuses.map');
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }
}
