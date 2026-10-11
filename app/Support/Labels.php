<?php

namespace App\Support;

/**
 * Plain names for codes the system keeps (so no screen shows "no_answer" or
 * "in_review"). One place, so the same thing has the same name everywhere.
 */
class Labels
{
    public const ISSUE_TYPES = [
        'no_answer' => 'Customer not answering', 'partial' => 'Partial delivery', 'cancel' => 'Wants to cancel', 'exchange' => 'Exchange',
        'address' => 'Address problem', 'hold' => 'Deliver later', 'other' => 'Other',
    ];

    public const RESOLUTION = ['delivered' => 'Delivered', 'rescheduled' => 'New date agreed', 'partial' => 'Partial delivery', 'returned' => 'Will be returned', 'exchange_created' => 'Exchange order made', 'solved' => 'Solved'];

    public const RISK = ['normal' => 'Normal', 'watch' => 'Watch closely', 'blocked' => 'Blocked'];

    public const ENDED = ['idle' => 'Given back: nothing done', 'timeout' => 'Given back: timer', 'break' => 'Back to New: break', 'reassigned' => 'Handed to someone else', 'left' => 'Back to New: staff left', 'finished' => 'Finished'];

    public const DISPUTE = ['open' => 'Waiting for a decision', 'upheld' => 'Upheld (point removed)', 'rejected' => 'Rejected (point stays)'];

    public static function issueType(?string $key): string
    {
        return __(self::ISSUE_TYPES[$key] ?? self::words($key));
    }

    public static function risk(?string $key): string
    {
        return __(self::RISK[$key] ?? self::words($key));
    }

    public static function ended(?string $key): string
    {
        return __(self::ENDED[$key] ?? self::words($key));
    }

    public static function dispute(?string $key): string
    {
        return __(self::DISPUTE[$key] ?? self::words($key));
    }

    /** A courier's status code ("partial_delivered", "in_review") as words. */
    public static function courierStatus(?string $key): string
    {
        return __(match ($key) {
            'partial_delivered' => 'Partly delivered', 'hold' => 'On hold at the courier', 'in_review' => 'Being checked by the courier',
            'pending' => 'Waiting for pickup', 'delivered_approval_pending', 'partial_delivered_approval_pending' => 'Delivered, waiting for approval',
            'cancelled_approval_pending' => 'Cancelled, waiting for approval', 'cancelled' => 'Returned', 'unknown', null, '' => 'Not known yet',
            default => self::words($key),
        });
    }

    private static function words(?string $key): string
    {
        return ucfirst(str_replace('_', ' ', (string) $key));
    }
}
