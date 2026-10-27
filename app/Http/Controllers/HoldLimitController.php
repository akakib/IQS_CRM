<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\Orders\DeskService;
use App\Services\Work\DeskRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * More holds: a person at their hold limit asks with a reason (desk), managers are told, and a manager
 * sets that person's new number (any, e.g. 5 to 10) or says no. The number stays on the person (Staff page).
 */
class HoldLimitController extends Controller
{
    public function __construct(private DeskRules $rules, private NotificationService $notifications, private ActivityLogger $logger) {}

    public function ask(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        if (DB::table('hold_limit_requests')->where('user_id', $user->id)->where('status', 'pending')->exists()) {
            return back()->with('error', __('You already asked. Your manager will answer.'));
        }
        $limit = $this->rules->holdLimit($user->id);
        DB::table('hold_limit_requests')->insert(['user_id' => $user->id, 'current_limit' => $limit, 'reason' => $data['reason'], 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->notifications->send('hold_limit_request', __(':n asks for more holds (now :l)', ['n' => $user->name, 'l' => $limit]), $data['reason'], [
            'link' => route('hold-limits.index'), 'user_ids' => app(DeskService::class)->managerIds(),
        ]);

        return back()->with('success', __('Sent to your manager.'));
    }

    public function index(): View
    {
        return view('desk.hold-limits', [
            'pending' => DB::table('hold_limit_requests as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.status', 'pending')
                ->orderBy('r.id')->get(['r.*', 'u.name']),
            'recent' => DB::table('hold_limit_requests as r')->join('users as u', 'u.id', '=', 'r.user_id')->leftJoin('users as d', 'd.id', '=', 'r.decided_by')
                ->where('r.status', '!=', 'pending')->orderByDesc('r.decided_at')->limit(20)->get(['r.*', 'u.name', 'd.name as decider']),
        ]);
    }

    public function decide(Request $request, int $id): RedirectResponse
    {
        $row = DB::table('hold_limit_requests')->where('id', $id)->where('status', 'pending')->first() ?? abort(404);
        abort_if((int) $row->user_id === $request->user()->id, 403, __('Your own request is decided by another manager.'));
        $data = $request->validate(['approve' => ['required', 'boolean'], 'new_limit' => ['required_if:approve,1', 'nullable', 'integer', 'min:1', 'max:50']]);
        $person = User::findOrFail($row->user_id);
        $approve = (bool) $data['approve'];
        DB::transaction(function () use ($row, $data, $approve, $person, $request) {
            DB::table('hold_limit_requests')->where('id', $row->id)->update(['status' => $approve ? 'approved' : 'rejected',
                'new_limit' => $approve ? (int) $data['new_limit'] : null, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'updated_at' => now()]);
            if ($approve) {
                $old = $person->desk_hold_limit;
                $person->forceFill(['desk_hold_limit' => (int) $data['new_limit']])->save();
                $this->rules->forget($person->id);
                $this->logger->log('user.updated', $person, ['desk_hold_limit' => $old], ['desk_hold_limit' => (int) $data['new_limit']]);
            }
        });
        $this->notifications->send('hold_limit_request', $approve ? __('More holds: you can now hold :n orders', ['n' => (int) $data['new_limit']]) : __('More holds: your manager said no'),
            null, ['link' => route('desk.index'), 'user_ids' => [$person->id], 'only' => true]);

        return back()->with('success', $approve ? __(':n can now hold :l orders.', ['n' => $person->name, 'l' => (int) $data['new_limit']]) : __('Request turned down.'));
    }
}
