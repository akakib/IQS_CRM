{{-- Dark / light switch (header, beside the bell). Remembered per browser. --}}
<button type="button"
    x-data="{ dark: document.documentElement.classList.contains('dark'),
        flip() { this.dark = !this.dark; document.documentElement.classList.toggle('dark', this.dark);
            try { localStorage.setItem('iqs_theme', this.dark ? 'dark' : 'light') } catch (e) {} } }"
    @click="flip()" :aria-label="dark ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))" :title="dark ? @js(__('Light mode')) : @js(__('Dark mode'))"
    {{ $attributes->merge(['class' => 'flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-100']) }}>
    <x-icon name="moon" class="h-[18px] w-[18px]" x-show="!dark" />
    <x-icon name="sun" class="h-[18px] w-[18px]" x-show="dark" x-cloak />
</button>
