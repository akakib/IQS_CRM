<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Permissions\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Owner-only: which roles a staff member has, plus their personal allow/deny. */
class UserAccessController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function edit(User $user): View
    {
        $assignments = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('users as by', 'by.id', '=', 'ur.assigned_by')
            ->where('ur.user_id', $user->id)
            ->whereNull('r.deleted_at')
            ->orderBy('r.name')
            ->get(['ur.id', 'r.name', 'r.system_key', 'ur.starts_at', 'ur.expires_at', 'ur.reason', 'by.name as by_name']);

        $overrides = DB::table('user_permissions as up')
            ->join('permissions as p', 'p.id', '=', 'up.permission_id')
            ->leftJoin('users as by', 'by.id', '=', 'up.granted_by')
            ->where('up.user_id', $user->id)
            ->orderBy('p.sort_order')
            ->get(['up.id', 'p.key', 'up.effect', 'up.data_scope', 'up.starts_at', 'up.expires_at', 'up.reason', 'by.name as by_name']);

        $map = $this->permissions->for($user);

        return view('users.access', [
            'user' => $user,
            'assignments' => $assignments,
            'overrides' => $overrides,
            'roleOptions' => Role::orderBy('name')->pluck('name', 'id')->all(),
            'permissionOptions' => Catalog::options(),
            'modules' => Catalog::modules(),
            'map' => $map,
            'maskFields' => Catalog::maskFields(),
        ]);
    }

    public function storeRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role_id' => ['required', Rule::exists('roles', 'id')->whereNull('deleted_at')],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:now', 'after:starts_at'],
            'reason' => [Rule::requiredIf(fn () => $request->filled('expires_at')), 'nullable', 'string', 'max:255'],
        ], ['reason.required' => __('Give a reason for temporary access.')]);

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $data['role_id'],
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'reason' => $data['reason'] ?? null,
            'assigned_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->permissions->bump();

        return back()->with('success', __('Role given to :name.', ['name' => $user->name]));
    }

    public function destroyRole(Request $request, User $user, int $assignment): RedirectResponse
    {
        $row = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.id', $assignment)->where('ur.user_id', $user->id)
            ->first(['ur.id', 'r.system_key']);
        abort_unless($row, 404);

        // Never leave the system without an Owner.
        if ($row->system_key === Role::OWNER && $this->activeOwnerCount() <= 1) {
            return back()->with('error', __('This is the last Owner. Give someone else the Owner role first.'));
        }

        DB::table('user_roles')->where('id', $row->id)->delete();
        $this->permissions->bump();

        return back()->with('success', __('Role removed.'));
    }

    public function storeOverride(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'permission' => ['required', Rule::in(Catalog::keys())],
            'effect' => ['required', Rule::in(['allow', 'deny'])],
            'data_scope' => ['nullable', Rule::in(['own', 'all'])],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:now', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::table('user_permissions')->insert([
            'user_id' => $user->id,
            'permission_id' => DB::table('permissions')->where('key', $data['permission'])->value('id'),
            'effect' => $data['effect'],
            'data_scope' => $data['effect'] === 'allow' ? ($data['data_scope'] ?? 'all') : null,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'reason' => $data['reason'],
            'granted_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->permissions->bump();

        return back()->with('success', $data['effect'] === 'allow' ? __('Access allowed.') : __('Access denied.'));
    }

    public function destroyOverride(User $user, int $override): RedirectResponse
    {
        $deleted = DB::table('user_permissions')->where('id', $override)->where('user_id', $user->id)->delete();
        abort_unless($deleted, 404);
        $this->permissions->bump();

        return back()->with('success', __('Custom access removed.'));
    }

    private function activeOwnerCount(): int
    {
        return DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('r.system_key', Role::OWNER)
            ->where('u.is_active', true)
            ->where(fn ($q) => $q->whereNull('ur.expires_at')->orWhere('ur.expires_at', '>', now()))
            ->distinct()->count('ur.user_id');
    }
}
