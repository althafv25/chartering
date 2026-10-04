<?php

namespace App\Services;

use App\Enums\VesselStatusTrack;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselStatusHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vessel status history per track. Periods never overlap: recording a new
 * status closes the open period at the new effective_from. The current status
 * is denormalised onto vessels.{track}_status for boards and filters.
 */
class VesselStatusService
{
    /** @param array<string, mixed> $data */
    public function change(Vessel $vessel, VesselStatusTrack $track, array $data, User $actor): VesselStatusHistory
    {
        $from = Carbon::parse($data['effective_from'])->utc();

        if ($from->greaterThan(now()->addMinutes(5))) {
            throw new BusinessRuleException('Status changes cannot be dated in the future. Planned movements are recorded on voyages.', 'status_in_future', status: 422);
        }

        return DB::transaction(function () use ($vessel, $track, $data, $from, $actor) {
            $locked = Vessel::query()->lockForUpdate()->findOrFail($vessel->id);

            $open = VesselStatusHistory::query()
                ->where('vessel_id', $locked->id)->where('track', $track->value)->whereNull('effective_to')
                ->latest('effective_from')->first();

            if ($open && $from->lessThanOrEqualTo($open->effective_from)) {
                throw new BusinessRuleException(
                    "The new status must start after the current one ({$open->effective_from->format('d M Y H:i')} UTC). Undo the latest change to correct history.",
                    'status_backdated',
                );
            }

            if ($open && $open->status === $data['status'] && ($open->port_id ?? null) === ($data['port_id'] ?? null)
                && ($open->offshore_location_id ?? null) === ($data['offshore_location_id'] ?? null)) {
                throw new BusinessRuleException('The vessel already has this status at this location.', 'status_unchanged');
            }

            $open?->forceFill(['effective_to' => $from])->save();

            $entry = VesselStatusHistory::query()->create([
                ...Arr::only($data, ['status', 'port_id', 'offshore_location_id', 'location_text', 'latitude', 'longitude', 'reason', 'remarks']),
                'vessel_id' => $locked->id,
                'track' => $track->value,
                'effective_from' => $from,
                'changed_by' => $actor->id,
            ]);

            // Status changes must not invalidate an open particulars form (no lock_version bump).
            $locked->forceFill([$track->column() => $data['status']])->saveQuietly();

            return $entry->load(['port', 'offshoreLocation', 'changer']);
        });
    }

    /** Removes the latest entry of a track and re-opens the previous one (correction). */
    public function undoLatest(Vessel $vessel, VesselStatusTrack $track, User $actor): ?VesselStatusHistory
    {
        return DB::transaction(function () use ($vessel, $track, $actor) {
            $locked = Vessel::query()->lockForUpdate()->findOrFail($vessel->id);
            $entries = VesselStatusHistory::query()->where('vessel_id', $locked->id)->where('track', $track->value)
                ->orderByDesc('effective_from')->orderByDesc('id')->limit(2)->get();

            $latest = $entries->first();
            if (! $latest) {
                throw new BusinessRuleException('There is no status to undo.', 'nothing_to_undo');
            }

            activity('vessel_status_history')->performedOn($locked)->causedBy($actor)->event('status_undone')
                ->withProperties(['old' => Arr::only($latest->toArray(), ['track', 'status', 'effective_from', 'location_text', 'reason'])])
                ->log('Vessel status change undone');

            $latest->delete();
            $previous = $entries->get(1);
            $previous?->forceFill(['effective_to' => null])->save();

            $locked->forceFill([$track->column() => $previous?->status])->saveQuietly();

            return $previous;
        });
    }

    /**
     * Fleet status board: active vessels with the open entry of each track.
     *
     * @param  array<string, mixed>  $f
     * @return Collection<int, Vessel>
     */
    public function board(array $f): Collection
    {
        $open = fn ($q) => $q->whereNull('effective_to')->with(['port', 'offshoreLocation']);

        return Vessel::query()
            ->with(['vesselType', 'statusHistory' => $open])
            ->where('status', 'active')
            ->when($f['vessel_type_id'] ?? null, fn ($q, $v) => $q->where('vessel_type_id', $v))
            ->when($f['commercial_status'] ?? null, fn ($q, $v) => $q->where('commercial_status', $v))
            ->when($f['operational_status'] ?? null, fn ($q, $v) => $q->where('operational_status', $v))
            ->orderBy('name')->get();
    }
}
