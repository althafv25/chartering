<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role' => ['nullable', 'string', 'max:60'],
            'sort' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->ok(UserResource::collection($this->users->paginate($filters, $this->perPage($request))));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return $this->created(new UserResource($this->users->create($request->validated(), $request->user())), 'User created.');
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return $this->ok(new UserResource($user->load('roles')));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        return $this->ok(new UserResource($this->users->update($user, $request->validated(), $request->user())), 'User updated.');
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);

        $user = $this->users->setStatus($user, UserStatus::from($data['status']), $request->user());

        return $this->ok(new UserResource($user), 'User status updated.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);
        $this->users->delete($user, $request->user());

        return $this->deleted('User deleted.');
    }
}
