<?php

namespace App\Enums;

enum LocationType: string
{
    case Store = 'store';
    case Shop = 'shop';
    case Online = 'online';
    case Virtual = 'virtual';

    public function label(): string
    {
        return match ($this) {
            self::Store => __('Store'),
            self::Shop => __('Shop'),
            self::Online => __('Online'),
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
