<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AisPosition;
use App\Models\Vessel;
use App\Services\Ais\AisService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AisController extends Controller
{
    public function __construct(private readonly AisService $ais) {}

    public function status(Request $request): JsonResponse
    {
        return $this->ok($this->ais->status($request->user()));
    }

    public function fleet(Request $request): JsonResponse
    {
        return $this->ok($this->ais->fleet($request->user()));
    }

    public function positions(Request $request, Vessel $vessel): JsonResponse
    {
        $page = $this->ais->positions($request->user(), $vessel, $this->perPage($request));

        return $this->page($page, $page->getCollection()->map(fn (AisPosition $p) => $this->position($p))->all());
    }

    public function track(Request $request, Vessel $vessel): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $this->ok($this->ais->track(
            $request->user(), $vessel,
            isset($f['from']) ? CarbonImmutable::parse($f['from']) : null,
            isset($f['to']) ? CarbonImmutable::parse($f['to']) : null,
        ));
    }

    public function manual(Request $request): JsonResponse
    {
        $d = $request->validate([
            'vessel_id' => ['required', 'integer', 'exists:vessels,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'observed_at' => ['required', 'date'],
            'sog_kn' => ['nullable', 'numeric', 'between:0,99'],
            'cog_deg' => ['nullable', 'numeric', 'between:0,360'],
            'heading_deg' => ['nullable', 'integer', 'between:0,511'],
            'nav_status' => ['nullable', 'string', 'max:40'],
            'destination' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->created($this->position($this->ais->recordManual($request->user(), $d)), 'Position recorded.');
    }

    /** @return array<string, mixed> */
    private function position(AisPosition $p): array
    {
        return [
            'id' => $p->id, 'vessel_id' => $p->vessel_id, 'latitude' => $p->latitude, 'longitude' => $p->longitude, 'sog_kn' => $p->sog_kn, 'cog_deg' => $p->cog_deg,
            'heading_deg' => $p->heading_deg, 'nav_status' => $p->nav_status, 'destination' => $p->destination, 'provider' => $p->provider,
            'observed_at' => $p->observed_at->toIso8601String(), 'received_at' => $p->received_at->toIso8601String(),
        ];
    }
}
