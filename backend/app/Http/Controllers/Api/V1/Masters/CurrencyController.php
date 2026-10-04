<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Currencies are deactivated, never deleted (historical transactions reference them). */
class CurrencyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $all = $request->user()->can(Permission::CurrenciesView->value) && ! $request->boolean('active');

        return $this->ok(Currency::query()->when(! $all, fn ($q) => $q->where('status', 'active'))->orderBy('code')->get()
            ->map(fn (Currency $c) => [...$c->only(['id', 'code', 'name', 'symbol', 'decimals', 'status']), 'is_base' => $c->code === config('offshore.base_currency')]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize(Permission::CurrenciesUpdate->value);
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z]{3}$/', Rule::unique('currencies', 'code')],
            'name' => ['required', 'string', 'max:60'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'between:0,4'],
        ]);

        return $this->created(Currency::query()->create([...$data, 'status' => 'active']), 'Currency added.');
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $this->authorize(Permission::CurrenciesUpdate->value);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['sometimes', 'integer', 'between:0,4'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (($data['status'] ?? null) === 'inactive' && $currency->code === config('offshore.base_currency')) {
            throw new BusinessRuleException('The base currency cannot be deactivated.', 'base_currency_locked');
        }

        $currency->fill($data)->save();

        return $this->ok($currency, 'Currency updated.');
    }
}
