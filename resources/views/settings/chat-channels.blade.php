@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none')

<x-layouts.app :heading="__('Chat channels')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Every place customers write to, one row each: each WhatsApp number, each page (Messenger and comments as two channels), Instagram and so on. Give channels to people on the Staff page; they see only theirs in Chat. Orders made from a chat remember the channel, so you can see which number or page brings orders.') }}</p>

    @can('settings.edit')
        <x-card :title="__('Add a channel')" class="mb-6">
            <form method="POST" action="{{ route('settings.chat-channels.save') }}" class="grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-end">
                @csrf
                <label class="text-sm font-medium text-gray-700">{{ __('Name') }}
                    <input name="name" required maxlength="80" value="{{ old('name') }}" placeholder="{{ __('e.g. WhatsApp 2 (01711...)') }}" class="{{ $input }} mt-1">
                </label>
                <div>
                    <p class="mb-1 text-sm font-medium text-gray-700">{{ __('Type') }}</p>
                    <x-simple-select name="type" :options="$types" :value="old('type', 'whatsapp')" full-width class="w-full" />
                </div>
                <x-button>{{ __('Add') }}</x-button>
            </form>
            @error('name')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        </x-card>
    @endcan

    @if ($channels->isEmpty())
        <x-empty-state :message="__('No channels yet. Add each WhatsApp number and page above.')" />
    @else
        <div class="space-y-2">
            @foreach ($channels as $c)
                <form method="POST" action="{{ route('settings.chat-channels.save', $c) }}" class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-2 sm:items-center lg:grid-cols-[1fr_13rem_5rem_auto_auto]">
                    @csrf
                    <input name="name" required maxlength="80" value="{{ $c->name }}" class="{{ $input }}" aria-label="{{ __('Name') }}" @cannot('settings.edit') disabled @endcannot>
                    <x-simple-select name="type" :options="$types" :value="$c->type" full-width class="w-full" />
                    <input name="sort_order" type="number" min="0" max="999" value="{{ $c->sort_order }}" class="{{ $input }} tabular-nums" aria-label="{{ __('Order in the list') }}" title="{{ __('Order in the list') }}">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($c->is_active) class="rounded border-gray-300 text-primary focus:ring-primary">
                        {{ __('In use') }} <span class="text-xs text-gray-500">· {{ trans_choice('{0} nobody|{1} 1 person|[2,*] :count people', $c->users_count) }}</span>
                    </label>
                    @can('settings.edit')<x-button size="sm" variant="secondary">{{ __('Save') }}</x-button>@endcan
                </form>
            @endforeach
        </div>
    @endif
</x-layouts.app>
