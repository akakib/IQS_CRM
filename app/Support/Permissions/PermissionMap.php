<?php

namespace App\Support\Permissions;

/**
 * Resolved permissions of one user: permission key => data scope, plus the
 * set of masked fields. Plain arrays so it caches cheaply; every check is O(1).
 */
final class PermissionMap
{
    public const SCOPE_RANK = ['own' => 1, 'team' => 2, 'all' => 3];

    /**
     * @param  array<string, string>  $scopes  key => own|team|all
     * @param  array<string, true>  $masks  field => true
     */
    public function __construct(
        public readonly bool $isOwner,
        public readonly array $scopes,
        public readonly array $masks,
    ) {}

    public function allows(string $key): bool
    {
        return $this->isOwner || isset($this->scopes[$key]);
    }

    public function scope(string $key): ?string
    {
        return $this->isOwner ? 'all' : ($this->scopes[$key] ?? null);
    }

    public function hides(string $field): bool
    {
        return ! $this->isOwner && isset($this->masks[$field]);
    }

    public function toArray(): array
    {
        return ['owner' => $this->isOwner, 'scopes' => $this->scopes, 'masks' => $this->masks];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['owner'], $data['scopes'], $data['masks']);
    }
}
