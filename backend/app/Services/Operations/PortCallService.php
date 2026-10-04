<?php

namespace App\Services\Operations;

use App\Exceptions\BusinessRuleException;
use App\Models\OffshoreLocation;
use App\Models\Port;
use App\Models\PortCall;
use App\Models\User;
use App\Models\Voyage;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Port calls / offshore location calls. Times arrive as port-local values and are stored
 * in UTC (G-06). Status follows the actual times (ATA → arrived, ATB → berthed,
 * ATD → sailed); `nominated` and `cancelled` are set explicitly.
 */
class PortCallService
{
    /** @param array<string, mixed> $data */
    public function create(Voyage $voyage, array $data, User $actor): PortCall
    {
        return DB::transaction(function () use ($voyage, $data, $actor) {
            $v = $this->openVoyage($voyage);
            $call = new PortCall(['voyage_id' => $v->id, 'created_by' => $actor->id]);
            $call->sequence = (int) ($data['sequence'] ?? ((int) $v->portCalls()->max('sequence') + 1));
            $this->apply($call, $data, $actor);

            return $call;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PortCall $call, array $data, User $actor): PortCall
    {
        return DB::transaction(function () use ($call, $data, $actor) {
            $this->openVoyage($call->voyage);
            $locked = PortCall::query()->lockForUpdate()->findOrFail($call->id);
            $locked->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            if ($locked->status === 'cancelled') {
                throw new BusinessRuleException('A cancelled port call cannot be edited.', 'port_call_read_only');
            }
            if (isset($data['sequence'])) {
                $locked->sequence = (int) $data['sequence'];
            }
            $this->apply($locked, $data, $actor);

            return $locked;
        });
    }

    public function cancel(PortCall $call, User $actor, string $reason): PortCall
    {
        return DB::transaction(function () use ($call, $actor, $reason) {
            $this->openVoyage($call->voyage);
            $locked = PortCall::query()->lockForUpdate()->findOrFail($call->id);
            if ($locked->ata !== null) {
                throw new BusinessRuleException('The vessel has already arrived; record the departure instead of cancelling.', 'port_call_in_progress');
            }
            $locked->fill(['status' => 'cancelled', 'remarks' => trim(($locked->getAttribute('remarks') ? $locked->getAttribute('remarks')."\n" : '')."Cancelled: {$reason}"),
                'updated_by' => $actor->id])->save();

            return $locked;
        });
    }

    public function delete(PortCall $call): void
    {
        DB::transaction(function () use ($call) {
            $this->openVoyage($call->voyage);
            $locked = PortCall::query()->lockForUpdate()->findOrFail($call->id);
            if ($locked->ata !== null || DB::table('captain_reports')->where('port_call_id', $locked->id)->whereNull('deleted_at')->exists()
                || DB::table('voyage_milestones')->where('port_call_id', $locked->id)->exists()) {
                throw new BusinessRuleException('This port call has actual times, reports or milestones; cancel it instead.', 'port_call_in_use');
            }
            $locked->delete();
        });
    }

    /** @param array<string, mixed> $data */
    private function apply(PortCall $call, array $data, User $actor): void
    {
        if (! $call->exists || array_key_exists('port_id', $data) || array_key_exists('offshore_location_id', $data)) {
            $portId = $data['port_id'] ?? null;
            $locId = $data['offshore_location_id'] ?? null;
            if (($portId === null) === ($locId === null)) {
                throw new BusinessRuleException('Select either a port or an offshore location.', 'validation_failed', ['port_id' => ['Port or location required (not both).']], 422);
            }
            $call->port_id = $portId ? (int) $portId : null;
            $call->offshore_location_id = $locId ? (int) $locId : null;
            $call->unsetRelation('port')->unsetRelation('location');
        }
        $call->fill(Arr::only($data, ['agent_company_id', 'purpose', 'berth', 'remarks']));
        $tz = $call->port_id ? (string) (Port::query()->find($call->port_id)?->getAttribute('timezone') ?? 'UTC')
            : (string) (OffshoreLocation::query()->find($call->offshore_location_id)?->getAttribute('timezone') ?? 'UTC');
        foreach (PortCall::TIMES as $field) {
            if (array_key_exists($field, $data)) {
                $call->setAttribute($field, LocalTime::toUtc($data[$field], $tz, $field));
            }
        }
        $this->validateTimes($call);
        $call->status = $this->derivedStatus($call, isset($data['status']) ? (string) $data['status'] : null);
        $call->setAttribute('updated_by', $actor->id);
        $call->save();
    }

    private function validateTimes(PortCall $c): void
    {
        $errors = [];
        $order = static function (?CarbonImmutable $a, ?CarbonImmutable $b): bool {
            return $a === null || $b === null || ! $b->lessThan($a);
        };
        $t = fn (string $f) => $c->getAttribute($f) ? CarbonImmutable::instance($c->getAttribute($f)) : null;
        if (! $order($t('eta'), $t('etb')) || ! $order($t('etb') ?? $t('eta'), $t('etd'))) {
            $errors['etd'] = ['Estimated times must be in order ETA ≤ ETB ≤ ETD.'];
        }
        if (! $order($t('ata'), $t('atb')) || ! $order($t('atb') ?? $t('ata'), $t('atd'))) {
            $errors['atd'] = ['Actual times must be in order ATA ≤ ATB ≤ ATD.'];
        }
        if (($t('atb') || $t('atd')) && ! $t('ata')) {
            $errors['ata'] = ['ATA is required before ATB/ATD.'];
        }
        $future = CarbonImmutable::now()->addHour();
        foreach (['ata', 'atb', 'atd'] as $f) {
            if ($t($f)?->greaterThan($future)) {
                $errors[$f] = ['Actual times cannot be in the future.'];
            }
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Port call times are inconsistent.', 'validation_failed', $errors, 422);
        }
    }

    private function derivedStatus(PortCall $c, ?string $requested): string
    {
        if ($c->atd) {
            return 'sailed';
        }
        if ($c->atb) {
            return 'berthed';
        }
        if ($c->ata) {
            return 'arrived';
        }

        return $requested === 'nominated' || ($requested === null && $c->status === 'nominated') ? 'nominated' : 'planned';
    }

    private function openVoyage(Voyage $voyage): Voyage
    {
        $v = Voyage::query()->lockForUpdate()->findOrFail($voyage->id);
        if (! $v->isOperationallyOpen()) {
            throw new BusinessRuleException("The voyage is {$v->status}; operational records are read-only.", 'voyage_read_only');
        }

        return $v;
    }
}
