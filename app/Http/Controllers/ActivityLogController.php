<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    private const PER_PAGE = [25, 50, 100];

    public function index(Request $request): View
    {
        $actorId = $request->integer('actor') ?: null;
        $type = Str::of((string) $request->query('type'))->lower()->replaceMatches('/[^a-z_]/', '')->value() ?: null;
        $action = trim((string) $request->query('q', ''));
        $from = $request->date('from');
        $to = $request->date('to');
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE, true) ? (int) $request->query('per_page') : 25;

        // Fast-growing table: newest first by id, no COUNT(*) (simple pagination).
        $entries = ActivityLog::query()
            ->select(['id', 'actor_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'ip', 'created_at'])
            ->with('actor:id,name')
            ->when($actorId, fn ($q) => $q->where('actor_id', $actorId))
            ->when($type, fn ($q) => $q->where('subject_type', $type))
            ->when($action !== '', fn ($q) => $q->where('action', 'like', $action.'%'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to->endOfDay()))
            ->orderByDesc('id')
            ->simplePaginate($perPage)
            ->withQueryString();

        return view('activity.index', [
            'entries' => $entries,
            'filters' => [
                'actor' => $actorId,
                'type' => $type,
                'q' => $action,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
                'per_page' => $perPage,
            ],
            'actorOptions' => User::orderBy('name')->pluck('name', 'id')->all(),
            'typeOptions' => ['user' => __('Staff'), 'role' => __('Role'), 'location' => __('Location')],
            'perPageOptions' => self::PER_PAGE,
        ]);
    }
}
