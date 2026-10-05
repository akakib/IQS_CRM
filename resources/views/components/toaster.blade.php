{{-- Global toasts. From JS: window.toast('Saved') / window.toast('Failed', 'error').
     Session flashes (success/error) are shown here too. Placed once in the layout. --}}
<div x-data="{
        items: [],
        init() {
            window.toast = (m, t) => this.push(m, t);
            @if (session('success') && ! session('undo')) this.push(@js(session('success')), 'success'); @endif
            @if (session('error')) this.push(@js(session('error')), 'error'); @endif
        },
        push(message, type = 'success') {
            if (!message) return;
            const id = Date.now() + Math.random();
            this.items.push({ id, message, type });
            setTimeout(() => this.items = this.items.filter(i => i.id !== id), type === 'error' ? 6000 : 3500);
        },
    }"
    {{-- Setup lives in init() (Alpine runs it once), not x-init: Alpine calls a
         function that x-init evaluates to, so "window.toast = fn" there fired
         an empty toast on every page load. --}}
    @toast.window="push($event.detail.message, $event.detail.type)"
    class="pointer-events-none fixed inset-x-3 top-16 z-[95] flex flex-col items-end gap-2 sm:inset-x-auto sm:right-4">
    <template x-for="item in items" :key="item.id">
        <div class="pointer-events-auto w-full max-w-sm rounded-lg border px-4 py-3 text-sm shadow-lg"
            :class="item.type === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-800'"
            x-text="item.message"></div>
    </template>
</div>
