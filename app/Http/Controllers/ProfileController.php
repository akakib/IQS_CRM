<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^01[3-9]\d{8}$/', Rule::unique('users', 'phone')->ignore($user->id)],
        ], [
            'phone.regex' => __('Phone must be 11 digits like 01XXXXXXXXX.'),
        ]);

        $user->update($data);

        return back()->with('success', __('Profile updated.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->forceFill([
            'password' => $request->input('password'),
            'remember_token' => Str::random(60),
        ])->save();

        // Keep this browser signed in, sign out every other device.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('success', __('Password changed. Other devices have been logged out.'));
    }
}
