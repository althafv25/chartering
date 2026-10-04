<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\AuthUserResource;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->fill([
            ...$data,
            'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
            'updated_by' => $user->id,
        ])->save();

        return $this->ok(new AuthUserResource($user->load('roles')), 'Profile updated.');
    }
}
