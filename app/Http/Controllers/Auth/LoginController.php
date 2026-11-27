<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ]),
            ]);
        }

        // Deactivated staff get the same generic message as a wrong password.
        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        // Back to the page they were sent away from, except the Dashboard or the home address: those mean "my first page",
        // which for staff who work orders is Order management (User::homeUrl).
        $intended = (string) $request->session()->pull('url.intended', '');
        $path = rtrim((string) parse_url($intended, PHP_URL_PATH), '/');

        return redirect($intended !== '' && ! in_array($path, ['', '/dashboard'], true) ? $intended : $request->user()->homeUrl());
    }

    /** "/" opens each person's own first page (User::homeUrl). */
    public function home(Request $request): RedirectResponse
    {
        return redirect($request->user()->homeUrl());
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user()) {
            app(\App\Services\Work\ChatService::class)->stop($request->user()); // logging out ends Communication
        }
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
