{{-- Label reader: a USB / Bluetooth scanner (types the code + Enter), typing by
     hand, or the phone camera (button on the right). All emit `scan` with the
     code. The page answers with $dispatch('scan-result', { ok, message, level }) for
     a flash and a beep; level (red, orange, cod, edited) colours the camera bar like the page.
     <x-scan-input @scan="handle($event.detail)" />        camera stays open (many parcels)
     <x-scan-input once @scan="handle($event.detail)" />   camera closes after one read
     quiet = the page shows the result itself, so no message under the box. --}}
@props(['placeholder' => __('Scan a label or type the code + Enter'), 'once' => false, 'quiet' => false, 'remember' => null, 'left' => null, 'doneMessage' => null])

<div {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="scanInput({ once: {{ $once ? 'true' : 'false' }}, remember: {{ Illuminate\Support\Js::from($remember) }}, left: {{ Illuminate\Support\Js::from($left) }}, doneMessage: {{ Illuminate\Support\Js::from($doneMessage) }} })"
    @scan-result.window="result($event.detail)">
    <div class="flex items-stretch gap-2">
        <input type="text" x-model="code" @keydown.enter.prevent="submit()" autofocus autocomplete="off" inputmode="none"
            @blur="setTimeout(() => { if (!camera && document.activeElement === document.body) $el.focus() }, 150)" placeholder="{{ $placeholder }}"
            :class="state === 'ok' ? 'border-green-600 bg-green-50' : (state === 'bad' ? 'border-red-600 bg-red-50' : 'border-gray-300')"
            class="min-w-0 flex-1 rounded-xl border-2 px-4 py-4 text-center font-mono text-lg tracking-wider focus:outline-none">
        <button type="button" @click="startCamera()" class="flex w-16 shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl border-2 border-gray-300 text-gray-600 hover:border-primary hover:text-primary" aria-label="{{ __('Scan with the camera') }}">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span class="text-[10px] font-medium">{{ __('Camera') }}</span>
        </button>
    </div>
    {{-- Camera mode, when the phone did not let the camera open by itself: one tap for the next label. --}}
    <div x-show="needsTap" x-cloak class="mt-3 flex gap-2">
        <button type="button" @click="startCamera()" class="flex-1 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-dark">{{ __('Scan next') }}</button>
        <button type="button" @click="needsTap = false; closeCamera()" class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Close') }}</button>
    </div>
    <p x-show="message && !camera && {{ $quiet ? 'false' : 'true' }}" x-text="message" class="mt-2 text-center text-sm font-medium" :class="good ? 'text-green-800' : 'text-red-700'"></p>

    {{-- Camera: full screen, so the label is easy to aim at on a phone. --}}
    <template x-teleport="body">
        <div x-show="camera" x-cloak class="fixed inset-0 z-[150] flex flex-col bg-black" @keydown.escape.window="camera && closeCamera()">
            <div class="flex items-center justify-between px-4 py-3 text-white">
                <p class="text-sm font-medium">{{ __('Point the camera at the barcode') }}</p>
                <div class="flex items-center gap-2">
                    <button type="button" x-show="torchOk" @click="toggleTorch()" class="rounded-lg border border-white/30 px-3 py-1.5 text-xs font-medium" :class="torch && 'bg-white text-black'" x-text="torch ? @js(__('Light on')) : @js(__('Light'))"></button>
                    <button type="button" @click="closeCamera()" class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-black">{{ __('Close') }}</button>
                </div>
            </div>

            <div class="relative min-h-0 flex-1">
                <video x-ref="video" playsinline muted class="h-full w-full object-cover"></video>
                {{-- Aiming frame --}}
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="h-28 w-[82%] max-w-md rounded-xl border-2 transition-colors" :class="state === 'ok' ? 'border-green-400' : (state === 'bad' ? (level === 'cod' ? 'border-amber-400' : 'border-red-500') : 'border-white/80')"></div>
                </div>
                <p x-show="starting" class="absolute inset-x-0 top-1/2 mt-20 text-center text-sm text-white/80">{{ __('Starting the camera…') }}</p>

                <div x-show="cameraError" class="absolute inset-0 flex items-center justify-center bg-black/80 p-6 text-center text-white">
                    <div>
                        <p class="text-base font-semibold" x-show="cameraError === 'denied'">{{ __('The camera is blocked for this site.') }}</p>
                        <p class="mt-1 text-sm text-white/80" x-show="cameraError === 'denied'">{{ __('Allow the camera in the browser settings for this site, then try again.') }}</p>
                        <p class="text-base font-semibold" x-show="cameraError === 'unsupported'">{{ __('This browser cannot use the camera here.') }}</p>
                        <p class="text-base font-semibold" x-show="cameraError === 'unavailable'">{{ __('The camera could not be started.') }}</p>
                        <p class="mt-3 text-sm text-white/80">{{ __('You can still type the code, or use the Manual tab.') }}</p>
                    </div>
                </div>
            </div>

            {{-- What the last scan did --}}
            <div class="min-h-[72px] px-4 py-3 text-center"
                :class="!message ? 'bg-black text-white/60' : ({ ok: 'bg-green-600 text-white', edited: 'bg-purple-600 text-white', orange: 'bg-orange-500 text-white', warn: 'bg-orange-500 text-white', cod: 'bg-amber-400 text-amber-950' }[level] ?? 'bg-red-600 text-white')">
                <p x-show="message && level === 'cod'" class="mb-1 inline-block rounded-full bg-amber-950 px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide text-amber-300">{{ __('COD not updated') }}</p>
                <p class="text-base font-semibold" x-text="message || @js(__('Waiting for a barcode…'))"></p>
                <p class="font-mono text-xs opacity-80" x-show="lastCode" x-text="lastCode"></p>
            </div>
        </div>
    </template>
</div>
