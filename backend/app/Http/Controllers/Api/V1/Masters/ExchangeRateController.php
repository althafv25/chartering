<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Masters\ExchangeRateResource;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExchangeRateController extends Controller
{
    public function __construct(private readonly ExchangeRateService $fx) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize(Permission::ExchangeRatesView->value);
        $f = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $rates = ExchangeRate::query()->with('creator')
            ->when($f['currency'] ?? null, fn ($q, $c) => $q->where(fn ($w) => $w->where('base_currency', strtoupper($c))->orWhere('quote_currency', strtoupper($c))))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('rate_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('rate_date', '<=', $d))
            ->orderByDesc('rate_date')->orderBy('base_currency')->orderBy('quote_currency')
            ->paginate($this->perPage($request, 25));

        return $this->ok(ExchangeRateResource::collection($rates));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize(Permission::ExchangeRatesManage->value);
        $data = $this->validated($request);

        $rate = ExchangeRate::query()->create([...$data, 'source' => 'manual', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return $this->created(new ExchangeRateResource($rate->load('creator')), 'Exchange rate saved.');
    }

    public function update(Request $request, ExchangeRate $exchangeRate): JsonResponse
    {
        $this->authorize(Permission::ExchangeRatesManage->value);
        $data = $request->validate([
            'rate' => Rules::decimal(10, 8, required: true),
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        // Changing a rate never alters transactions: they store their own FX snapshot.
        $exchangeRate->fill([...$data, 'updated_by' => $request->user()->id])->save();

        return $this->ok(new ExchangeRateResource($exchangeRate->load('creator')), 'Exchange rate updated.');
    }

    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        $this->authorize(Permission::ExchangeRatesManage->value);
        $exchangeRate->delete();

        return $this->deleted('Exchange rate deleted.');
    }

    /** Resolve a rate and optionally convert an amount (read-only helper for forms). */
    public function convert(Request $request): JsonResponse
    {
        $this->authorize(Permission::ExchangeRatesView->value);
        $data = $request->validate([
            'from' => Rules::currency(true),
            'to' => Rules::currency(true),
            'date' => ['required', 'date'],
            'amount' => Rules::decimal(16, 4),
        ]);

        $resolved = $this->fx->resolve($data['from'], $data['to'], $data['date']);
        if (isset($data['amount'])) {
            $resolved['converted_amount'] = $this->fx->convert((string) $data['amount'], $data['from'], $data['to'], $data['date']);
        }

        return $this->ok($resolved);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge([
            'base_currency' => strtoupper((string) $request->input('base_currency')),
            'quote_currency' => strtoupper((string) $request->input('quote_currency')),
        ]);

        return $request->validate([
            'rate_date' => ['required', 'date', 'before_or_equal:'.now()->addDays(7)->toDateString()],
            'base_currency' => Rules::currency(true),
            'quote_currency' => [...Rules::currency(true), 'different:base_currency',
                Rule::unique('exchange_rates', 'quote_currency')->where(fn ($q) => $q
                    ->where('base_currency', $request->input('base_currency'))
                    ->whereDate('rate_date', $request->input('rate_date'))
                    ->where('source', 'manual'))],
            'rate' => Rules::decimal(10, 8, required: true),
            'remarks' => ['nullable', 'string', 'max:255'],
        ], ['quote_currency.unique' => 'A manual rate for this pair and date already exists — edit it instead.']);
    }
}
