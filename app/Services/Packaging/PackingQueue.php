<?php

namespace App\Services\Packaging;

use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What one packer's list holds right now, in one place: everything Ready for packaging, plus the red (packed, then
 * edited: repack) and orange (label out of date) ones, minus what a manager's pause keeps back from the packers.
 * The packaging page and the dashboard's "To pack" tile read the same numbers.
 */
class PackingQueue
{
    public function __construct(private PackagingPause $pause) {}

    /** @return array{sql: string, red: string, orange: string, rfp: int} raw conditions on the orders alias `o` */
    public function sql(User $user): array
    {
        $rfp = OrderStatus::idFor('ready_for_packaging');
        $packed = implode(',', OrderStatus::idsFor(['packed', 'ready_for_pickup']));
        $red = "(o.packed_version IS NOT NULL AND o.packed_version < o.current_version AND o.status_id IN ({$packed}))";
        $orange = "(o.label_version < o.current_version AND o.status_id IN ({$rfp}, {$packed}))";
        $inQueue = "(o.status_id = {$rfp} OR {$red} OR {$orange})";
        if (! $this->pause->canControl($user) && ($hide = $this->pause->visibleSql('o')) !== '') {
            $inQueue = "({$inQueue} AND {$hide})";
        }

        return ['sql' => $inQueue, 'red' => $red, 'orange' => $orange, 'rfp' => $rfp];
    }

    /** @return array{all: int, red: int, waiting: int, mine: int} */
    public function counts(User $user): array
    {
        ['sql' => $inQueue, 'red' => $red, 'rfp' => $rfp] = $this->sql($user);
        $counts = (array) DB::table('orders as o')->whereRaw($inQueue)->selectRaw(
            "COUNT(*) as `all`, SUM(CASE WHEN {$red} THEN 1 ELSE 0 END) as red,
             SUM(CASE WHEN o.status_id = {$rfp} AND o.packer_id IS NULL THEN 1 ELSE 0 END) as waiting,
             SUM(CASE WHEN o.packer_id = ? THEN 1 ELSE 0 END) as mine", [$user->id])->first();

        return array_map('intval', $counts);
    }
}
