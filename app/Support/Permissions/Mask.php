<?php

namespace App\Support\Permissions;

use App\Models\User;

final class Mask
{
    /**
     * The value itself, or a masked copy when the user's roles hide $field.
     * Phones keep the first 3 and last 2 digits: 017******78.
     */
    public static function value(?string $value, string $field, ?User $user = null): ?string
    {
        $user ??= auth()->user();

        if ($value === null || $value === '' || ($user && $user->canSeeField($field))) {
            return $value;
        }

        $length = mb_strlen($value);

        return $length <= 5
            ? str_repeat('*', $length)
            : mb_substr($value, 0, 3).str_repeat('*', $length - 5).mb_substr($value, -2);
    }
}
