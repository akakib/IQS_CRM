<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Onsite = 'onsite';
    case Remote = 'remote';
    case PartTime = 'part_time';

    public function label(): string
    {
        return match ($this) {
            self::Onsite => __('Onsite'),
            self::Remote => __('Remote'),
            self::PartTime => __('Part-time'),
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
