<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\ActivityLogger;
use App\Services\PermissionService;
use App\Support\Permissions\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(private PermissionService $permissions) {}

    public function index(): View
    {
        // One query: roles with live counts.
        $roles = Role::query()
            ->select(['id', 'name', 'system_key', 'description'])
            ->withCount(['permissions', 'users' => fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('user_roles.expires_at')->orWhere('user_roles.expires_at', '>', now()))])
            ->orderByRaw('system_key is null, id')
            ->get();

        return view('roles.index', ['roles' => $roles]);
    }

    public function create(): View
    {
        return view('roles.create', $this->formData(new Role, [], [], []));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create(['name' => $data['name'], 'description' => $data['description']]);
            $this->saveAccess($role, $data);

            return $role;
        });

        return redirect()->route('roles.index')->with('success', __('Role ":name" created.', ['name' => $role->name]));
    }

    public function edit(Role $role): View
    {
        $grants = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $role->id)
            ->pluck('rp.data_scope', 'p.key')->all();
        $masks = DB::table('role_field_masks')->where('role_id', $role->id)->pluck('field')->all();

        // One scope per module in the UI: the widest one granted.
        $scopes = [];
        foreach ($grants as $key => $scope) {
            $module = explode('.', $key)[0];
            $scopes[$module] = ($scopes[$module] ?? 'own') === 'all' ? 'all' : $scope;
        }

        return view('roles.edit', $this->formData($role, array_keys($grants), $scopes, $masks));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);

        DB::transaction(function () use ($role, $data) {
            $role->update(['name' => $data['name'], 'description' => $data['description']]);

            // The Owner role always has everything; its grants are not stored.
            if (! $role->isOwner()) {
                $this->saveAccess($role, $data);
            }
        });
        $this->permissions->bump();

        return redirect()->route('roles.index')->with('success', __('Role ":name" updated.', ['name' => $role->name]));
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->isOwner()) {
            return back()->with('error', __('The Owner role cannot be deleted.'));
        }

        if (DB::table('user_roles')->where('role_id', $role->id)->exists()) {
            return back()->with('error', __('Role ":name" is still given to staff. Move them to another role first.', ['name' => $role->name]));
        }

        $role->delete();
        $this->permissions->bump();

        return redirect()->route('roles.index')->with('success', __('Role ":name" deleted.', ['name' => $role->name]));
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $modules = array_keys(config('permissions.modules', []));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role?->id)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:255'],
            'grants' => ['array'],
            'grants.*' => [Rule::in(Catalog::keys())],
            'scopes' => ['array'],
            'scopes.*' => [Rule::in(['own', 'all'])],
            'masks' => ['array'],
            'masks.*' => [Rule::in(config('permissions.field_masks', []))],
        ]);

        $data['description'] ??= null;
        $data['grants'] ??= [];
        $data['masks'] ??= [];
        $data['scopes'] = array_intersect_key($data['scopes'] ?? [], array_flip($modules));

        return $data;
    }

    /** Replace a role's grants and masks with what the form sent. */
    private function saveAccess(Role $role, array $data): void
    {
        $ids = DB::table('permissions')->whereIn('key', $data['grants'])->pluck('id', 'key');
        $before = $this->accessSnapshot($role);

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        DB::table('role_permissions')->insert($ids->map(fn ($id, $key) => [
            'role_id' => $role->id,
            'permission_id' => $id,
            'data_scope' => $data['scopes'][explode('.', $key)[0]] ?? 'all',
        ])->values()->all());

        DB::table('role_field_masks')->where('role_id', $role->id)->delete();
        DB::table('role_field_masks')->insert(array_map(fn ($f) => ['role_id' => $role->id, 'field' => $f], array_unique($data['masks'])));

        $after = $this->accessSnapshot($role);
        if ($before !== $after) {
            app(ActivityLogger::class)->log('role.access_changed', $role, $before, $after);
        }

        $this->permissions->bump();
    }

    /** @return array{grants: array<string, string>, masks: list<string>} */
    private function accessSnapshot(Role $role): array
    {
        return [
            'grants' => DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $role->id)->orderBy('p.key')->pluck('rp.data_scope', 'p.key')->all(),
            'masks' => DB::table('role_field_masks')->where('role_id', $role->id)->orderBy('field')->pluck('field')->all(),
        ];
    }

    private function formData(Role $role, array $grants, array $scopes, array $masks): array
    {
        return [
            'role' => $role,
            'modules' => Catalog::modules(),
            'actions' => Catalog::ACTIONS,
            'grants' => array_flip($grants),
            'scopes' => $scopes,
            'masks' => array_flip($masks),
            'maskFields' => Catalog::maskFields(),
        ];
    }
}
