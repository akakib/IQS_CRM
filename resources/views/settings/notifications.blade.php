@php
    $targetLabels = [
        'role' => __('Everyone with role'),
        'user' => __('One person'),
        'order_moderator' => __('The assigned moderator'),
        'actor_manager' => __('Managers'),
    ];
    $priorityOptions = ['info' => __('Info'), 'normal' => __('Normal'), 'urgent' => __('Urgent')];
@endphp

<x-layouts.app :heading="__('Notification settings')">
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <p class="text-sm text-gray-500">{{ __('Which event goes to whom, and on which channel. Telegram and SMS are queued; real sending is switched on later.') }}</p>
        <form method="POST" action="{{ route('settings.notifications.test') }}">
            @csrf
            <button type="submit" class="shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Send me a test') }}</button>
        </form>
    </div>

    <div class="space-y-3">
        @foreach ($types as $type)
            @continue($type->system_key === 'system_test')
            <div class="rounded-xl border border-gray-200 bg-white p-4" x-data="{ adding: false, target: 'role' }">
                <form method="POST" action="{{ route('settings.notifications.types.update', $type->id) }}" class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between"
                    x-ref="typeForm" @select-change="setTimeout(() => $refs.typeForm.requestSubmit(), 0)">
                    @csrf
                    @method('PUT')
                    <div>
                        <p class="text-sm font-semibold text-gray-800">{{ $type->name }}</p>
                        <p class="font-mono text-xs text-gray-400">{{ $type->system_key }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-simple-select name="default_priority" size="sm" :options="$priorityOptions" :value="$type->default_priority" />
                        <label class="flex items-center gap-1.5 text-xs text-gray-600">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked($type->is_active) @change="$refs.typeForm.requestSubmit()" class="rounded border-gray-300 text-primary">
                            {{ __('On') }}
                        </label>
                    </div>
                </form>

                <div class="mt-3 space-y-1.5">
                    @forelse ($rules[$type->id] ?? [] as $rule)
                        <div class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1.5 text-sm">
                            <span class="text-gray-700">
                                {{ $targetLabels[$rule->target] }}@if ($rule->role_name): <b>{{ $rule->role_name }}</b>@endif @if ($rule->user_name): <b>{{ $rule->user_name }}</b>@endif
                                <span class="ml-2 text-xs text-gray-500">{{ collect(['In-app' => $rule->channel_in_app, 'Telegram' => $rule->channel_telegram, 'SMS' => $rule->channel_sms])->filter()->keys()->join(' · ') }}</span>
                            </span>
                            <form method="POST" action="{{ route('settings.notifications.rules.destroy', $rule->id) }}" id="notif-rule-{{ $rule->id }}">
                                @csrf
                                @method('DELETE')
                                <button type="button" @click="$dispatch('open-confirm', { id: 'notif-rule-delete', form: 'notif-rule-{{ $rule->id }}', label: @js($targetLabels[$rule->target].($rule->role_name ? ': '.$rule->role_name : '').($rule->user_name ? ': '.$rule->user_name : '')) })" class="text-xs text-red-600 hover:underline">{{ __('Remove') }}</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">{{ __('Nobody receives this yet.') }}</p>
                    @endforelse
                </div>

                <button type="button" x-show="!adding" @click="adding = true" class="mt-2 text-xs font-medium text-primary hover:underline">+ {{ __('Add who receives it') }}</button>
                <form x-show="adding" x-cloak method="POST" action="{{ route('settings.notifications.rules.store') }}"
                    class="mt-3 flex flex-col gap-2 border-t border-gray-100 pt-3 md:flex-row md:flex-wrap md:items-center"
                    @select-change="if (Object.keys(@js($targetLabels)).includes($event.detail)) target = $event.detail">
                    @csrf
                    <input type="hidden" name="type_id" value="{{ $type->id }}">
                    <x-simple-select name="target" size="sm" :options="$targetLabels" value="role" />
                    <div x-show="target === 'role'"><x-simple-select name="role_id" size="sm" :options="['' => __('Choose role')] + $roleOptions" value="" /></div>
                    <div x-show="target === 'user'" x-cloak><x-simple-select name="user_id" size="sm" :options="['' => __('Choose person')] + $userOptions" value="" /></div>
                    @foreach (['channel_in_app' => __('In-app'), 'channel_telegram' => 'Telegram', 'channel_sms' => 'SMS'] as $field => $label)
                        <label class="flex items-center gap-1.5 text-xs text-gray-600">
                            <input type="checkbox" name="{{ $field }}" value="1" @checked($field === 'channel_in_app') class="rounded border-gray-300 text-primary"> {{ $label }}
                        </label>
                    @endforeach
                    <button type="submit" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-dark">{{ __('Add') }}</button>
                    <button type="button" @click="adding = false" class="text-xs text-gray-500 hover:underline">{{ __('Cancel') }}</button>
                </form>
            </div>
        @endforeach
    </div>
    <x-confirm-modal id="notif-rule-delete" :verb="__('Remove')" :message="__('They stop getting this notice.')" />
</x-layouts.app>
