<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vessel\SaveConsumptionProfileRequest;
use App\Http\Resources\Masters\ConsumptionProfileResource;
use App\Models\Vessel;
use App\Models\VesselConsumptionProfile;
use App\Services\ConsumptionProfileService;
use Illuminate\Http\JsonResponse;

class ConsumptionProfileController extends Controller
{
    public function __construct(private readonly ConsumptionProfileService $profiles) {}

    public function index(Vessel $vessel): JsonResponse
    {
        $this->authorize('view', $vessel);

        return $this->ok(ConsumptionProfileResource::collection($vessel->consumptionProfiles()->with('rates.fuelType')->get()));
    }

    public function store(SaveConsumptionProfileRequest $request, Vessel $vessel): JsonResponse
    {
        return $this->created(new ConsumptionProfileResource($this->profiles->create($vessel, $request->validated(), $request->user())), 'Consumption profile saved.');
    }

    public function update(SaveConsumptionProfileRequest $request, Vessel $vessel, VesselConsumptionProfile $profile): JsonResponse
    {
        abort_unless($profile->vessel_id === $vessel->id, 404);

        return $this->ok(new ConsumptionProfileResource($this->profiles->update($profile, $request->validated(), $request->user())), 'Consumption profile updated.');
    }

    public function destroy(Vessel $vessel, VesselConsumptionProfile $profile): JsonResponse
    {
        $this->authorize('update', $vessel);
        abort_unless($profile->vessel_id === $vessel->id, 404);
        $this->profiles->delete($profile);

        return $this->deleted('Consumption profile deleted.');
    }
}
