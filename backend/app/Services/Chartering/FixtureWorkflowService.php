<?php

namespace App\Services\Chartering;

use App\Exceptions\BusinessRuleException;
use App\Models\Enquiry;
use App\Models\Fixture;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Fixture lifecycle (phase 5):
 *   draft → submitted → approved           (approval by a different user, APR-02)
 *   submitted → draft                      (rejected with reason)
 *   approved → failed                      (subjects not lifted / deal failed; reason)
 *   draft|submitted|approved → cancelled   (reason; approved only without contract/voyage)
 * Commercial terms (rate, currency, quantity, laycan, ports, commissions) are the
 * accepted-revision snapshot and are never editable. Draft allows non-commercial edits.
 */
class FixtureWorkflowService
{
    public const EDITABLE = ['cargo_description', 'terms', 'remarks', 'charterer_company_id', 'owner_company_id', 'broker_company_id'];

    public function __construct(private readonly ApprovalGuard $approvals, private readonly EnquiryWorkflow $enquiries) {}

    /** @param array<string, mixed> $data */
    public function update(Fixture $fixture, array $data, User $actor): Fixture
    {
        return DB::transaction(function () use ($fixture, $data, $actor) {
            $locked = Fixture::query()->lockForUpdate()->findOrFail($fixture->id);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleException("A {$locked->status} fixture cannot be edited.", 'fixture_read_only');
            }
            $locked->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            $locked->fill([...Arr::only($data, self::EDITABLE), 'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function submit(Fixture $fixture, User $actor): Fixture
    {
        return $this->move($fixture, ['draft'], 'submitted', $actor, ['submitted_by' => $actor->id, 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_comment' => null]);
    }

    public function approve(Fixture $fixture, User $actor, ?string $comment): Fixture
    {
        return $this->move($fixture, ['submitted'], 'approved', $actor, ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment], selfCheck: true);
    }

    public function reject(Fixture $fixture, User $actor, string $reason): Fixture
    {
        return $this->move($fixture, ['submitted'], 'draft', $actor, ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $reason], selfCheck: true, event: 'rejected');
    }

    public function fail(Fixture $fixture, User $actor, string $reason): Fixture
    {
        return $this->move($fixture, ['approved'], 'failed', $actor, ['decision_comment' => $reason], releaseEnquiry: true, guardDownstream: true);
    }

    public function cancel(Fixture $fixture, User $actor, string $reason): Fixture
    {
        return $this->move($fixture, ['draft', 'submitted', 'approved'], 'cancelled', $actor, ['decision_comment' => $reason], releaseEnquiry: true, guardDownstream: true);
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function move(Fixture $fixture, array $from, string $to, User $actor, array $extra, bool $selfCheck = false, bool $releaseEnquiry = false,
        bool $guardDownstream = false, ?string $event = null): Fixture
    {
        return DB::transaction(function () use ($fixture, $from, $to, $actor, $extra, $selfCheck, $releaseEnquiry, $guardDownstream, $event) {
            $locked = Fixture::query()->lockForUpdate()->findOrFail($fixture->id);
            if (! in_array($locked->status, $from, true)) {
                throw new BusinessRuleException("Fixture cannot move from {$locked->status} to {$to}.", 'invalid_status_transition');
            }
            if ($selfCheck) {
                $this->approvals->assertNotSelfDecision($locked->submitted_by, $actor);
            }
            if ($guardDownstream) {
                $contract = $locked->contract()->whereNotIn('status', ['cancelled'])->first();
                if ($contract || $locked->voyage()->exists()) {
                    throw new BusinessRuleException('The fixture already has a contract or voyage. Cancel those first.', 'fixture_in_use');
                }
            }
            $prev = $locked->status;
            $locked->forceFill([...$extra, 'status' => $to, 'updated_by' => $actor->id])->save();
            activity('fixtures')->performedOn($locked)->causedBy($actor)->event($event ?? $to)
                ->withProperties(['old' => ['status' => $prev], 'attributes' => ['status' => $to, 'comment' => $extra['decision_comment'] ?? null]])
                ->log("Fixture {$prev} → {$to}");
            if ($releaseEnquiry) {
                $this->enquiries->fixtureFell(Enquiry::query()->lockForUpdate()->findOrFail($locked->enquiry_id), $actor, "fixture {$locked->fixture_number} {$to}");
            }

            return $locked;
        });
    }
}
