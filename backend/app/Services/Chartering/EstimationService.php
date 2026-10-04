<?php

namespace App\Services\Chartering;

use App\Exceptions\BusinessRuleException;
use App\Models\Enquiry;
use App\Models\EnquiryVessel;
use App\Models\Estimation;
use App\Models\User;
use App\Models\Vessel;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

class EstimationService
{
    public const TYPE_FROM_BUSINESS = [
        'voyage_charter' => 'voyage_charter', 'time_charter' => 'time_charter', 'offshore_charter' => 'offshore_day_rate',
        'cargo_relet' => 'cargo_relet', 'service' => 'offshore_day_rate',
    ];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly ScenarioService $scenarios,
        private readonly ScenarioCalculationService $calc,
        private readonly EnquiryWorkflow $workflow,
        private readonly ApprovalGuard $approvals,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Estimation
    {
        $enquiry = isset($data['enquiry_id']) ? Enquiry::query()->findOrFail($data['enquiry_id']) : null;
        if ($enquiry) {
            $this->workflow->assertAcceptsWork($enquiry, 'an estimation');
        }
        $vessel = Vessel::query()->findOrFail($data['vessel_id']);
        if ($vessel->status !== 'active') {
            throw new BusinessRuleException('Estimations can only be made for active vessels.', 'vessel_inactive', status: 422);
        }
        $type = $data['estimation_type'] ?? ($enquiry ? self::TYPE_FROM_BUSINESS[$enquiry->business_type] : null);
        if (! $type) {
            throw new BusinessRuleException('Estimation type is required without an enquiry.', 'type_required', ['estimation_type' => ['Required.']], 422);
        }

        return DB::transaction(function () use ($data, $actor, $enquiry, $vessel, $type) {
            $year = now()->format('Y');
            $est = Estimation::query()->create([
                'estimation_number' => $this->sequences->next("estimation:{$year}", "EST-{$year}-"),
                'enquiry_id' => $enquiry?->id,
                'estimation_type' => $type,
                'title' => $data['title'] ?? trim($vessel->name.($enquiry ? " — {$enquiry->enquiry_number}" : '')),
                'vessel_id' => $vessel->id,
                'currency' => strtoupper($data['currency'] ?? $enquiry?->currency ?? config('offshore.base_currency')),
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($enquiry) {
                EnquiryVessel::query()->firstOrCreate(['enquiry_id' => $enquiry->id, 'vessel_id' => $vessel->id]);
                $this->workflow->advance($enquiry, 'evaluating', $actor, "estimation {$est->estimation_number} created");
            }

            $this->scenarios->createFromDefaults($est, null, $data['consumption_profile_id'] ?? null, $actor);

            return $est;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Estimation $est, array $data, User $actor): Estimation
    {
        return DB::transaction(function () use ($est, $data, $actor) {
            $locked = Estimation::query()->lockForUpdate()->findOrFail($est->id);
            $locked->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            $this->scenarios->assertEditable($locked);
            $locked->fill([...array_intersect_key($data, array_flip(['title', 'remarks'])), 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function submit(Estimation $est, User $actor): Estimation
    {
        return DB::transaction(function () use ($est, $actor) {
            $locked = $this->lockInStatus($est, ['draft'], 'submitted');
            $selected = $locked->selectedScenario()->first();
            if (! $selected) {
                throw new BusinessRuleException('Select a scenario before submitting.', 'no_selected_scenario');
            }
            $selected->setRelation('estimation', $locked);
            if (! $this->calc->isCurrent($selected)) {
                throw new BusinessRuleException("Scenario {$selected->code} must be fully calculated before submission.", 'scenario_not_calculated');
            }

            return $this->transition($locked, 'submitted', ['submitted_by' => $actor->id, 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_comment' => null], $actor);
        });
    }

    public function approve(Estimation $est, User $actor, ?string $comment): Estimation
    {
        return DB::transaction(function () use ($est, $actor, $comment) {
            $locked = $this->lockInStatus($est, ['submitted'], 'approved');
            $this->approvals->assertNotSelfDecision($locked->submitted_by, $actor);

            return $this->transition($locked, 'approved', ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment], $actor);
        });
    }

    public function reject(Estimation $est, User $actor, string $reason): Estimation
    {
        return DB::transaction(function () use ($est, $actor, $reason) {
            $locked = $this->lockInStatus($est, ['submitted'], 'rejected');
            $this->approvals->assertNotSelfDecision($locked->submitted_by, $actor);

            return $this->transition($locked, 'rejected', ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $reason], $actor);
        });
    }

    /** Rejected → draft so the author can address the comments (history kept in the audit log). */
    public function reopen(Estimation $est, User $actor): Estimation
    {
        return DB::transaction(fn () => $this->transition($this->lockInStatus($est, ['rejected'], 'reopened'), 'draft', [], $actor));
    }

    /** Copy any estimation (typically approved) into a new draft, preserving scenario lineage. */
    public function clone(Estimation $source, User $actor): Estimation
    {
        if ($source->enquiry) {
            $this->workflow->assertAcceptsWork($source->enquiry, 'an estimation');
        }

        return DB::transaction(function () use ($source, $actor) {
            $year = now()->format('Y');
            $copy = Estimation::query()->create([
                'estimation_number' => $this->sequences->next("estimation:{$year}", "EST-{$year}-"),
                ...$source->only(['enquiry_id', 'estimation_type', 'vessel_id', 'currency', 'remarks']),
                'title' => $source->getAttribute('title').' (rev.)',
                'cloned_from_id' => $source->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            foreach ($source->scenarios()->get() as $scenario) {
                $this->scenarios->clone($scenario, null, $actor, $copy);
            }
            activity('estimations')->performedOn($copy)->causedBy($actor)->event('cloned')
                ->withProperties(['attributes' => ['cloned_from' => $source->estimation_number]])->log("Cloned from {$source->estimation_number}");

            return $copy;
        });
    }

    /** @param list<string> $from */
    private function lockInStatus(Estimation $est, array $from, string $action): Estimation
    {
        $locked = Estimation::query()->lockForUpdate()->findOrFail($est->id);
        if (! in_array($locked->status, $from, true)) {
            throw new BusinessRuleException("Estimation cannot be {$action} while {$locked->status}.", 'invalid_status_transition');
        }

        return $locked;
    }

    /** @param array<string, mixed> $extra */
    private function transition(Estimation $est, string $to, array $extra, User $actor): Estimation
    {
        $from = $est->status;
        $est->forceFill([...$extra, 'status' => $to, 'updated_by' => $actor->id])->save();
        activity('estimations')->performedOn($est)->causedBy($actor)->event($to)
            ->withProperties(['old' => ['status' => $from], 'attributes' => ['status' => $to, 'comment' => $extra['decision_comment'] ?? null]])
            ->log("Estimation {$from} → {$to}");

        return $est;
    }
}
