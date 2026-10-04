<?php

namespace App\Enums;

enum LocationType: string
{
    case Warehouse = 'warehouse';
    case Shop = 'shop';
    case Virtual = 'virtual';

    public function label(): string
    {
        return match ($this) {
            self::Warehouse => __('Warehouse'),
            self::Shop => __('Shop'),
            self::Virtual => __('Virtual'),
        };
    }

    /** @return array<string, string> value => label, for dropdowns */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
