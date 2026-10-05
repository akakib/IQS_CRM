{{-- Grouped sidebar: group heading, then icon + label per page. The current
     page has a tinted row with a bar on its left; its group heading takes the
     brand colour. Groups fold; the choice is remembered per browser and the
     group holding the current page always opens. --}}
@php
    $user = auth()->user();
    $groups = [];
    $activeGroup = null;
    foreach (config('menu') as $key => [$label, $items]) {
        $visible = [];
        foreach ($items as $item) {
            [$itemLabel, $route, $active, $can] = $item;
            if (! \Illuminate\Support\Facades\Route::has($route) || ($can && ! $user->can($can))) {
                continue;
            }
            $isActive = request()->routeIs($active);
            $activeGroup = $isActive ? $key : $activeGroup;
            $visible[] = ['label' => __($itemLabel), 'url' => route($route), 'active' => $isActive, 'icon' => $item[4] ?? 'dot'];
        }
        if ($visible) {
            $groups[$key] = ['label' => __($label), 'items' => $visible];
        }
    }
    // Open at first: Overview, the first working group, and wherever the user is.
    $openByDefault = array_slice(array_keys($groups), 0, 2);
@endphp

<nav class="thin-scroll flex-1 overflow-y-auto px-3 py-3"
    x-data="{
        groups: {}, defaults: @js($openByDefault),
        init() {
            try { this.groups = JSON.parse(localStorage.getItem('iqs_sidebar_groups_v2') || '{}') } catch (e) { this.groups = {} }
            @if ($activeGroup) this.groups[@js($activeGroup)] = true; @endif
        },
        isOpen(key) { return this.groups[key] ?? this.defaults.includes(key) },
        toggle(key) {
            this.groups[key] = !this.isOpen(key);
            try { localStorage.setItem('iqs_sidebar_groups_v2', JSON.stringify(this.groups)) } catch (e) {}
        },
    }">
    @foreach ($groups as $key => $group)
        <div class="mb-1">
            <button type="button" @click="toggle(@js($key))" class="flex w-full items-center justify-between rounded-lg px-3 py-2 hover:bg-gray-50">
                <span @class([
                    'text-[11px] font-semibold uppercase tracking-[0.08em]',
                    'text-primary' => $key === $activeGroup,
                    'text-gray-400' => $key !== $activeGroup,
                ])>{{ $group['label'] }}</span>
                <svg class="h-3.5 w-3.5 text-gray-400 transition-transform duration-200" :class="isOpen(@js($key)) && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>
            <ul x-show="isOpen(@js($key))" @if ($key !== $activeGroup && ! in_array($key, $openByDefault, true)) x-cloak @endif class="mb-2 space-y-0.5">
                @foreach ($group['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif @class([
                            'relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm',
                            'bg-primary-soft font-semibold text-primary' => $item['active'],
                            'font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900' => ! $item['active'],
                        ])>
                            @if ($item['active'])<span class="absolute inset-y-1.5 left-0 w-[3px] rounded-full bg-primary" aria-hidden="true"></span>@endif
                            <x-icon :name="$item['icon']" @class(['h-[18px] w-[18px]', 'text-gray-400' => ! $item['active']]) />
                            <span class="truncate">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
