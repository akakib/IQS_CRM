{{-- Grouped accordion sidebar (hisab style). Open/closed state is remembered
     per browser; the group holding the current page always opens. --}}
@php
    $user = auth()->user();
    $groups = [];
    $activeGroup = null;
    foreach (config('menu') as $key => [$label, $items]) {
        $visible = [];
        foreach ($items as [$itemLabel, $route, $active, $can]) {
            if (! \Illuminate\Support\Facades\Route::has($route) || ($can && ! $user->can($can))) {
                continue;
            }
            $isActive = request()->routeIs($active);
            $activeGroup = $isActive ? $key : $activeGroup;
            $visible[] = ['label' => __($itemLabel), 'url' => route($route), 'active' => $isActive];
        }
        if ($visible) {
            $groups[$key] = ['label' => __($label), 'items' => $visible];
        }
    }
@endphp

<nav class="flex-1 overflow-y-auto px-3 py-4"
    x-data="{
        groups: {},
        init() {
            try { this.groups = JSON.parse(localStorage.getItem('iqs_sidebar_groups') || '{}') } catch (e) { this.groups = {} }
            @if ($activeGroup) this.groups[@js($activeGroup)] = true; @endif
        },
        isOpen(key) { return this.groups[key] ?? true },
        toggle(key) {
            this.groups[key] = !this.isOpen(key);
            try { localStorage.setItem('iqs_sidebar_groups', JSON.stringify(this.groups)) } catch (e) {}
        },
    }">
    @foreach ($groups as $key => $group)
        <div class="mb-2">
            <button type="button" @click="toggle(@js($key))" class="flex w-full items-center justify-between px-3 py-2">
                <span @class([
                    'text-[12px] font-medium uppercase tracking-[0.06em]',
                    'text-green-900' => $key === $activeGroup,
                    'text-gray-400' => $key !== $activeGroup,
                ])>{{ $group['label'] }}</span>
                <svg class="h-4 w-4 text-gray-400 transition-transform duration-200" :class="isOpen(@js($key)) && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>
            <ul x-show="isOpen(@js($key))" class="space-y-0.5">
                @foreach ($group['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}" @class([
                            'block rounded-lg px-3 py-2 text-sm font-medium',
                            'bg-green-50 text-green-900' => $item['active'],
                            'text-gray-600 hover:bg-gray-100' => ! $item['active'],
                        ])>{{ $item['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
