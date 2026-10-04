<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\LaytimeCalculationResource;
use App\Models\LaytimeCalculation;
use App\Models\LaytimeException;
use App\Models\LaytimeSofEvent;
use App\Models\PortCall;
use App\Services\Operations\LaytimeService;
use App\Support\LocalTime;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LaytimeController extends Controller
{
    private const WITH = ['portCall', 'voyage', 'contract', 'sofEvents', 'exceptions'];

    private const TIME_FIELDS = ['nor_tendered_at', 'nor_accepted_at', 'laytime_commenced_at', 'laytime_completed_at'];

    public function __construct(private readonly LaytimeService $laytime) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('operations.laytime.view');
        $f = $request->validate([
            'voyage_id' => ['nullable', 'integer'],
            'port_call_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(LaytimeCalculation::STATUSES)],
            'calculation_type' => ['nullable', Rule::in(LaytimeCalculation::TYPES)],
        ]);
        $q = LaytimeCalculation::query()->with(self::WITH);
        foreach (['voyage_id', 'port_call_id', 'status', 'calculation_type'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }

        return $this->ok(LaytimeCalculationResource::collection($q->orderByDesc('id')->paginate($this->perPage($request))));
    }

    public function show(LaytimeCalculation $laytimeCalculation): JsonResponse
    {
        $this->authorize('operations.laytime.view');

        return $this->ok($this->res($laytimeCalculation));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('operations.laytime.create');
        $c = $this->laytime->save(null, $this->rules($request, true), $request->user());

        return $this->created($this->res($c), 'Laytime calculation created.');
    }

    public function update(Request $request, LaytimeCalculation $laytimeCalculation): JsonResponse
    {
        $this->authorize('operations.laytime.update');

        return $this->ok($this->res($this->laytime->save($laytimeCalculation, $this->rules($request, false), $request->user())), 'Laytime calculation updated.');
    }

    public function calculate(Request $request, LaytimeCalculation $laytimeCalculation): JsonResponse
    {
        $this->authorize('operations.laytime.update');

        return $this->ok($this->res($this->laytime->calculate($laytimeCalculation, $request->user())), 'Laytime calculated.');
    }

    public function addSofEvent(Request $request, LaytimeCalculation $laytimeCalculation): JsonResponse
    {
        $this->authorize('operations.laytime.update');
        $tz = $laytimeCalculation->portCall?->timezone() ?? 'UTC';
        $d = $request->validate([
            'event_at' => ['required', 'string', 'max:40'],
            'event_code' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:30'],
        ]);
        $d['event_at'] = LocalTime::toUtc($d['event_at'], $tz, 'event_at');
        $this->laytime->addSofEvent($laytimeCalculation, $d, $request->user());

        return $this->created($this->res($laytimeCalculation), 'SOF event added.');
    }

    public function removeSofEvent(Request $request, LaytimeCalculation $laytimeCalculation, LaytimeSofEvent $sofEvent): JsonResponse
    {
        $this->authorize('operations.laytime.update');
        $this->laytime->removeSofEvent($laytimeCalculation, $sofEvent, $request->user());

        return $this->ok($this->res($laytimeCalculation), 'SOF event removed.');
    }

    public function addException(Request $request, LaytimeCalculation $laytimeCalculation): JsonResponse
    {
        $this->authorize('operations.laytime.update');
        $tz = $laytimeCalculation->portCall?->timezone() ?? 'UTC';
        $d = $request->validate([
            'from_at' => ['required', 'string', 'max:40'],
            'to_at' => ['required', 'string', 'max:40'],
            'exception_type' => ['required', 'string', 'max:50'],
            'pct_counted' => Rules::decimal(7, 4, true),
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $d['from_at'] = LocalTime::toUtc($d['from_at'], $tz, 'from_at');
        $d['to_at'] = LocalTime::toUtc($d['to_at'], $tz, 'to_at');
        $d['pct_counted'] = (string) $d['pct_counted'];
        $this->laytime->addException($laytimeCalculation, $d, $request->user());

        return $this->created($this->res($laytimeCalculation), 'Exception added.');
    }

    public function updateException(Request $request, LaytimeCalculation $laytimeCalculation, LaytimeException $exception): JsonResponse
    {
        $this->authorize('operations.laytime.update');
        $tz = $laytimeCalculation->portCall?->timezone() ?? 'UTC';
        $d = $request->validate([
            'from_at' => ['sometimes', 'string', 'max:40'],
            'to_at' => ['sometimes', 'string', 'max:40'],
            'exception_type' => ['sometimes', 'string', 'max:50'],
            'pct_counted' => Rules::decimal(7, 4),
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        if (isset($d['from_at'])) {
            $d['from_at'] = LocalTime::toUtc($d['from_at'], $tz, 'from_at');
        }
        if (isset($d['to_at'])) {
            $d['to_at'] = LocalTime::toUtc($d['to_at'], $tz, 'to_at');
        }
        if (isset($d['pct_counted'])) {
            $d['pct_counted'] = (string) $d['pct_counted'];
        }
        $this->laytime->updateException($laytimeCalculation, $exception, $d, $request->user());

        return $this->ok($this->res($laytimeCalculation), 'Exception updated.');
    }

    public function removeException(Request $request, LaytimeCalculation $laytimeCalculation, LaytimeException $exception): JsonResponse
    {
        $this->authorize('operations.laytime.update');
        $this->laytime->removeException($laytimeCalculation, $exception, $request->user());

        return $this->ok($this->res($laytimeCalculation), 'Exception removed.');
    }

    public function submit(Request $request, LaytimeCalculation $laytimeCalculation): JsonResponse
    {
        $this->authorize('operations.laytime.update');

        return $this->ok($this->res($this->laytime->submit($laytimeCalculation, $request->user())), 'Laytime calculation submitted.');
    }

    public function agree(Request $request, LaytimeCalculation $laytimeCalculation): JsonResponse
    {
        $this->authorize('operations.laytime.agree');

        return $this->ok($this->res($this->laytime->agree($laytimeCalculation, $request->user())), 'Laytime calculation agreed.');
    }

    public function dispute(Request $request, LaytimeCalculation $laytimeCalculation): JsonResponse
    {
        $this->authorize('operations.laytime.agree');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->laytime->dispute($laytimeCalculation, $request->user(), $d['reason'])), 'Laytime calculation marked disputed.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $tz = $this->timezoneFor($request, $creating);
        $d = $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'port_call_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('port_calls', 'id')],
            'voyage_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('voyages', 'id')],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')],
            'calculation_type' => [$req, Rule::in(LaytimeCalculation::TYPES)],
            'fixed_hours' => Rules::decimal(12, 4),
            'cargo_quantity' => Rules::decimal(14, 3),
            'rate_per_day' => Rules::decimal(12, 4),
            'rate_unit' => ['nullable', 'string', 'max:20'],
            'terms_code' => ['nullable', 'string', 'max:30'],
            'terms_definition' => ['nullable', 'array'],
            'nor_tendered_at' => ['nullable', 'string', 'max:40'],
            'nor_accepted_at' => ['nullable', 'string', 'max:40'],
            'notice_time_hours' => Rules::decimal(10, 4),
            'laytime_commenced_at' => ['nullable', 'string', 'max:40'],
            'laytime_completed_at' => ['nullable', 'string', 'max:40'],
            'demurrage_rate_per_day' => Rules::decimal(18, 4),
            'despatch_rate_per_day' => Rules::decimal(18, 4),
            'currency' => Rules::currency(),
            'once_on_demurrage_rule' => ['nullable', 'string', Rule::in(['always_on_demurrage', 'exceptions_apply'])],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);
        foreach (self::TIME_FIELDS as $field) {
            if (array_key_exists($field, $d) && $d[$field] !== null) {
                $d[$field] = LocalTime::toUtc($d[$field], $tz, $field);
            }
        }

        return $d;
    }

    private function timezoneFor(Request $request, bool $creating): string
    {
        if ($creating) {
            $portCallId = $request->input('port_call_id');
            if ($portCallId) {
                $portCall = PortCall::query()->find($portCallId);

                return $portCall?->timezone() ?? 'UTC';
            }

            return 'UTC';
        }

        /** @var LaytimeCalculation|null $route */
        $route = $request->route('laytimeCalculation');

        return $route?->portCall?->timezone() ?? 'UTC';
    }

    private function res(LaytimeCalculation $c): LaytimeCalculationResource
    {
        return new LaytimeCalculationResource($c->refresh()->load(self::WITH));
    }
}
