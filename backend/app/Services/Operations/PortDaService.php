<?php

namespace App\Services\Operations;

use App\Exceptions\BusinessRuleException;
use App\Models\PortCall;
use App\Models\PortDa;
use App\Models\PortDaItem;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Finance\VoyageExpenseService;
use App\Services\SequenceService;
use App\Support\Decimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Port DA (Disbursement Account) service.
 * DA lifecycle: draft → submitted → approved → settled.
 * Items: estimated vs actual amounts, variance calculated server-side.
 */
class PortDaService
{
    private const EDITABLE_FIELDS = ['agent_company_id', 'da_type', 'proforma_da_id', 'currency', 'remarks'];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly ExchangeRateService $fx,
        private readonly VoyageExpenseService $expenses,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(?PortDa $da, array $data, User $actor): PortDa
    {
        return DB::transaction(function () use ($da, $data, $actor) {
            if ($da) {
                $da = PortDa::query()->lockForUpdate()->findOrFail($da->id);
                $da->assertLockVersion($data['lock_version'] ?? null);
                if (! $da->isEditable()) {
                    throw new BusinessRuleException("A {$da->status} DA cannot be edited.", 'da_read_only');
                }
            }
            $d = $da ?? new PortDa([
                'created_by' => $actor->id,
                'port_call_id' => (int) $data['port_call_id'],
                'voyage_id' => (int) $data['voyage_id'],
                'port_id' => (int) $data['port_id'],
            ]);
            $d->fill(Arr::only($data, self::EDITABLE_FIELDS));
            if (! $d->exists) {
                $d->da_number = $this->sequences->next('port_da', 'DA-'.now()->format('Y').'-');
            }
            $this->assertLinks($d);
            $d->setAttribute('updated_by', $actor->id);
            $d->save();

            return $d;
        });
    }

    /** @param array<int, array{da_cost_category_id: int, description?: string, estimated_amount?: string, actual_amount?: string, remarks?: string}> $items */
    public function saveItems(PortDa $da, array $items, User $actor): PortDa
    {
        return DB::transaction(function () use ($da, $items, $actor) {
            $d = PortDa::query()->lockForUpdate()->findOrFail($da->id);
            if (! $d->isEditable()) {
                throw new BusinessRuleException("A {$d->status} DA cannot be edited.", 'da_read_only');
            }
            // Replace all items
            $d->items()->delete();
            $seq = 0;
            foreach ($items as $item) {
                $est = isset($item['estimated_amount']) ? Decimal::round((string) $item['estimated_amount'], 2) : null;
                $act = isset($item['actual_amount']) ? Decimal::round((string) $item['actual_amount'], 2) : null;
                $variance = null;
                if ($est !== null && $act !== null) {
                    $variance = Decimal::round(Decimal::sub($act, $est), 2);
                }
                PortDaItem::query()->create([
                    'port_da_id' => $d->id,
                    'da_cost_category_id' => (int) $item['da_cost_category_id'],
                    'description' => $item['description'] ?? null,
                    'estimated_amount' => $est,
                    'actual_amount' => $act,
                    'variance_amount' => $variance,
                    'remarks' => $item['remarks'] ?? null,
                    'sequence' => $seq++,
                ]);
            }
            $this->recalculateTotal($d, $actor);

            return $d;
        });
    }

    public function submit(PortDa $da, User $actor): PortDa
    {
        return DB::transaction(function () use ($da, $actor) {
            $d = PortDa::query()->lockForUpdate()->findOrFail($da->id);
            if ($d->status !== 'draft') {
                throw new BusinessRuleException('Only a draft DA can be submitted.', 'invalid_status_transition');
            }
            $this->recalculateTotal($d, $actor);
            $d->fill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id, 'updated_by' => $actor->id])->save();

            return $d;
        });
    }

    public function approve(PortDa $da, User $actor): PortDa
    {
        return DB::transaction(function () use ($da, $actor) {
            $d = PortDa::query()->lockForUpdate()->findOrFail($da->id);
            if ($d->status !== 'submitted') {
                throw new BusinessRuleException('Only a submitted DA can be approved.', 'invalid_status_transition');
            }
            if ((string) $d->submitted_by === (string) $actor->id) {
                $selfAllowed = (bool) config('offshore.approvals.self_approval_allowed', false);
                if (! $selfAllowed) {
                    throw new BusinessRuleException('Self-approval is not allowed.', 'self_approval_forbidden', [], 403);
                }
            }
            $d->fill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id, 'updated_by' => $actor->id])->save();
            // DA-02: approved final DA items become voyage expenses (source port_da).
            $this->expenses->syncFromPortDa($d, $actor);

            return $d;
        });
    }

    public function reject(PortDa $da, User $actor): PortDa
    {
        return DB::transaction(function () use ($da, $actor) {
            $d = PortDa::query()->lockForUpdate()->findOrFail($da->id);
            if ($d->status !== 'submitted') {
                throw new BusinessRuleException('Only a submitted DA can be rejected.', 'invalid_status_transition');
            }
            $d->fill(['status' => 'draft', 'submitted_at' => null, 'submitted_by' => null, 'updated_by' => $actor->id])->save();

            return $d;
        });
    }

    /** The port call must belong to the DA's voyage, and the DA's port must be the port call's port. */
    private function assertLinks(PortDa $da): void
    {
        $errors = [];
        $call = PortCall::query()->find($da->port_call_id);
        if ($call === null || $call->voyage_id !== $da->voyage_id) {
            $errors['port_call_id'] = ['The port call does not belong to the DA voyage.'];
        } elseif ($call->port_id !== null && $call->port_id !== $da->port_id) {
            $errors['port_id'] = ['The port does not match the port call.'];
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Invalid DA links.', 'validation_failed', $errors, 422);
        }
    }

    private function recalculateTotal(PortDa $da, User $actor): void
    {
        $items = $da->items()->get();
        $fieldName = $da->da_type === 'proforma' ? 'estimated_amount' : 'actual_amount';
        $total = '0';
        foreach ($items as $item) {
            $amt = $item->getAttribute($fieldName);
            if ($amt !== null) {
                $total = Decimal::add($total, (string) $amt);
            }
        }
        $total = Decimal::round($total, 2);
        $base = (string) config('offshore.base_currency');
        $fxInfo = $this->fx->resolve($da->currency, $base, now());
        $baseAmount = Decimal::round(Decimal::mul($total, $fxInfo['rate']), 2);
        $da->fill(['total_amount' => $total, 'fx_rate' => $fxInfo['rate'], 'fx_method' => $fxInfo['method'], 'base_amount' => $baseAmount, 'updated_by' => $actor->id])->save();
    }
}
