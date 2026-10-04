<?php

namespace App\Support\Permissions;

use Illuminate\Support\Str;

/** Read-only view of config/permissions.php for screens and validation. */
final class Catalog
{
    public const ACTIONS = ['view', 'create', 'edit', 'approve', 'delete', 'export'];

    /** @return array<string, array{label: string, actions: array<string, string>}> module => label + [action => label] */
    public static function modules(): array
    {
        $labels = config('permissions.action_labels', []);
        $modules = [];

        foreach (config('permissions.modules', []) as $module => $actions) {
            $modules[$module] = [
                'label' => __(Str::headline($module)),
                'actions' => collect($actions)->mapWithKeys(fn ($a) => [
                    $a => __($labels["{$module}.{$a}"] ?? Str::headline($a)),
                ])->all(),
            ];
        }

        return $modules;
    }

    /** @return list<string> every key, e.g. staff.view */
    public static function keys(): array
    {
        $keys = [];
        foreach (config('permissions.modules', []) as $module => $actions) {
            foreach ($actions as $action) {
                $keys[] = "{$module}.{$action}";
            }
        }

        return $keys;
    }

    /** @return array<string, string> key => "Module: Action" for dropdowns */
    public static function options(): array
    {
        $options = [];
        foreach (self::modules() as $module => $m) {
            foreach ($m['actions'] as $action => $label) {
                $options["{$module}.{$action}"] = $m['label'].': '.$label;
            }
        }

        return $options;
    }

    /** @return array<string, string> field => label */
    public static function maskFields(): array
    {
        return collect(config('permissions.field_masks', []))
            ->mapWithKeys(fn ($f) => [$f => __(Str::headline($f))])->all();
    }
}
