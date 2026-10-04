<?php

namespace App\Http\Controllers;

use App\Enums\LocationType;
use App\Http\Requests\LocationRequest;
use App\Models\Location;
use App\Support\Lists\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(Request $request): View
    {
        $list = ListState::from($request, ['name', 'type', 'updated_at'], [
            'type' => array_keys(LocationType::options()),
            'status' => ['active', 'inactive'],
        ]);

        // One COUNT + one SELECT of only the shown columns.
        $locations = Location::query()
            ->select(['id', 'name', 'type', 'is_active', 'updated_at'])
            ->when($list->search !== '', fn ($q) => $q->where('name', 'like', $list->search.'%'))
            ->when($list->filter('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($list->filter('status'), fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->tap(fn ($q) => $list->applySort($q))
            ->paginate($list->perPage)
            ->withQueryString();

        return view('locations.index', [
            'locations' => $locations,
            'list' => $list,
            'typeOptions' => LocationType::options(),
        ]);
    }

    public function create(): View
    {
        return view('locations.create', [
            'location' => new Location(['is_active' => true]),
            'typeOptions' => LocationType::options(),
        ]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        $location = Location::create($request->validated());

        return redirect()->route('locations.index')
            ->with('success', __('Location ":name" created.', ['name' => $location->name]));
    }

    public function edit(Location $location): View
    {
        return view('locations.edit', [
            'location' => $location,
            'typeOptions' => LocationType::options(),
        ]);
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($request->validated());

        return redirect()->route('locations.index')
            ->with('success', __('Location ":name" updated.', ['name' => $location->name]));
    }

    public function destroy(Location $location): RedirectResponse
    {
        // Soft delete: master data is never hard-deleted.
        $location->delete();

        return redirect()->route('locations.index')
            ->with('success', __('Location ":name" deleted.', ['name' => $location->name]));
    }

    /** Batch first: activate or deactivate many at once (each change is logged). */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $active = $data['action'] === 'activate';
        $changed = 0;
        Location::whereIn('id', $data['ids'])->where('is_active', ! $active)->get()
            ->each(function (Location $location) use ($active, &$changed) {
                $location->update(['is_active' => $active]);
                $changed++;
            });

        return back()->with('success', trans_choice(':count location updated.|:count locations updated.', $changed, ['count' => $changed]));
    }
}
