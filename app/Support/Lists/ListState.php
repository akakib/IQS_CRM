<?php

namespace App\Support\Lists;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Query-string state of an index page (search, filters, sort, page size),
 * read once, whitelisted, and shared by the controller and the Blade list
 * components. A shared link or the back button reopens the same list.
 *
 *   $list = ListState::from($request, sorts: ['name', 'created_at'], filters: ['status' => ['active', 'inactive']]);
 *   $query->when($list->search, ...)->tap(fn ($q) => $list->applySort($q));
 */
final class ListState
{
    public const PER_PAGE = [25, 50, 100];

    /**
     * @param  array<string, string|null>  $filters
     */
    private function __construct(
        public readonly string $search,
        public readonly array $filters,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $perPage,
    ) {}

    /**
     * @param  list<string>  $sorts  allowed sort columns, first is the default
     * @param  array<string, list<string>|'int'|'date'>  $filters  name => allowed values, or 'int'/'date'
     */
    public static function from(Request $request, array $sorts, array $filters = [], string $defaultDir = 'asc'): self
    {
        $clean = [];
        foreach ($filters as $name => $allowed) {
            $value = trim((string) $request->query($name, ''));
            $clean[$name] = match (true) {
                $value === '' => null,
                $allowed === 'int' => ctype_digit($value) ? $value : null,
                $allowed === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null,
                default => in_array($value, $allowed, true) ? $value : null,
            };
        }

        $sort = in_array($request->query('sort'), $sorts, true) ? $request->query('sort') : $sorts[0];
        $dir = in_array($request->query('dir'), ['asc', 'desc'], true) ? $request->query('dir') : $defaultDir;
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE, true) ? (int) $request->query('per_page') : self::PER_PAGE[0];

        return new self(trim((string) $request->query('q', '')), $clean, $sort, $dir, $perPage);
    }

    public function filter(string $name): ?string
    {
        return $this->filters[$name] ?? null;
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || array_filter($this->filters, fn ($v) => $v !== null) !== [];
    }

    /** Sort by the chosen column, then id for a stable order across pages. */
    public function applySort(Builder $query, string $tieBreaker = 'id'): Builder
    {
        return $query->orderBy($this->sort, $this->dir)->orderBy($tieBreaker, $this->dir);
    }

    public function sortUrl(string $column): string
    {
        $dir = ($this->sort === $column && $this->dir === 'asc') ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null]);
    }

    public function sortIcon(string $column): string
    {
        return $this->sort === $column ? ($this->dir === 'asc' ? '↑' : '↓') : '';
    }
}
