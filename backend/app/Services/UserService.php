<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UserService
{
    private const SORTABLE = ['name', 'email', 'status', 'last_login_at', 'created_at'];

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = User::query()->with('roles');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('job_title', 'like', "%{$search}%"));
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($role = $filters['role'] ?? null) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        [$column, $direction] = $this->sort($filters['sort'] ?? 'name');

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::query()->create([
                ...Arr::only($data, ['first_name', 'last_name', 'phone', 'job_title', 'timezone', 'password']),
                'name' => $this->displayName($data),
                'email' => mb_strtolower($data['email']),
                'status' => $data['status'] ?? UserStatus::Active->value,
                'password_changed_at' => now(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->assignRoles($user, $data['roles'] ?? [], $actor);

            return $user->load('roles');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $fields = Arr::only($data, ['first_name', 'last_name', 'phone', 'job_title', 'timezone']);

            if (array_key_exists('email', $data)) {
                $fields['email'] = mb_strtolower($data['email']);
            }
            if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data)) {
                $fields['name'] = $this->displayName([...$user->only('first_name', 'last_name'), ...$data]);
            }
            if (! empty($data['password'])) {
                $fields['password'] = $data['password'];
                $fields['password_changed_at'] = now();
            }

            $user->fill([...$fields, 'updated_by' => $actor->id])->save();

            if (! empty($data['password'])) {
                $user->tokens()->delete();
            }

            if (array_key_exists('roles', $data)) {
                $this->assignRoles($user, $data['roles'], $actor);
            }

            return $user->load('roles');
        });
    }

    public function setStatus(User $user, UserStatus $status, User $actor): User
    {
        if ($user->is($actor) && $status === UserStatus::Inactive) {
            throw new BusinessRuleException('You cannot deactivate your own account.', 'cannot_deactivate_self');
        }

        $this->guardLastSuperAdmin($user, $status === UserStatus::Inactive);

        $user->fill(['status' => $status, 'updated_by' => $actor->id])->save();

        if ($status === UserStatus::Inactive) {
            $user->tokens()->delete();
        }

        return $user->load('roles');
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new BusinessRuleException('You cannot delete your own account.', 'cannot_delete_self');
        }

        $this->guardLastSuperAdmin($user, true);

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete(); // soft delete; audit history keeps the causer reference
        });
    }

    /** @param list<string> $roles */
    private function assignRoles(User $user, array $roles, User $actor): void
    {
        $removingSuper = $user->hasRole(UserRole::SuperAdmin->value) && ! in_array(UserRole::SuperAdmin->value, $roles, true);

        // Only a Super Admin may grant or revoke Super Admin.
        if ((in_array(UserRole::SuperAdmin->value, $roles, true) || $removingSuper) && ! $actor->hasRole(UserRole::SuperAdmin->value)) {
            throw new BusinessRuleException('Only a Super Admin can grant or revoke the Super Admin role.', 'super_admin_required', status: 403);
        }

        if ($removingSuper) {
            $this->guardLastSuperAdmin($user, true);
        }

        $before = $user->getRoleNames()->sort()->values()->all();
        $user->syncRoles($roles);
        $after = $user->getRoleNames()->sort()->values()->all();

        if ($before !== $after) {
            activity('users')->performedOn($user)->causedBy($actor)->event('roles_changed')
                ->withProperties(['old' => ['roles' => $before], 'attributes' => ['roles' => $after]])
                ->log('User roles changed');
        }
    }

    private function guardLastSuperAdmin(User $user, bool $removing): void
    {
        if (! $removing || ! $user->hasRole(UserRole::SuperAdmin->value)) {
            return;
        }

        $remaining = User::role(UserRole::SuperAdmin->value)
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($user->id)->count();

        if ($remaining === 0) {
            throw new BusinessRuleException('At least one active Super Admin must remain.', 'last_super_admin');
        }
    }

    /** @param array<string, mixed> $data */
    private function displayName(array $data): string
    {
        return trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: (string) ($data['name'] ?? '');
    }

    /** @return array{0:string,1:string} */
    private function sort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        return [in_array($column, self::SORTABLE, true) ? $column : 'name', $direction];
    }
}
