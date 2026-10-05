<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\SaveBankAccountRequest;
use App\Http\Requests\Company\SaveCompanyRequest;
use App\Http\Requests\Company\SaveContactRequest;
use App\Http\Resources\Masters\CompanyRefResource;
use App\Http\Resources\Masters\CompanyResource;
use App\Http\Resources\Masters\ContactResource;
use App\Models\Company;
use App\Models\CompanyAlias;
use App\Models\CompanyBankAccount;
use App\Models\Contact;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    private const DETAIL = ['roles', 'contacts', 'aliases', 'bankAccounts'];

    public function __construct(private readonly CompanyService $companies) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(CompanyRole::values())],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'blocked'])],
            'country' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->ok(CompanyResource::collection($this->companies->paginate($f, $this->perPage($request))));
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);
        $role = $request->input('role');
        if ($role !== null && ! is_array($role)) {
            $request->merge(['role' => [$role]]);
        }
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', 'array', 'max:10'], 'role.*' => ['string', Rule::in(CompanyRole::values())]]);

        return $this->ok(CompanyRefResource::collection($this->companies->lookup((string) ($f['search'] ?? ''), $f['role'] ?? null)));
    }

    public function duplicates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);
        $f = $request->validate(['legal_name' => ['required', 'string', 'max:200'], 'country' => ['nullable', 'string', 'size:2'], 'ignore_id' => ['nullable', 'integer']]);

        return $this->ok(CompanyRefResource::collection($this->companies->possibleDuplicates($f['legal_name'], $f['country'] ?? null, $f['ignore_id'] ?? null)));
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return $this->ok(new CompanyResource($company->load(self::DETAIL)));
    }

    public function store(SaveCompanyRequest $request): JsonResponse
    {
        $company = $this->companies->create($request->validated(), $request->user());

        return $this->created(new CompanyResource($company->load(self::DETAIL)), 'Company created.');
    }

    public function update(SaveCompanyRequest $request, Company $company): JsonResponse
    {
        $company = $this->companies->update($company, $request->validated(), $request->user());

        return $this->ok(new CompanyResource($company->load(self::DETAIL)), 'Company updated.');
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);
        $this->companies->delete($company);

        return $this->deleted('Company deleted.');
    }

    // ── Contacts ──────────────────────────────────────────────

    public function storeContact(SaveContactRequest $request, Company $company): JsonResponse
    {
        $contact = DB::transaction(function () use ($request, $company) {
            $data = $request->validated();
            if (! empty($data['is_primary'])) {
                $company->contacts()->update(['is_primary' => false]);
            }

            return $company->contacts()->create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        });

        return $this->created(new ContactResource($contact), 'Contact added.');
    }

    public function updateContact(SaveContactRequest $request, Company $company, Contact $contact): JsonResponse
    {
        abort_unless($contact->company_id === $company->id, 404);

        DB::transaction(function () use ($request, $company, $contact) {
            $data = $request->validated();
            if (! empty($data['is_primary'])) {
                $company->contacts()->whereKeyNot($contact->id)->update(['is_primary' => false]);
            }
            $contact->fill([...$data, 'updated_by' => $request->user()->id])->save();
        });

        return $this->ok(new ContactResource($contact), 'Contact updated.');
    }

    public function destroyContact(Company $company, Contact $contact): JsonResponse
    {
        $this->authorize('update', $company);
        abort_unless($contact->company_id === $company->id, 404);
        $contact->delete();

        return $this->deleted('Contact removed.');
    }

    // ── Bank accounts ────────────────────────────────────────

    public function storeBankAccount(SaveBankAccountRequest $request, Company $company): JsonResponse
    {
        $account = DB::transaction(function () use ($request, $company) {
            $data = $request->validated();
            if (! empty($data['is_primary'])) {
                $company->bankAccounts()->update(['is_primary' => false]);
            }

            return $company->bankAccounts()->create([...$data, 'created_by' => $request->user()->id]);
        });

        return $this->created(['id' => $account->id], 'Bank account added.');
    }

    public function updateBankAccount(SaveBankAccountRequest $request, Company $company, CompanyBankAccount $bankAccount): JsonResponse
    {
        abort_unless($bankAccount->company_id === $company->id, 404);
        DB::transaction(function () use ($request, $company, $bankAccount) {
            $data = $request->validated();
            if (! empty($data['is_primary'])) {
                $company->bankAccounts()->whereKeyNot($bankAccount->id)->update(['is_primary' => false]);
            }
            $bankAccount->fill($data)->save();
        });

        return $this->ok(['id' => $bankAccount->id], 'Bank account updated.');
    }

    public function destroyBankAccount(Request $request, Company $company, CompanyBankAccount $bankAccount): JsonResponse
    {
        $this->authorize('update', $company);
        $this->authorize('companies.bank-view');
        abort_unless($bankAccount->company_id === $company->id, 404);
        $bankAccount->delete();

        return $this->deleted('Bank account removed.');
    }

    // ── Aliases ──────────────────────────────────────────────

    public function storeAlias(Request $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);
        $data = $request->validate([
            'alias' => ['required', 'string', 'max:200'],
            'reason' => ['required', Rule::in(['former_name', 'abbreviation', 'other'])],
        ]);
        $alias = $this->companies->addAlias($company, $data['alias'], $data['reason'], $request->user());

        return $this->created(['id' => $alias->id, 'alias' => $alias->alias, 'reason' => $alias->reason], 'Alias added.');
    }

    public function destroyAlias(Company $company, CompanyAlias $alias): JsonResponse
    {
        $this->authorize('update', $company);
        abort_unless($alias->company_id === $company->id, 404);
        $alias->delete();

        return $this->deleted('Alias removed.');
    }
}
