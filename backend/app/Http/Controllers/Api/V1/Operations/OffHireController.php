<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\OffHireResource;
use App\Models\OffHireEvent;
use App\Models\Voyage;
use App\Services\Operations\OffHireService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OffHireController extends Controller
{
    public function __construct(private readonly OffHireService $offHire) {}

    public function store(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.off-hire.manage');
        $e = $this->offHire->save($voyage, null, $this->rules($request, true), $request->user());

        return $this->created($this->res($e), 'Off-hire recorded.');
    }

    public function update(Request $request, Voyage $voyage, OffHireEvent $offHire): JsonResponse
    {
        $this->authorize('operations.off-hire.manage');

        return $this->ok($this->res($this->offHire->save($voyage, $offHire, $this->rules($request, false), $request->user())), 'Off-hire updated.');
    }

    public function agree(Request $request, Voyage $voyage, OffHireEvent $offHire): JsonResponse
    {
        $this->authorize('operations.off-hire.agree');
        $d = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return $this->ok($this->res($this->offHire->decide($offHire, 'agreed', $request->user(), $d['comment'] ?? null)), 'Off-hire agreed.');
    }

    public function dispute(Request $request, Voyage $voyage, OffHireEvent $offHire): JsonResponse
    {
        $this->authorize('operations.off-hire.agree');
        $d = $request->validate(['comment' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->offHire->decide($offHire, 'disputed', $request->user(), $d['comment'])), 'Off-hire marked disputed.');
    }

    public function destroy(Voyage $voyage, OffHireEvent $offHire): JsonResponse
    {
        $this->authorize('operations.off-hire.manage');
        $this->offHire->delete($offHire);

        return $this->deleted('Off-hire removed.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        return $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'from_at' => [$creating ? 'required' : 'sometimes', 'string', 'max:40'],
            'to_at' => ['nullable', 'string', 'max:40'],
            'reason_code' => [$creating ? 'required' : 'sometimes', Rule::in(OffHireEvent::REASONS)],
            'description' => ['nullable', 'string', 'max:1000'],
            'fuel_consumed' => ['nullable', 'array', 'max:10'],
            'fuel_consumed.*.fuel_type_id' => ['required', 'integer', Rule::exists('fuel_types', 'id')],
            'fuel_consumed.*.mt' => Rules::decimal(9, 3, true),
        ]);
    }

    private function res(OffHireEvent $e): OffHireResource
    {
        return new OffHireResource($e->load('decider'));
    }
}
