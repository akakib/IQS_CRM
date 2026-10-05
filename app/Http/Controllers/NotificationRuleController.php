<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings > Notifications: the alert matrix (which event goes to whom, where). */
class NotificationRuleController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function index(): View
    {
        $types = DB::table('notification_types')->orderBy('id')->get();
        $rules = DB::table('notification_rules as nr')
            ->leftJoin('roles as r', 'r.id', '=', 'nr.role_id')
            ->leftJoin('users as u', 'u.id', '=', 'nr.user_id')
            ->orderBy('nr.id')
            ->get(['nr.*', 'r.name as role_name', 'u.name as user_name'])
            ->groupBy('type_id');

        return view('settings.notifications', [
            'types' => $types,
            'rules' => $rules,
            'roleOptions' => Role::orderBy('name')->pluck('name', 'id')->all(),
            'userOptions' => User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function updateType(Request $request, int $type): RedirectResponse
    {
        $data = $request->validate([
            'default_priority' => ['required', Rule::in(['info', 'normal', 'urgent'])],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $before = (array) DB::table('notification_types')->where('id', $type)->first(['default_priority', 'is_active']);
        abort_unless($before, 404);
        DB::table('notification_types')->where('id', $type)->update($data + ['updated_at' => now()]);
        $this->logger->log('notification_type.updated', ['notification_type', $type], $before, $data);

        return back()->with('success', __('Saved.'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $id = DB::table('notification_rules')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $this->logger->log('notification_rule.created', ['notification_rule', $id], null, $data);

        return back()->with('success', __('Alert rule added.'));
    }

    public function destroy(int $rule): RedirectResponse
    {
        $before = (array) DB::table('notification_rules')->where('id', $rule)->first();
        abort_unless($before, 404);
        DB::table('notification_rules')->where('id', $rule)->delete();
        $this->logger->log('notification_rule.deleted', ['notification_rule', $rule], $before);

        return back()->with('success', __('Alert rule removed.'));
    }

    public function test(Request $request, NotificationService $notifications): RedirectResponse
    {
        $notifications->send('system_test', __('Test notification'), __('If you can read this, the bell works.'), [
            'user_ids' => [$request->user()->id],
            'link' => route('settings.notifications'),
        ]);

        return back()->with('success', __('Test notification sent to you.'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type_id' => ['required', Rule::exists('notification_types', 'id')],
            'target' => ['required', Rule::in(['role', 'user', 'order_moderator', 'actor_manager'])],
            'role_id' => ['nullable', 'required_if:target,role', Rule::exists('roles', 'id')],
            'user_id' => ['nullable', 'required_if:target,user', Rule::exists('users', 'id')],
        ]);

        return [
            'type_id' => (int) $data['type_id'],
            'target' => $data['target'],
            'role_id' => $data['target'] === 'role' ? (int) $data['role_id'] : null,
            'user_id' => $data['target'] === 'user' ? (int) $data['user_id'] : null,
            'channel_in_app' => $request->boolean('channel_in_app'),
            'channel_telegram' => $request->boolean('channel_telegram'),
            'channel_sms' => $request->boolean('channel_sms'),
            'is_active' => true,
        ];
    }
}
