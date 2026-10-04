<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\VesselType;
use App\Services\ReferenceDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReferenceDataController extends Controller
{
    public function __construct(private readonly ReferenceDataService $reference) {}

    public function catalogue(): JsonResponse
    {
        $this->authorize(Permission::MastersView->value);

        return $this->ok($this->reference->catalogue());
    }

    /** Any authenticated user may read active lookup values for pickers. */
    public function index(Request $request, string $type): JsonResponse
    {
        $activeOnly = ! $request->user()->can(Permission::MastersView->value) || $request->boolean('active');

        return $this->ok($this->reference->list($type, $activeOnly));
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $this->authorize(Permission::MastersUpdate->value);
        $data = $request->validate($this->reference->rules($type));

        return $this->created($this->reference->create($type, $data), 'Saved.');
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorize(Permission::MastersUpdate->value);
        $item = $this->reference->find($type, $id);
        $data = $request->validate($this->reference->rules($type, $item));

        return $this->ok($this->reference->update($item, $data), 'Saved.');
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $this->authorize(Permission::MastersUpdate->value);
        $this->reference->delete($this->reference->find($type, $id));

        return $this->deleted();
    }

    /** Type-specific configurable vessel fields. */
    public function updateVesselTypeAttributes(Request $request, VesselType $vesselType): JsonResponse
    {
        $this->authorize(Permission::MastersUpdate->value);

        $data = $request->validate([
            'attribute_schema' => ['present', 'array', 'max:40'],
            'attribute_schema.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'attribute_schema.*.label' => ['required', 'string', 'max:80'],
            'attribute_schema.*.data_type' => ['required', Rule::in(['decimal', 'integer', 'string', 'bool', 'select'])],
            'attribute_schema.*.unit' => ['nullable', 'string', 'max:20'],
            'attribute_schema.*.options' => ['nullable', 'array', 'required_if:attribute_schema.*.data_type,select'],
            'attribute_schema.*.options.*' => ['string', 'max:60'],
        ]);

        $schema = array_map(fn ($a) => [
            'key' => $a['key'], 'label' => $a['label'], 'data_type' => $a['data_type'],
            'unit' => $a['unit'] ?? null, 'options' => $a['data_type'] === 'select' ? array_values($a['options'] ?? []) : null,
        ], $data['attribute_schema']);

        $vesselType->forceFill(['attribute_schema' => $schema])->save();

        return $this->ok($vesselType->fresh(), 'Vessel type fields saved.');
    }
}
