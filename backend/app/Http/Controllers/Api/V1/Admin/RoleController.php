<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\SaveRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return $this->ok(RoleResource::collection($this->roles->all()));
    }

    public function permissions(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return $this->ok($this->roles->catalogue());
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return $this->ok(new RoleResource($role->load('permissions')->loadCount('users')));
    }

    public function store(SaveRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->validated('name'), $request->validated('permissions'), $request->user());

        return $this->created(new RoleResource($role), 'Role created.');
    }

    public function update(SaveRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roles->update($role, $request->validated('name'), $request->validated('permissions'), $request->user());

        return $this->ok(new RoleResource($role), 'Role updated.');
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorize('delete', $role);
        $this->roles->delete($role, $request->user());

        return $this->deleted('Role deleted.');
    }
}
