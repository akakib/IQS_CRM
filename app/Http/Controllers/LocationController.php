<?php

namespace App\Http\Controllers;

use App\Enums\LocationType;
use App\Http\Requests\LocationRequest;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LocationController extends Controller
{
    private const SORTS = ['name', 'type', 'updated_at'];

    private const PER_PAGE = [25, 50, 100];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $type = LocationType::tryFrom((string) $request->query('type'));
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : null;
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE, true) ? (int) $request->query('per_page') : 25;

        // One COUNT + one SELECT of only the shown columns.
        $locations = Location::query()
            ->select(['id', 'name', 'type', 'is_active', 'updated_at'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', $search.'%'))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy($sort, $dir)
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('locations.index', [
            'locations' => $locations,
            'filters' => [
                'q' => $search,
                'type' => $type?->value,
                'status' => $status,
                'sort' => $sort,
                'dir' => $dir,
                'per_page' => $perPage,
            ],
            'typeOptions' => LocationType::options(),
            'perPageOptions' => self::PER_PAGE,
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
}
