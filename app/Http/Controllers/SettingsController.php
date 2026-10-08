<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function edit(): View
    {
        $groups = [];
        foreach (config('settings') as $key => [$type, $default, $label, $group]) {
            $groups[$group][$key] = ['type' => $type, 'label' => __($label), 'value' => $this->settings->get($key)];
        }

        return view('settings.general', ['groups' => $groups]);
    }

    public function update(Request $request, ActivityLogger $logger): RedirectResponse
    {
        // Field names use _ for . (form arrays treat dots as nesting).
        $rules = [];
        foreach (config('settings') as $key => [$type, , , , $keyRules]) {
            $rules[str_replace('.', '_', $key)] = $keyRules;
        }
        $rules['orders_pickup_cutoffs.*'] = ['required', 'date_format:H:i'];
        $rules['appearance_font'][] = \Illuminate\Validation\Rule::in(array_keys(config('appearance.fonts')));

        // An unticked box sends nothing: on the General page every on/off setting is read as on or off.
        if ($request->has('settings_page')) {
            foreach (config('settings') as $key => [$type]) {
                if ($type === 'bool') {
                    $field = str_replace('.', '_', $key);
                    $request->merge([$field => $request->boolean($field)]);
                }
            }
        }
        $data = $request->validate($rules, [
            'store_hotline.regex' => __('Hotline must be 11 digits like 01XXXXXXXXX.'),
        ]);

        $values = [];
        foreach (array_keys(config('settings')) as $key) {
            $field = str_replace('.', '_', $key);
            if ($key === 'store.logo') {
                continue;
            }
            if (array_key_exists($field, $data)) {
                $values[$key] = $data[$field];
            }
        }
        $values['orders.pickup_cutoffs'] = collect($values['orders.pickup_cutoffs'] ?? [])->unique()->sort()->values()->all();

        if ($request->hasFile('store_logo')) {
            $old = $this->settings->get('store.logo');
            $values['store.logo'] = $request->file('store_logo')->store('branding', 'public');
            if ($old) {
                Storage::disk('public')->delete($old);
            }
        } elseif ($request->boolean('remove_logo')) {
            Storage::disk('public')->delete((string) $this->settings->get('store.logo'));
            $values['store.logo'] = '';
        }

        $changes = $this->settings->set($values, $request->user()->id);
        if ($changes['after'] !== []) {
            $logger->log('settings.updated', null, $changes['before'], $changes['after']);
        }

        return back()->with('success', __('Settings saved.'));
    }
}
