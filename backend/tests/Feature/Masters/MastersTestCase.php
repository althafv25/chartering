<?php

namespace Tests\Feature\Masters;

use App\Models\Company;
use App\Models\Port;
use App\Models\VesselType;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class MastersTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seed(ReferenceDataSeeder::class);
    }

    protected function psvTypeId(): int
    {
        return VesselType::query()->where('code', 'PSV')->value('id');
    }

    protected function company(array $roles = ['owner'], array $attrs = []): Company
    {
        static $n = 0;
        $n++;
        $c = Company::query()->create(['code' => "T-{$n}", 'legal_name' => "Test Co {$n}", 'normalized_name' => "test co {$n}", ...$attrs]);
        foreach ($roles as $r) {
            $c->roles()->create(['role' => $r]);
        }

        return $c;
    }

    protected function port(string $name = 'Jebel Ali', string $code = 'AEJEA', ?string $lat = '25.0112', ?string $lon = '55.0612'): Port
    {
        return Port::query()->create(['name' => $name, 'normalized_name' => strtolower($name), 'unlocode' => $code, 'country' => substr($code, 0, 2), 'timezone' => 'Asia/Dubai', 'latitude' => $lat, 'longitude' => $lon]);
    }
}
