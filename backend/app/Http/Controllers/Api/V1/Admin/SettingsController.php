<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(): JsonResponse
    {
        $this->authorize(Permission::SettingsView->value);

        return $this->ok($this->settings->grouped());
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize(Permission::SettingsUpdate->value);
        $request->validate(['settings' => ['required', 'array', 'min:1']]);

        $this->settings->update($request->input('settings'), $request->user());

        return $this->ok($this->settings->grouped(), 'Settings saved.');
    }
}
