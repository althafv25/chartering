<?php

namespace App\Services\Operations;

use App\Domain\Offshore\ActivityRevenueCalculator;
use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\ContractRate;
use App\Models\OffshoreActivity;
use App\Models\OffshoreActivityType;
use App\Models\OffshoreLocation;
use App\Models\OffshoreProject;
use App\Models\User;
use App\Models\Voyage;
use App\Services\Chartering\ApprovalGuard;
use App\Services\Contracts\ContractRateResolver;
use App\Services\Finance\VoyageRevenueService;
use App\Services\SequenceService;
use App\Services\SettingsService;
use App\Support\Decimal;
use App\Support\LocalTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Offshore activities (07 §7, 08 §O).
 *   draft → submitted → verified (different user, APR-02) ; submitted → draft (rejected, reason)
 *   `invoiced` is set by invoicing (phase 10).
 * OA-01 hour split ≤ duration; OA-02 rates come from the contract schedule effective at start_at
 * and are snapshotted (with contract_rate_id and version) on every draft save and on submit;
 * verified activities are frozen. Activities of one vessel must not overlap.
 */
class OffshoreActivityService
{
    private const BILLABLE_TYPES = ['day_rate', 'hourly', 'hire_per_day'];

    private const CONTRACT_USABLE = ['approved', 'active', 'completed', 'expired'];

    public function __construct(
        private readonly ContractRateResolver $resolver,
        private readonly ActivityRevenueCalculator $calculator,
        private readonly SettingsService $settings,
        private readonly SequenceService $sequences,
        private readonly ApprovalGuard $approvals,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(?OffshoreActivity $activity, array $data, User $actor): OffshoreActivity
    {
        return DB::transaction(function () use ($activity, $data, $actor) {
            if ($activity) {
                $activity = OffshoreActivity::query()->lockForUpdate()->findOrFail($activity->id);
                $activity->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
                if (! $activity->isEditable()) {
                    throw new BusinessRuleException("A {$activity->status} activity cannot be edited.", 'activity_read_only');
                }
            }
            $a = $activity ?? new OffshoreActivity(['created_by' => $actor->id]);
            $a->fill(collect($data)->only(['vessel_id', 'voyage_id', 'contract_id', 'offshore_project_id', 'offshore_location_id', 'offshore_activity_type_id',
                'description', 'remarks'])->all());
            if (array_key_exists('fuel_used', $data)) {
                $a->fuel_used = $data['fuel_used'] ? array_values(array_map(fn ($l) => ['fuel_type_id' => (int) $l['fuel_type_id'], 'mt' => Decimal::round((string) $l['mt'], 3)], $data['fuel_used'])) : null;
            }
            $this->resolveLinks($a);

            $tz = $a->offshore_location_id ? (string) (OffshoreLocation::query()->find($a->offshore_location_id)?->getAttribute('timezone') ?? 'UTC')
                : (string) ($actor->getAttribute('timezone') ?: 'UTC');
            foreach (['start_at', 'end_at'] as $f) {
                if (array_key_exists($f, $data)) {
                    $a->setAttribute($f, LocalTime::toUtc($data[$f], $tz, $f));
                }
            }
            if (! $a->start_at || ! $a->end_at || ! $a->end_at->greaterThan($a->start_at)) {
                throw new BusinessRuleException('End must be after start.', 'validation_failed', ['end_at' => ['End must be after start.']], 422);
            }
            if ($a->end_at->greaterThan(now()->addHour())) {
                throw new BusinessRuleException('Activities are recorded after the fact; the end cannot be in the future.', 'validation_failed', ['end_at' => ['Future time.']], 422);
            }
            $this->applyHours($a, $data);
            $this->assertNoOverlap($a);
            $this->price($a);

            if (! $a->exists) {
                $a->activity_number = $this->sequences->next('offshore_activity', 'OA-'.$a->start_at->format('Y').'-');
            }
            $a->setAttribute('updated_by', $actor->id);
            $a->save();

            return $a;
        });
    }

    public function submit(OffshoreActivity $activity, User $actor): OffshoreActivity
    {
        return $this->move($activity, 'draft', 'submitted', $actor, function (OffshoreActivity $a) use ($actor) {
            $this->price($a);
            $a->fill(['submitted_by' => $actor->id, 'submitted_at' => now(), 'decision_comment' => null]);
        });
    }

    public function verify(OffshoreActivity $activity, User $actor, ?string $comment): OffshoreActivity
    {
        return $this->move($activity, 'submitted', 'verified', $actor, function (OffshoreActivity $a) use ($actor, $comment) {
            $this->approvals->assertNotSelfDecision($a->submitted_by, $actor);
            if ($a->revenue_amount === null && $this->needsRate($a)) {
                throw new BusinessRuleException('The activity cannot be verified: '.implode(', ', $a->warnings ?? ['rate missing']).'.', 'activity_not_priced',
                    ['rate' => array_map(fn ($w) => str_replace('_', ' ', $w), $a->warnings ?? [])]);
            }
            $a->fill(['verified_by' => $actor->id, 'verified_at' => now(), 'decision_comment' => $comment]);

            // Activity→Revenue automation: Create voyage revenue from verified activity
            if ($a->revenue_amount !== null && bccomp($a->revenue_amount, '0', 2) > 0) {
                app(VoyageRevenueService::class)->syncFromActivity($a, $actor);
            }
        });
    }

    public function reject(OffshoreActivity $activity, User $actor, string $reason): OffshoreActivity
    {
        return $this->move($activity, 'submitted', 'draft', $actor, function (OffshoreActivity $a) use ($actor, $reason) {
            $this->approvals->assertNotSelfDecision($a->submitted_by, $actor);
            $a->fill(['decision_comment' => $reason, 'verified_by' => null, 'verified_at' => null]);
        }, 'rejected');
    }

    public function delete(OffshoreActivity $activity): void
    {
        if (! $activity->isEditable()) {
            throw new BusinessRuleException('Only draft activities can be deleted.', 'activity_read_only');
        }
        $activity->delete();
    }

    /** Fill client/contract from voyage/project/contract and check that they agree. */
    private function resolveLinks(OffshoreActivity $a): void
    {
        $errors = [];
        $voyage = $a->voyage_id ? Voyage::query()->find($a->voyage_id) : null;
        $project = $a->offshore_project_id ? OffshoreProject::query()->find($a->offshore_project_id) : null;
        if ($voyage) {
            if ($voyage->vessel_id !== $a->vessel_id) {
                $errors['voyage_id'] = ['The voyage belongs to another vessel.'];
            }
            if (! $voyage->isOperationallyOpen()) {
                throw new BusinessRuleException("The voyage is {$voyage->status}; activities cannot be changed.", 'voyage_read_only');
            }
            $a->contract_id ??= $voyage->contract_id;
        }
        if ($project) {
            if ($project->status === 'cancelled') {
                $errors['offshore_project_id'] = ['The project is cancelled.'];
            }
            $a->contract_id ??= $project->contract_id;
            $a->offshore_location_id ??= $project->getAttribute('offshore_location_id');
        }
        $contract = $a->contract_id ? Contract::query()->find($a->contract_id) : null;
        if ($contract) {
            if (! in_array($contract->status, self::CONTRACT_USABLE, true)) {
                $errors['contract_id'] = ["A {$contract->status} contract cannot be used for activities."];
            }
            if ($contract->vessel_id && $contract->vessel_id !== $a->vessel_id) {
                $errors['contract_id'] = ['The contract is for another vessel.'];
            }
            if ($project && $project->contract_id && $project->contract_id !== $contract->id) {
                $errors['contract_id'] = ['The project is linked to a different contract.'];
            }
        }
        $a->client_company_id = $contract?->customer_company_id ?? $project?->client_company_id ?? $voyage?->getAttribute('charterer_company_id');
        if ($errors !== []) {
            throw new BusinessRuleException('The activity links are inconsistent.', 'validation_failed', $errors, 422);
        }
    }

    /** @param array<string, mixed> $data */
    private function applyHours(OffshoreActivity $a, array $data): void
    {
        $duration = LocalTime::hoursBetween($a->start_at, $a->end_at);
        $given = array_intersect_key($data, array_flip(['billable_hours', 'non_billable_hours', 'standby_hours']));
        if (! $a->exists && $given === []) {
            // Default split from the activity type (is_billable_default).
            $billable = (bool) OffshoreActivityType::query()->find($a->offshore_activity_type_id)?->getAttribute('is_billable_default');
            $given = $billable ? ['billable_hours' => $duration] : ['non_billable_hours' => $duration];
        }
        foreach ($given as $k => $v) {
            $a->setAttribute($k, Decimal::round((string) ($v ?? '0'), 4));
        }
        $sum = Decimal::add(Decimal::add($a->billable_hours, $a->non_billable_hours), $a->standby_hours);
        if (Decimal::cmp($sum, $duration) > 0) {
            throw new BusinessRuleException("Billable + non-billable + standby hours ({$sum}) exceed the activity duration ({$duration} h) (OA-01).", 'hours_exceed_duration',
                ['billable_hours' => ["Total {$sum} h > duration {$duration} h."]], 422);
        }
    }

    private function assertNoOverlap(OffshoreActivity $a): void
    {
        $clash = OffshoreActivity::query()->where('vessel_id', $a->vessel_id)->when($a->exists, fn ($q) => $q->whereKeyNot($a->id))
            ->where('start_at', '<', $a->end_at)->where('end_at', '>', $a->start_at)->first(['activity_number']);
        if ($clash) {
            throw new BusinessRuleException("The vessel already has activity {$clash->activity_number} in this period.", 'activity_overlap',
                ['start_at' => ["Overlaps {$clash->activity_number}."]], 422);
        }
    }

    /** OA-02: snapshot the contract rates effective at start_at and calculate revenue. */
    private function price(OffshoreActivity $a): void
    {
        $proration = (string) $this->settings->get('offshore.day_rate_proration', 'hourly');
        $standbyBasis = (string) $this->settings->get('offshore.standby_basis', 'standby_rate');
        $contract = $a->contract_id ? Contract::query()->find($a->contract_id) : null;
        if (! $contract) {
            $a->fill(['rate_snapshot' => [], 'revenue_amount' => $this->needsRate($a) ? null : '0.00', 'currency' => null, 'contract_version_no' => null,
                'warnings' => $this->needsRate($a) ? ['no_contract'] : [], 'calculation_basis' => null]);

            return;
        }
        $rates = $this->resolver->ratesAt($contract, $a->start_at);
        $typeId = $a->offshore_activity_type_id;
        $billable = $this->pick($rates, self::BILLABLE_TYPES, $typeId);
        $standby = $this->pick($rates, ['standby_day_rate'], $typeId);
        $lumpSums = [];
        $code = (string) OffshoreActivityType::query()->find($typeId)?->getAttribute('code');
        $feeType = ['MOB' => 'mobilization_fee', 'DEMOB' => 'demobilization_fee'][$code] ?? null;
        if ($feeType && $this->settings->get('offshore.mob_demob_auto', true) && ($fee = $this->pick($rates, [$feeType], $typeId)) && ! $this->feeAlreadyCharged($a, $feeType)) {
            $lumpSums[] = $this->rateArray($fee);
        }
        $result = $this->calculator->calculate($a->billable_hours, $a->standby_hours, $billable ? $this->rateArray($billable) : null,
            $standby ? $this->rateArray($standby) : null, $lumpSums, $proration, $standbyBasis);

        $a->fill([
            'rate_snapshot' => $result['lines'], 'revenue_amount' => $result['revenue'], 'currency' => $result['currency'] ?? $contract->currency,
            'contract_version_no' => $this->resolver->versionAt($contract, $a->start_at)?->version_no, 'warnings' => $result['warnings'],
            'calculation_basis' => "{$proration}|{$standbyBasis}",
        ]);
        if ($rates->isEmpty() && $this->needsRate($a)) {
            $a->warnings = ['no_rates_in_force'];
            $a->revenue_amount = null;
        }
    }

    private function needsRate(OffshoreActivity $a): bool
    {
        return Decimal::cmp($a->billable_hours, '0') > 0 || Decimal::cmp($a->standby_hours, '0') > 0;
    }

    /**
     * Activity-specific line of any listed type first, then generic lines, in the listed order.
     *
     * @param  Collection<int, ContractRate>  $rates
     * @param  list<string>  $types
     */
    private function pick(Collection $rates, array $types, int $typeId): ?ContractRate
    {
        foreach ([true, false] as $specific) {
            foreach ($types as $t) {
                $hit = $rates->first(fn (ContractRate $r) => $r->rate_type === $t && ($specific ? $r->offshore_activity_type_id === $typeId : $r->offshore_activity_type_id === null));
                if ($hit) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /** BR-OA-03 proposal: each mob/demob fee is charged once per contract. */
    private function feeAlreadyCharged(OffshoreActivity $a, string $feeType): bool
    {
        return OffshoreActivity::query()->where('contract_id', $a->contract_id)->when($a->exists, fn ($q) => $q->whereKeyNot($a->id))
            ->whereNotNull('rate_snapshot')->get(['rate_snapshot'])
            ->contains(fn (OffshoreActivity $o) => collect($o->rate_snapshot ?? [])->contains(fn ($l) => ($l['rate_type'] ?? null) === $feeType));
    }

    /** @return array{rate_type: string, amount: string, currency: string, unit: string, contract_rate_id: int} */
    private function rateArray(ContractRate $r): array
    {
        return ['rate_type' => $r->rate_type, 'amount' => (string) $r->amount, 'currency' => (string) $r->currency, 'unit' => (string) $r->unit, 'contract_rate_id' => $r->id];
    }

    private function move(OffshoreActivity $activity, string $from, string $to, User $actor, callable $mutate, ?string $event = null): OffshoreActivity
    {
        return DB::transaction(function () use ($activity, $from, $to, $actor, $mutate, $event) {
            $a = OffshoreActivity::query()->lockForUpdate()->findOrFail($activity->id);
            if ($a->status !== $from) {
                throw new BusinessRuleException("A {$a->status} activity cannot become {$to}.", 'invalid_status_transition');
            }
            if ($a->voyage_id && ! Voyage::query()->findOrFail($a->voyage_id)->isOperationallyOpen()) {
                throw new BusinessRuleException('The voyage is closed; activities are read-only.', 'voyage_read_only');
            }
            $mutate($a);
            $a->fill(['status' => $to, 'updated_by' => $actor->id])->save();
            activity('offshore_activities')->performedOn($a)->causedBy($actor)->event($event ?? $to)
                ->withProperties(['old' => ['status' => $from], 'attributes' => ['status' => $to, 'revenue_amount' => $a->revenue_amount, 'comment' => $a->getAttribute('decision_comment')]])
                ->log("Activity {$a->activity_number} {$to}");

            return $a;
        });
    }
}
