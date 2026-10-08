<?php

namespace App\Http\Requests;

use App\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'regex:/^01[3-9]\d{8}$/', Rule::unique('users', 'phone')->ignore($user?->id)],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'work_location_id' => ['nullable', Rule::exists('locations', 'id')->whereNull('deleted_at')],
            'telegram_user_id' => ['nullable', 'string', 'max:50'],
            'voice_name' => ['nullable', 'string', 'max:60'],
            'desk_limit' => ['nullable', 'integer', 'min:0', 'max:50'],
            'desk_timer_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'desk_extend_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'desk_extend_daily_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
            'desk_voice' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_photo' => ['nullable', 'boolean'],
            // Required when creating; optional on edit (filled = Owner resets it).
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => __('Phone must be 11 digits like 01XXXXXXXXX.')];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'employment_type' => $this->input('employment_type') ?: null,
            'work_location_id' => $this->input('work_location_id') ?: null,
            'phone' => $this->input('phone') ?: null,
            'telegram_user_id' => $this->input('telegram_user_id') ?: null,
            'voice_name' => trim((string) $this->input('voice_name')) ?: null,
            ...collect(['desk_limit', 'desk_timer_minutes', 'desk_extend_minutes', 'desk_extend_daily_limit', 'desk_voice'])
                ->mapWithKeys(fn ($k) => [$k => ($v = trim((string) $this->input($k))) === '' ? null : $v])->all(),
        ]);
    }
}
