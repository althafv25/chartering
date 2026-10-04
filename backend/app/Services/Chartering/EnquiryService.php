<?php

namespace App\Services\Chartering;

use App\DTO\Chartering\EnquiryData;
use App\Exceptions\BusinessRuleException;
use App\Models\Enquiry;
use App\Models\EnquiryVessel;
use App\Models\Estimation;
use App\Models\Offer;
use App\Models\User;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

class EnquiryService
{
    public function __construct(private readonly SequenceService $sequences, private readonly EnquiryWorkflow $workflow) {}

    public function create(EnquiryData $data, User $actor): Enquiry
    {
        return DB::transaction(function () use ($data, $actor) {
            $year = now()->format('Y');
            $enquiry = Enquiry::query()->create([
                ...$data->attributes,
                'received_at' => $data->attributes['received_at'] ?? now(),
                'enquiry_number' => $this->sequences->next("enquiry:{$year}", "ENQ-{$year}-"),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            if ($data->ports !== null) {
                $this->replacePorts($enquiry, $data->ports);
            }

            return $enquiry;
        });
    }

    public function update(Enquiry $enquiry, EnquiryData $data, User $actor): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $data, $actor) {
            $locked = Enquiry::query()->lockForUpdate()->findOrFail($enquiry->id);
            $locked->assertLockVersion($data->lockVersion);
            if ($locked->isClosed()) {
                throw new BusinessRuleException("A {$locked->status} enquiry cannot be edited. Reopen it first.", 'enquiry_closed');
            }
            $locked->fill([...$data->attributes, 'updated_by' => $actor->id])->save();
            if ($data->ports !== null) {
                $this->replacePorts($locked, $data->ports);
                activity('enquiries')->performedOn($locked)->causedBy($actor)->event('ports_changed')
                    ->withProperties(['attributes' => ['ports' => $data->ports]])->log('Enquiry itinerary changed');
            }

            return $locked;
        });
    }

    public function delete(Enquiry $enquiry): void
    {
        if (Estimation::query()->where('enquiry_id', $enquiry->id)->exists() || Offer::query()->where('enquiry_id', $enquiry->id)->exists()) {
            throw new BusinessRuleException('Enquiries with estimations or offers cannot be deleted. Cancel the enquiry instead.', 'enquiry_in_use');
        }
        $enquiry->delete();
    }

    public function shortlist(Enquiry $enquiry, int $vesselId, ?string $status, ?string $notes): EnquiryVessel
    {
        $this->workflow->assertAcceptsWork($enquiry, 'vessels');

        return EnquiryVessel::query()->updateOrCreate(
            ['enquiry_id' => $enquiry->id, 'vessel_id' => $vesselId],
            array_filter(['shortlist_status' => $status, 'notes' => $notes], fn ($v) => $v !== null),
        );
    }

    public function removeFromShortlist(Enquiry $enquiry, int $vesselId): void
    {
        $used = Estimation::query()->where('enquiry_id', $enquiry->id)->where('vessel_id', $vesselId)->exists()
            || Offer::query()->where('enquiry_id', $enquiry->id)->where('vessel_id', $vesselId)->exists();
        if ($used) {
            throw new BusinessRuleException('This vessel has estimations or offers for the enquiry. Mark it as rejected instead.', 'vessel_in_use');
        }
        EnquiryVessel::query()->where(['enquiry_id' => $enquiry->id, 'vessel_id' => $vesselId])->delete();
    }

    /** @param list<array<string, mixed>> $ports */
    private function replacePorts(Enquiry $enquiry, array $ports): void
    {
        $enquiry->ports()->delete();
        foreach ($ports as $i => $p) {
            $enquiry->ports()->create([
                'sequence' => $i + 1,
                'port_id' => $p['port_id'] ?? null,
                'offshore_location_id' => $p['offshore_location_id'] ?? null,
                'purpose' => $p['purpose'],
                'notes' => $p['notes'] ?? null,
            ]);
        }
        $enquiry->unsetRelation('ports');
    }
}
