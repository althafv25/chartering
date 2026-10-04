<?php

namespace App\Services\Operations;

use App\Domain\Laytime\LaytimeCalculator;
use App\Exceptions\BusinessRuleException;
use App\Models\LaytimeCalculation;
use App\Models\LaytimeException;
use App\Models\LaytimeSofEvent;
use App\Models\User;
use App\Models\Voyage;
use App\Services\Finance\VoyageExpenseService;
use App\Services\Finance\VoyageRevenueService;
use App\Support\Decimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Laytime calculation service.
 * Lifecycle: draft → submitted → agreed | disputed.
 * Server-side calculation via LaytimeCalculator domain.
 */
class LaytimeService
{
    private const EDITABLE = ['contract_id', 'calculation_type', 'fixed_hours', 'cargo_quantity', 'rate_per_day', 'rate_unit', 'terms_code', 'terms_definition',
        'nor_tendered_at', 'nor_accepted_at', 'notice_time_hours', 'laytime_commenced_at', 'laytime_completed_at', 'demurrage_rate_per_day', 'despatch_rate_per_day',
        'currency', 'once_on_demurrage_rule', 'remarks'];

    /** @param array<string, mixed> $data */
    public function save(?LaytimeCalculation $calc, array $data, User $actor): LaytimeCalculation
    {
        return DB::transaction(function () use ($calc, $data, $actor) {
            if ($calc) {
                $calc = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
                $calc->assertLockVersion($data['lock_version'] ?? null);
                if (! $calc->isEditable()) {
                    throw new BusinessRuleException("A {$calc->status} laytime calculation cannot be edited.", 'laytime_read_only');
                }
            }
            $c = $calc ?? new LaytimeCalculation([
                'created_by' => $actor->id,
                'port_call_id' => (int) $data['port_call_id'],
                'voyage_id' => (int) $data['voyage_id'],
            ]);
            $c->fill(Arr::only($data, self::EDITABLE));
            foreach (['fixed_hours', 'cargo_quantity', 'rate_per_day', 'notice_time_hours', 'demurrage_rate_per_day', 'despatch_rate_per_day'] as $k) {
                if (array_key_exists($k, $data)) {
                    // null clears the value (an emptied form field); anything else is stored rounded.
                    $scale = in_array($k, ['cargo_quantity'], true) ? 3 : 4;
                    $c->setAttribute($k, $data[$k] === null || $data[$k] === '' ? null : Decimal::round((string) $data[$k], $scale));
                }
            }
            // Money needs a currency: default to the voyage's, so agreeing a calculation can always book its demurrage or despatch.
            $c->currency ??= Voyage::query()->whereKey($c->voyage_id)->value('currency');
            $c->setAttribute('updated_by', $actor->id);
            $c->save();

            return $c;
        });
    }

    public function calculate(LaytimeCalculation $calc, User $actor): LaytimeCalculation
    {
        return DB::transaction(function () use ($calc, $actor) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            $exceptions = $c->exceptions()->get()->map(fn ($e) => [
                'from_at' => $e->from_at,
                'to_at' => $e->to_at,
                'exception_type' => $e->exception_type,
                'pct_counted' => (string) $e->pct_counted,
            ])->all();
            $inputs = [
                'fixed_hours' => $c->fixed_hours,
                'cargo_quantity' => $c->cargo_quantity,
                'rate_per_day' => $c->rate_per_day,
                'rate_unit' => $c->rate_unit,
                'laytime_commenced_at' => $c->laytime_commenced_at,
                'laytime_completed_at' => $c->laytime_completed_at,
                'demurrage_rate_per_day' => $c->demurrage_rate_per_day,
                'despatch_rate_per_day' => $c->despatch_rate_per_day,
                'once_on_demurrage_rule' => $c->once_on_demurrage_rule,
                'exceptions' => $exceptions,
            ];
            $result = LaytimeCalculator::calculate($inputs);
            $c->fill([
                'allowed_hours' => $result->allowedHours,
                'used_hours' => $result->usedHours,
                'difference_hours' => $result->differenceHours,
                'demurrage_amount' => $result->demurrageAmount,
                'despatch_amount' => $result->despatchAmount,
                'calculation_version' => $result->calculationVersion,
                'trace' => $result->trace,
                'calculated_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            return $c;
        });
    }

    public function submit(LaytimeCalculation $calc, User $actor): LaytimeCalculation
    {
        return DB::transaction(function () use ($calc, $actor) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            if ($c->status !== 'draft') {
                throw new BusinessRuleException('Only a draft laytime can be submitted.', 'invalid_status_transition');
            }
            if ($c->allowed_hours === null || $c->used_hours === null) {
                throw new BusinessRuleException('The calculation must be completed before submission.', 'calculation_incomplete');
            }
            $c->fill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id, 'updated_by' => $actor->id])->save();

            return $c;
        });
    }

    public function agree(LaytimeCalculation $calc, User $actor): LaytimeCalculation
    {
        return DB::transaction(function () use ($calc, $actor) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            if ($c->status !== 'submitted') {
                throw new BusinessRuleException('Only a submitted laytime can be agreed.', 'invalid_status_transition');
            }
            $c->fill(['status' => 'agreed', 'agreed_at' => now(), 'agreed_by' => $actor->id, 'updated_by' => $actor->id])->save();

            // Laytime→Revenue/Expense automation: Create voyage revenue for demurrage or expense for despatch
            if ($c->demurrage_amount !== null && bccomp($c->demurrage_amount, '0', 2) > 0) {
                app(VoyageRevenueService::class)->syncFromLaytime($c, $actor);
            } elseif ($c->despatch_amount !== null && bccomp($c->despatch_amount, '0', 2) > 0) {
                app(VoyageExpenseService::class)->syncFromLaytime($c, $actor);
            }

            return $c;
        });
    }

    public function dispute(LaytimeCalculation $calc, User $actor, string $reason): LaytimeCalculation
    {
        return DB::transaction(function () use ($calc, $actor, $reason) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            if ($c->status !== 'submitted') {
                throw new BusinessRuleException('Only a submitted laytime can be disputed.', 'invalid_status_transition');
            }
            $c->fill(['status' => 'disputed', 'remarks' => trim(($c->remarks ?? '')."\nDisputed: {$reason}"), 'updated_by' => $actor->id])->save();

            return $c;
        });
    }

    private function assertEditable(LaytimeCalculation $c): void
    {
        if (! $c->isEditable()) {
            throw new BusinessRuleException("A {$c->status} laytime calculation cannot be edited.", 'laytime_read_only');
        }
    }

    /** @param array<string, mixed> $data */
    public function addSofEvent(LaytimeCalculation $calc, array $data, User $actor): LaytimeSofEvent
    {
        return DB::transaction(function () use ($calc, $data, $actor) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            $this->assertEditable($c);
            $event = $c->sofEvents()->create([
                'event_at' => $data['event_at'],
                'event_code' => $data['event_code'],
                'description' => $data['description'] ?? null,
                'source' => $data['source'] ?? 'manual',
            ]);
            $c->setAttribute('updated_by', $actor->id)->save();

            return $event;
        });
    }

    public function removeSofEvent(LaytimeCalculation $calc, LaytimeSofEvent $event, User $actor): void
    {
        DB::transaction(function () use ($calc, $event, $actor) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            $this->assertEditable($c);
            if ((int) $event->laytime_calculation_id !== $c->id) {
                throw new BusinessRuleException('The SOF event does not belong to this calculation.', 'invalid_relation', [], 422);
            }
            $event->delete();
            $c->setAttribute('updated_by', $actor->id)->save();
        });
    }

    /** @param array<string, mixed> $data */
    public function addException(LaytimeCalculation $calc, array $data, User $actor): LaytimeException
    {
        return DB::transaction(function () use ($calc, $data, $actor) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            $this->assertEditable($c);
            $exception = $c->exceptions()->create([
                'from_at' => $data['from_at'],
                'to_at' => $data['to_at'],
                'exception_type' => $data['exception_type'],
                'pct_counted' => Decimal::round((string) ($data['pct_counted'] ?? '0'), 4),
                'remarks' => $data['remarks'] ?? null,
            ]);
            $c->setAttribute('updated_by', $actor->id)->save();

            return $exception;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateException(LaytimeCalculation $calc, LaytimeException $exception, array $data, User $actor): LaytimeException
    {
        return DB::transaction(function () use ($calc, $exception, $data, $actor) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            $this->assertEditable($c);
            if ((int) $exception->laytime_calculation_id !== $c->id) {
                throw new BusinessRuleException('The exception does not belong to this calculation.', 'invalid_relation', [], 422);
            }
            $fields = Arr::only($data, ['from_at', 'to_at', 'exception_type', 'pct_counted', 'remarks']);
            if (isset($fields['pct_counted'])) {
                $fields['pct_counted'] = Decimal::round((string) $fields['pct_counted'], 4);
            }
            $exception->fill($fields)->save();
            $c->setAttribute('updated_by', $actor->id)->save();

            return $exception;
        });
    }

    public function removeException(LaytimeCalculation $calc, LaytimeException $exception, User $actor): void
    {
        DB::transaction(function () use ($calc, $exception, $actor) {
            $c = LaytimeCalculation::query()->lockForUpdate()->findOrFail($calc->id);
            $this->assertEditable($c);
            if ((int) $exception->laytime_calculation_id !== $c->id) {
                throw new BusinessRuleException('The exception does not belong to this calculation.', 'invalid_relation', [], 422);
            }
            $exception->delete();
            $c->setAttribute('updated_by', $actor->id)->save();
        });
    }
}
