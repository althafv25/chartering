<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CompanyAlias;
use App\Models\User;
use App\Support\ListQuery;
use App\Support\NameNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    private const FIELDS = [
        'legal_name', 'trading_name', 'country', 'city', 'address_line1', 'address_line2', 'postal_code', 'email', 'phone',
        'website', 'tax_number', 'vat_registered', 'default_currency', 'payment_terms_days', 'credit_limit', 'status', 'remarks',
    ];

    public function __construct(private readonly SequenceService $sequences) {}

    /** @param array<string, mixed> $f */
    public function paginate(array $f, int $perPage): LengthAwarePaginator
    {
        $query = Company::query()->with('roles')->withCount('contacts');

        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $norm = ListQuery::like(NameNormalizer::normalize($term));
            $query->where(fn ($q) => $q->where('legal_name', 'like', $like)
                ->orWhere('trading_name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('normalized_name', 'like', $norm)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('aliases', fn ($a) => $a->where('normalized_alias', 'like', $norm))
                ->orWhereHas('contacts', fn ($c) => $c->where('email', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(last_name, '')) like ?", [$like])));
        }
        if ($role = $f['role'] ?? null) {
            $query->withRole($role);
        }
        if ($status = $f['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($country = $f['country'] ?? null) {
            $query->where('country', $country);
        }

        return ListQuery::sort($query, $f['sort'] ?? null, ['legal_name', 'code', 'country', 'created_at'], 'legal_name')->paginate($perPage);
    }

    /** Lightweight search for pickers. @return Collection<int, Company> */
    public function lookup(string $term, ?string $role, int $limit = 20): Collection
    {
        $like = ListQuery::like($term);

        return Company::query()->with('roles')
            ->where('status', '!=', 'blocked')
            ->when($role, fn ($q) => $q->withRole($role))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('legal_name', 'like', $like)
                ->orWhere('trading_name', 'like', $like)->orWhere('code', 'like', $like)
                ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', $like))))
            ->orderBy('legal_name')->limit($limit)->get();
    }

    /**
     * Companies whose normalized name (or alias) matches, optionally in the same country.
     *
     * @return Collection<int, Company>
     */
    public function possibleDuplicates(string $legalName, ?string $country, ?int $ignoreId = null): Collection
    {
        $norm = NameNormalizer::normalize($legalName);

        return Company::query()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(fn ($q) => $q->where('normalized_name', $norm)
                ->orWhereHas('aliases', fn ($a) => $a->where('normalized_alias', $norm)))
            ->when($country, fn ($q) => $q->where(fn ($w) => $w->where('country', $country)->orWhereNull('country')))
            ->limit(5)->get(['id', 'code', 'legal_name', 'country']);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Company
    {
        if (empty($data['confirm_duplicate'])) {
            $this->guardDuplicates($data['legal_name'], $data['country'] ?? null);
        }

        return DB::transaction(function () use ($data, $actor) {
            $company = Company::query()->create([
                ...Arr::only($data, self::FIELDS),
                'code' => $this->sequences->next('company', 'CMP-'),
                'normalized_name' => NameNormalizer::normalize($data['legal_name']),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->syncRoles($company, $data['roles'], $actor);

            return $company->load('roles');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Company $company, array $data, User $actor): Company
    {
        $company->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);

        $renamed = isset($data['legal_name']) && $data['legal_name'] !== $company->legal_name;
        if ($renamed && empty($data['confirm_duplicate'])) {
            $this->guardDuplicates($data['legal_name'], $data['country'] ?? $company->country, $company->id);
        }

        return DB::transaction(function () use ($company, $data, $actor, $renamed) {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            $locked->assertLockVersion((int) $data['lock_version']);

            if ($renamed) {
                // Netpas-style aliasing: keep the former name searchable.
                CompanyAlias::query()->create([
                    'company_id' => $locked->id,
                    'alias' => $locked->legal_name,
                    'normalized_alias' => NameNormalizer::normalize($locked->legal_name),
                    'reason' => 'former_name',
                    'valid_to' => now()->toDateString(),
                    'created_by' => $actor->id,
                ]);
                $data['normalized_name'] = NameNormalizer::normalize($data['legal_name']);
            }

            $locked->fill([...Arr::only($data, [...self::FIELDS, 'normalized_name']), 'updated_by' => $actor->id])->save();

            if (array_key_exists('roles', $data)) {
                $this->syncRoles($locked, $data['roles'], $actor);
            }

            return $locked->load('roles');
        });
    }

    public function delete(Company $company): void
    {
        // Soft delete; FKs from vessels/ports stay intact. Later phases add
        // "has commercial history" guards here.
        $company->delete();
    }

    public function addAlias(Company $company, string $alias, string $reason, User $actor): CompanyAlias
    {
        return CompanyAlias::query()->create([
            'company_id' => $company->id,
            'alias' => $alias,
            'normalized_alias' => NameNormalizer::normalize($alias),
            'reason' => $reason,
            'created_by' => $actor->id,
        ]);
    }

    /** @param list<string> $roles */
    private function syncRoles(Company $company, array $roles, User $actor): void
    {
        $roles = array_values(array_unique($roles));
        $before = $company->roles()->pluck('role')->sort()->values()->all();

        $company->roles()->whereNotIn('role', $roles)->delete();
        foreach (array_diff($roles, $before) as $role) {
            $company->roles()->create(['role' => $role]);
        }

        $after = collect($roles)->sort()->values()->all();
        if ($before !== $after) {
            activity('companies')->performedOn($company)->causedBy($actor)->event('roles_changed')
                ->withProperties(['old' => ['roles' => $before], 'attributes' => ['roles' => $after]])->log('Company roles changed');
        }
        $company->unsetRelation('roles');
    }

    private function guardDuplicates(string $legalName, ?string $country, ?int $ignoreId = null): void
    {
        $dupes = $this->possibleDuplicates($legalName, $country, $ignoreId);
        if ($dupes->isNotEmpty()) {
            throw new BusinessRuleException(
                'A company with a similar name already exists. Review the matches or confirm to create it anyway.',
                'possible_duplicate',
                ['duplicates' => $dupes->map(fn (Company $c) => "{$c->code} — {$c->legal_name}".($c->country ? " ({$c->country})" : ''))->all()],
            );
        }
    }
}
