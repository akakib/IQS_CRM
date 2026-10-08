<?php

namespace App\Http\Controllers;

use App\Models\ChatChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Chats: per channel and per person, what was answered, how many became
 * orders and why the rest did not (the reasons staff pick in Chat). Four
 * queries for any range.
 */
class ChatReportController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = Carbon::parse($data['to'] ?? today())->min(today());
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(6));
        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92);
        }
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $events = DB::table('chat_events')->whereBetween('created_at', $range)
            ->groupBy('user_id', 'chat_channel_id', 'kind', 'reason_id')
            ->selectRaw('user_id, chat_channel_id, kind, reason_id, COUNT(*) as n')->get();
        $orders = DB::table('orders')->whereNotNull('chat_channel_id')->whereBetween('created_at', $range)
            ->groupBy('created_by', 'chat_channel_id')->selectRaw('created_by, chat_channel_id, COUNT(*) as n, SUM(grand_total) as total')->get();
        $channels = ChatChannel::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'type'])->keyBy('id');
        $reasons = DB::table('status_reasons')->where('reason_type', 'chat_lost')->orderBy('sort_order')->pluck('label_en', 'id');
        $people = DB::table('users')->whereIn('id', $events->pluck('user_id')->merge($orders->pluck('created_by'))->unique())->pluck('name', 'id');

        // One row of numbers for any group (a channel, a person, everything).
        $sum = function ($ev, $or) {
            $messages = (int) $ev->where('kind', 'message')->sum('n') - (int) $ev->where('kind', 'undo')->sum('n');
            $lost = (int) $ev->where('kind', 'no_order')->sum('n');
            $made = (int) $or->sum('n');

            return [
                'messages' => $messages, 'orders' => $made, 'lost' => $lost, 'sales' => (float) $or->sum('total'),
                // Of the chats that ended (an order or a "no order"), how many became an order.
                'rate' => $made + $lost > 0 ? (int) round($made * 100 / ($made + $lost)) : null,
                'reasons' => $ev->where('kind', 'no_order')->groupBy('reason_id')->map(fn ($g) => (int) $g->sum('n'))->sortDesc(),
            ];
        };

        return view('reports.chats', [
            'from' => $from, 'to' => $to,
            'total' => $sum($events, $orders),
            'byChannel' => $channels->map(fn ($c) => ['name' => $c->name, 'type' => ChatChannel::TYPES[$c->type] ?? $c->type]
                + $sum($events->where('chat_channel_id', $c->id), $orders->where('chat_channel_id', $c->id)))
                ->filter(fn ($r) => $r['messages'] || $r['orders'] || $r['lost']),
            'byPerson' => $people->map(fn ($name, $id) => ['name' => $name] + $sum($events->where('user_id', $id), $orders->where('created_by', $id)))
                ->sortByDesc('orders'),
            'reasons' => $reasons,
        ]);
    }
}
