<?php

namespace App\Http\Resources\Masters;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeBank = (bool) $request->user()?->can('companies.bank-view');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'legal_name' => $this->legal_name,
            'trading_name' => $this->trading_name,
            'roles' => $this->relationLoaded('roles') ? $this->roleNames() : [],
            'country' => $this->country,
            'city' => $this->city,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'postal_code' => $this->postal_code,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'tax_number' => $this->tax_number,
            'vat_registered' => $this->vat_registered,
            'default_currency' => $this->default_currency,
            'payment_terms_days' => $this->payment_terms_days,
            'credit_limit' => $this->credit_limit,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'lock_version' => $this->lock_version,
            'contacts_count' => $this->whenCounted('contacts'),
            'contacts' => ContactResource::collection($this->whenLoaded('contacts')),
            'aliases' => $this->whenLoaded('aliases', fn () => $this->aliases->map(fn ($a) => [
                'id' => $a->id, 'alias' => $a->alias, 'reason' => $a->reason, 'valid_to' => $a->valid_to?->toDateString(),
            ])),
            'bank_accounts' => $this->whenLoaded('bankAccounts', fn () => $this->bankAccounts->map(fn ($b) => [
                'id' => $b->id,
                'bank_name' => $b->bank_name,
                'account_name' => $b->account_name,
                'account_number' => $canSeeBank ? $b->account_number : self::mask($b->account_number),
                'iban' => $canSeeBank ? $b->iban : self::mask($b->iban),
                'swift_bic' => $b->swift_bic,
                'currency' => $b->currency,
                'is_primary' => $b->is_primary,
                'masked' => ! $canSeeBank,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }
}
