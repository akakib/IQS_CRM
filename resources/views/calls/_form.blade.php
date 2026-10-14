{{-- Name, purpose, callers, target and what to say: the same on New and Edit. --}}
@php use App\Services\Calls\CallListService; @endphp

<div>
    <label class="block text-xs font-medium text-gray-700">{{ __('Name') }}</label>
    <input type="text" name="name" value="{{ old('name', $list->name ?? '') }}" required maxlength="120" placeholder="{{ __('Feedback, July buyers') }}"
        class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-base focus:border-primary focus:outline-none">
    @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-xs font-medium text-gray-700">{{ __('What the call is for') }}</label>
        <x-simple-select name="purpose" :options="array_map(fn ($l) => __($l), CallListService::PURPOSES)" :value="old('purpose', $list->purpose ?? 'feedback')" full-width class="mt-1" />
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-700">{{ __('Calls a day, each person') }}</label>
        <input type="number" name="daily_target" min="1" max="500" value="{{ old('daily_target', $list->daily_target ?? 50) }}" required
            class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-base focus:border-primary focus:outline-none">
        @error('daily_target')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
</div>

<div>
    <p class="text-xs font-medium text-gray-700">{{ __('Who calls') }}</p>
    <p class="text-xs text-gray-500">{{ __('They share the list: whoever presses Next call gets the next number, so nothing waits on someone who is away.') }}</p>
    <div class="mt-2 flex flex-wrap gap-2">
        @forelse ($callers as $id => $name)
            <label class="cursor-pointer">
                <input type="checkbox" name="callers[]" value="{{ $id }}" class="peer sr-only" @checked(in_array($id, old('callers', $assigned)))>
                <span class="inline-block rounded-full border border-gray-300 px-3 py-1.5 text-sm text-gray-700 peer-checked:border-primary peer-checked:bg-green-50 peer-checked:font-medium peer-checked:text-primary">{{ $name }}</span>
            </label>
        @empty
            <p class="text-sm text-amber-700">{{ __('Nobody may make calls yet. Give a role the permission "Make calls from the lists given to them" under Roles.') }}</p>
        @endforelse
    </div>
    @error('callers')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-xs font-medium text-gray-700">{{ __('What to say (shown to the caller)') }}</label>
    <textarea name="instructions" rows="3" maxlength="2000" placeholder="{{ __('Ask how the dates were, offer 5% on the next order...') }}"
        class="mt-1 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-base focus:border-primary focus:outline-none">{{ old('instructions', $list->instructions ?? '') }}</textarea>
</div>
