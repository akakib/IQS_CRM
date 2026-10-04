<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** The bell: every query is scoped to the signed-in user and uses (user_id, read_at, id). */
class NotificationController extends Controller
{
    /** Polled every ~45 s: one indexed aggregate query. */
    public function count(Request $request): JsonResponse
    {
        $row = DB::table('app_notifications')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->selectRaw("COUNT(*) as unread, SUM(CASE WHEN priority = 'urgent' AND acted_at IS NULL THEN 1 ELSE 0 END) as urgent")
            ->first();

        return response()->json(['unread' => (int) $row->unread, 'urgent' => (int) $row->urgent]);
    }

    public function feed(Request $request): JsonResponse
    {
        $filter = $request->query('filter', 'all');

        $items = DB::table('app_notifications')
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subDays((int) config('notifications.dropdown_days', 30)))
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($filter === 'urgent', fn ($q) => $q->where('priority', 'urgent')->whereNull('acted_at'))
            ->orderByDesc('updated_at')
            ->limit(15)
            ->get(['id', 'priority', 'title', 'body', 'group_count', 'read_at', 'acted_at', 'updated_at']);

        return response()->json($items->map(fn ($n) => [
            'id' => $n->id,
            'title' => $n->group_count > 1 ? $n->title.' (×'.$n->group_count.')' : $n->title,
            'body' => $n->body,
            'urgent' => $n->priority === 'urgent' && ! $n->acted_at,
            'unread' => $n->read_at === null,
            'ago' => \Illuminate\Support\Carbon::parse($n->updated_at)->diffForHumans(),
            'url' => route('notifications.open', $n->id),
        ]));
    }

    public function open(Request $request, int $id): RedirectResponse
    {
        $n = DB::table('app_notifications')->where('id', $id)->where('user_id', $request->user()->id)->first(['id', 'link_url', 'read_at']);
        abort_unless($n, 404);

        if (! $n->read_at) {
            DB::table('app_notifications')->where('id', $n->id)->update(['read_at' => now()]);
        }

        return redirect($n->link_url ?: route('notifications.index'));
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        DB::table('app_notifications')->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /** Full history: nothing is ever deleted. Simple pagination, no COUNT. */
    public function index(Request $request): View
    {
        $items = DB::table('app_notifications as n')
            ->join('notification_types as t', 't.id', '=', 'n.type_id')
            ->where('n.user_id', $request->user()->id)
            ->orderByDesc('n.id')
            ->select(['n.id', 'n.priority', 'n.title', 'n.body', 'n.group_count', 'n.read_at', 'n.acted_at', 'n.created_at', 't.name as type'])
            ->simplePaginate(25);

        return view('notifications.index', ['items' => $items]);
    }
}
