<x-layouts.app :heading="__('Staff')">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ trans_choice(':count staff member|:count staff members', $users->total(), ['count' => $users->total()]) }}</p>
        @can('staff.create')
            <x-button :href="route('users.create')">+ {{ __('Add staff') }}</x-button>
        @endcan
    </div>

    <x-list.filter-bar :list="$list" :action="route('users.index')" :placeholder="__('Search name, email or phone')">
        <x-simple-select name="employment_type" :options="['' => __('All types')] + $employmentOptions" :value="$list->filter('employment_type') ?? ''" />
        <x-simple-select name="location" :options="['' => __('All locations')] + $locationOptions" :value="$list->filter('location') ?? ''" />
        <x-simple-select name="status" :options="['' => __('All statuses'), 'active' => __('Active'), 'inactive' => __('Inactive')]" :value="$list->filter('status') ?? ''" />
    </x-list.filter-bar>

    @if ($users->isEmpty())
        @if ($list->hasFilters())
            <x-empty-state :message="__('No staff match these filters.')" :action="route('users.index')" :action-label="__('Clear filters')" />
        @else
            <x-empty-state :message="__('No staff yet.')" />
        @endif
    @else
        <x-list.table>
            <x-slot:head>
                <th><x-list.sort :list="$list" column="name">{{ __('Name') }}</x-list.sort></th>
                <th><x-list.sort :list="$list" column="email">{{ __('Email') }}</x-list.sort></th>
                <th>{{ __('Phone') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Location') }}</th>
                <th>{{ __('Status') }}</th>
                <th class="text-right">{{ __('Actions') }}</th>
            </x-slot:head>
            @foreach ($users as $user)
                <tr>
                    <td class="font-medium text-gray-800">{{ $user->name }}</td>
                    <td class="text-gray-600">{{ $user->email }}</td>
                    <td class="text-gray-600">{{ $user->phone ?? '-' }}</td>
                    <td class="text-gray-600">{{ $user->employment_type?->label() ?? '-' }}</td>
                    <td class="text-gray-600">{{ $user->workLocation?->name ?? '-' }}</td>
                    <td><x-status-badge :active="$user->is_active" /></td>
                    <td class="text-right"><x-users.actions :user="$user" /></td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($users as $user)
                <x-record-card :title="$user->name" :subtitle="$user->email">
                    @if ($user->phone)<p class="text-sm text-gray-500">{{ $user->phone }}</p>@endif
                    <x-slot:badge><x-status-badge :active="$user->is_active" /></x-slot:badge>
                    <x-slot:footer>{{ collect([$user->employment_type?->label(), $user->workLocation?->name])->filter()->join(' · ') ?: '-' }}</x-slot:footer>
                    <x-slot:actions><x-users.actions :user="$user" /></x-slot:actions>
                </x-record-card>
            @endforeach
        </x-list.cards>

        <div class="mt-4">{{ $users->links() }}</div>
    @endif

    <x-confirm-modal id="user-status" />

    {{-- Deactivate: nothing held = the usual confirm; work still held = say what moves and to whom. --}}
    <div x-data="{ w: null, form: null, name: '', to: null, busy: false,
            async ask(d) {
                this.form = d.form; this.name = d.name; this.w = null; this.busy = true;
                try { this.w = await (await fetch(d.url, { headers: { Accept: 'application/json' } })).json() } catch (e) { this.w = { fresh: 0, talking: 0, issues: 0, people: [] } }
                this.busy = false;
                if (!this.w.talking && !this.w.issues) {
                    this.$dispatch('open-confirm', { id: 'user-status', form: d.form, label: d.name, verb: @js(__('Deactivate')), danger: true,
                        message: this.w.fresh ? @js(__('Their orders not called yet go back to New. They will be logged out.')) : @js(__('They will be logged out and cannot log in until activated again.')) });
                    return;
                }
                this.to = this.w.people.length ? this.w.people[0].id : null;
                this.$dispatch('open-modal', 'staff-leave');
            },
            go() { const f = document.getElementById(this.form); f.querySelector('[name=give_to]').value = this.to; f.submit() } }"
        @deactivate-staff.window="ask($event.detail)">
        <x-modal id="staff-leave" :title="__('Deactivate staff')" persistent>
            <template x-if="w">
                <div class="space-y-3 text-sm">
                    <p class="text-gray-700"><b x-text="name"></b> {{ __('still holds work. They will be logged out.') }}</p>
                    <ul class="space-y-1 rounded-lg bg-gray-50 p-3 text-gray-700">
                        <li x-show="w.talking"><b x-text="w.talking"></b> {{ __('orders already talked about (No answer, Hold, advance, not booked)') }}</li>
                        <li x-show="w.issues"><b x-text="w.issues"></b> {{ __('open delivery issues') }}</li>
                        <li x-show="w.fresh" class="text-gray-500"><b x-text="w.fresh"></b> {{ __('orders not called yet go back to New') }}</li>
                    </ul>
                    <p class="font-medium text-gray-800">{{ __('Who takes over? One person, so the customer hears from someone who can read the whole story.') }}</p>
                    <p x-show="!w.people.length" class="text-red-600">{{ __('Nobody else can take orders. Give someone order access first.') }}</p>
                    <div class="max-h-64 space-y-1 overflow-y-auto">
                        <template x-for="p in w.people" :key="p.id">
                            <button type="button" @click="to = p.id" class="flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left"
                                :class="to === p.id ? 'border-primary bg-primary-soft text-primary' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                                <span class="font-medium" x-text="p.name"></span>
                                <span class="text-xs" x-text="p.load + ' ' + @js(__('open orders'))"></span>
                            </button>
                        </template>
                    </div>
                    <p class="text-xs text-gray-500">{{ __('The one with the fewest open orders is picked first. Orders already sent to packaging stay in their name.') }}</p>
                </div>
            </template>
            <x-slot:footer>
                <x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'staff-leave')">{{ __('Cancel') }}</x-button>
                <x-button type="button" variant="danger" ::disabled="!to" @click="go()">{{ __('Deactivate and hand over') }}</x-button>
            </x-slot:footer>
        </x-modal>
    </div>
</x-layouts.app>
