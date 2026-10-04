<?php

namespace App\Services;

use App\Enums\Permission as PermissionEnum;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    /** @return Collection<int, Role> */
    public function all(): Collection
    {
        return Role::query()->withCount('users')->with('permissions')->orderBy('name')->get();
    }

    /**
     * Permission catalogue grouped by module for the role editor.
     *
     * @return list<array{module:string, permissions:list<array{name:string, action:string}>}>
     */
    public function catalogue(): array
    {
        return collect(PermissionEnum::cases())
            ->groupBy(fn (PermissionEnum $p) => $p->module())
            ->map(fn ($items, $module) => [
                'module' => $module,
                'permissions' => $items->map(fn (PermissionEnum $p) => ['name' => $p->value, 'action' => $p->action()])->values()->all(),
            ])->values()->all();
    }

    /** @param list<string> $permissions */
    public function create(string $name, array $permissions, User $actor): Role
    {
        return DB::transaction(function () use ($name, $permissions, $actor) {
            /** @var Role $role */
            $role = Role::create(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
            $this->flush();

            activity('roles')->performedOn($role)->causedBy($actor)->event('created')
                ->withProperties(['attributes' => ['name' => $name, 'permissions' => $permissions]])
                ->log('Role created');

            return $role->load('permissions')->loadCount('users');
        });
    }

    /** @param list<string> $permissions */
    public function update(Role $role, ?string $name, array $permissions, User $actor): Role
    {
        if ($role->name === UserRole::SuperAdmin->value) {
            throw new BusinessRuleException('The Super Admin role has implicit full access and cannot be edited.', 'role_locked');
        }

        if ($name !== null && $name !== $role->name && UserRole::isSystem($role->name)) {
            throw new BusinessRuleException('System roles cannot be renamed.', 'role_locked');
        }

        return DB::transaction(function () use ($role, $name, $permissions, $actor) {
            $before = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];

            if ($name !== null) {
                $role->update(['name' => $name]);
            }
            $role->syncPermissions($permissions);
            $this->flush();

            $after = ['name' => $role->name, 'permissions' => collect($permissions)->sort()->values()->all()];
            if ($before !== $after) {
                activity('roles')->performedOn($role)->causedBy($actor)->event('updated')
                    ->withProperties(['old' => $before, 'attributes' => $after])
                    ->log('Role permissions updated');
            }

            return $role->load('permissions')->loadCount('users');
        });
    }

    public function delete(Role $role, User $actor): void
    {
        if (UserRole::isSystem($role->name)) {
            throw new BusinessRuleException('System roles cannot be deleted.', 'role_locked');
        }

        if ($role->users()->exists()) {
            throw new BusinessRuleException('Remove all users from this role before deleting it.', 'role_in_use');
        }

        activity('roles')->performedOn($role)->causedBy($actor)->event('deleted')
            ->withProperties(['old' => ['name' => $role->name]])->log('Role deleted');

        $role->delete();
        $this->flush();
    }

    private function flush(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
