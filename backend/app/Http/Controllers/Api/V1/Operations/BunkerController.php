<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\BunkerStemResource;
use App\Models\BunkerStem;
use App\Models\Voyage;
use App\Services\Operations\BunkerStemService;
use App\Services\Operations\RobLedgerService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BunkerController extends Controller
{
    private const WITH = ['vessel', 'voyage', 'port', 'portCall.port', 'portCall.location', 'supplier', 'fuelType'];

    public function __construct(private readonly BunkerStemService $stems, private readonly RobLedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('operations.bunkers.view');
        $f = $request->validate(['vessel_id' => ['nullable', 'integer'], 'voyage_id' => ['nullable', 'integer'], 'fuel_type_id' => ['nullable', 'integer'],
            'supplier_company_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(BunkerStem::STATUSES)], 'search' => ['nullable', 'string', 'max:60']]);
        $q = BunkerStem::query()->with(self::WITH);
        foreach (['vessel_id', 'voyage_id', 'fuel_type_id', 'supplier_company_id', 'status'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $q->where(fn ($w) => $w->where('stem_number', 'like', "%{$term}%")->orWhere('bdn_number', 'like', "%{$term}%"));
        }

        return $this->ok(BunkerStemResource::collection($q->orderByDesc('ordered_on')->orderByDesc('id')->paginate($this->perPage($request))));
    }

    public function show(BunkerStem $bunkerStem): JsonResponse
    {
        $this->authorize('operations.bunkers.view');

        return $this->ok(new BunkerStemResource($bunkerStem->load(self::WITH)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('operations.bunkers.manage');
        $s = $this->stems->save(null, $this->rules($request, true), $request->user());

        return $this->created(new BunkerStemResource($s->load(self::WITH)), "Stem {$s->stem_number} ordered.");
    }

    public function update(Request $request, BunkerStem $bunkerStem): JsonResponse
    {
        $this->authorize('operations.bunkers.manage');

        return $this->ok(new BunkerStemResource($this->stems->save($bunkerStem, $this->rules($request, false), $request->user())->load(self::WITH)), 'Stem updated.');
    }

    public function deliver(Request $request, BunkerStem $bunkerStem): JsonResponse
    {
        $this->authorize('operations.bunkers.manage');
        $d = $request->validate(['lock_version' => ['required', 'integer'], 'delivered_at' => ['required', 'string', 'max:40'],
            'delivered_mt' => Rules::decimal(9, 3, true), 'bdn_number' => ['required', 'string', 'max:60']]);
        $d['delivered_mt'] = (string) $d['delivered_mt'];

        return $this->ok(new BunkerStemResource($this->stems->deliver($bunkerStem, $d, $request->user())->load(self::WITH)), 'Delivery recorded.');
    }

    public function cancel(Request $request, BunkerStem $bunkerStem): JsonResponse
    {
        $this->authorize('operations.bunkers.manage');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->ok(new BunkerStemResource($this->stems->cancel($bunkerStem, $request->user(), $d['reason'])->load(self::WITH)), 'Stem cancelled.');
    }

    public function robLedger(Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.bunkers.view');

        return $this->ok($this->ledger->forVoyage($voyage));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $d = $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'vessel_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('vessels', 'id')],
            'voyage_id' => [$creating ? 'nullable' : 'prohibited', 'integer', Rule::exists('voyages', 'id')],
            'port_call_id' => ['nullable', 'integer'],
            'port_id' => ['nullable', 'integer', Rule::exists('ports', 'id')],
            'supplier_company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'fuel_type_id' => [$req, 'integer', Rule::exists('fuel_types', 'id')],
            'ordered_on' => [$req, 'date'],
            'ordered_mt' => [...Rules::decimal(9, 3, $creating), 'gt:0'],
            'price_per_mt' => Rules::decimal(12, 4, $creating),
            'currency' => Rules::currency($creating),
            'bdn_number' => ['nullable', 'string', 'max:60'],
            'invoice_reference' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach (['ordered_mt', 'price_per_mt'] as $k) {
            if (isset($d[$k])) {
                $d[$k] = (string) $d[$k];
            }
        }

        return $d;
    }
}
