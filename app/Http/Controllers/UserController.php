<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Http\Requests\UserRequest;
use App\Models\Location;
use App\Models\User;
use App\Support\Lists\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $list = ListState::from($request, ['name', 'email', 'created_at'], [
            'employment_type' => array_keys(EmploymentType::options()),
            'location' => 'int',
            'status' => ['active', 'inactive'],
        ]);
        $search = $list->search;

        // COUNT + SELECT + one eager load for location names.
        $users = User::query()
            ->select(['id', 'name', 'email', 'phone', 'employment_type', 'work_location_id', 'is_active', 'created_at'])
            ->with('workLocation:id,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', $search.'%')
                ->orWhere('email', 'like', $search.'%')
                ->orWhere('phone', 'like', $search.'%')))
            ->when($list->filter('employment_type'), fn ($q, $type) => $q->where('employment_type', $type))
            ->when($list->filter('location'), fn ($q, $id) => $q->where('work_location_id', $id))
            ->when($list->filter('status'), fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->tap(fn ($q) => $list->applySort($q))
            ->paginate($list->perPage)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'list' => $list,
            'employmentOptions' => EmploymentType::options(),
            'locationOptions' => $this->locationOptions(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', $this->formData(new User));
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = User::create([...$request->validated(), 'is_active' => true, 'email_verified_at' => now()]);

        return redirect()->route('users.index')
            ->with('success', __('Staff ":name" added.', ['name' => $user->name]));
    }

    public function edit(User $user): View
    {
        return view('users.edit', $this->formData($user));
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $passwordReset = filled($data['password'] ?? null);

        if (! $passwordReset) {
            unset($data['password']);
        }

        $user->update($data);

        if ($passwordReset) {
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            $this->logOutEverywhere($user);
        }

        return redirect()->route('users.index')->with('success', $passwordReset
            ? __('Staff ":name" updated and password reset.', ['name' => $user->name])
            : __('Staff ":name" updated.', ['name' => $user->name]));
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', __('You cannot deactivate your own account.'));
        }

        // Never deleted: deactivated staff keep their history but cannot log in.
        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $this->logOutEverywhere($user);
        }

        return back()->with('success', $user->is_active
            ? __('Staff ":name" activated.', ['name' => $user->name])
            : __('Staff ":name" deactivated.', ['name' => $user->name]));
    }

    private function logOutEverywhere(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /** @return array<string, string> */
    private function locationOptions(): array
    {
        return Location::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function formData(User $user): array
    {
        return [
            'user' => $user,
            'employmentOptions' => EmploymentType::options(),
            'locationOptions' => $this->locationOptions(),
        ];
    }
}
