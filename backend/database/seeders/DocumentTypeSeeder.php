<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['charter_party', 'Charter Party', false],
            ['fixture_note', 'Fixture Note / Recap', false],
            ['nor', 'Notice of Readiness', false],
            ['sof', 'Statement of Facts', false],
            ['certificate', 'Certificate', true],
            ['invoice', 'Invoice', false],
            ['port_da', 'Port DA', false],
            ['bunker_receipt', 'Bunker Receipt', false],
            ['bdn', 'Bunker Delivery Note (BDN)', false],
            ['survey', 'Survey Report', false],
            ['captain_report', 'Captain Report', false],
            ['agreement', 'Agreement', false],
            ['identification', 'Identification', true],
            ['other', 'Other', false],
        ];

        foreach ($types as [$code, $name, $requiresExpiry]) {
            DocumentType::query()->updateOrCreate(['code' => $code], ['name' => $name, 'requires_expiry' => $requiresExpiry, 'status' => 'active']);
        }
    }
}
