<?php

namespace Tests\Feature\Performance;

use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * Lazy loading is forbidden outside production, but Laravel only enforces it on result sets with 2+ rows, so
 * single-row feature tests never notice a missing eager load. This builds several voyages (each with its own
 * enquiry, estimation, offer, fixture) and requires every main list to answer 200 with a query count that
 * does not grow when more records are added.
 */
class ListEndpointsMultiRowTest extends OperationsTestCase
{
    private const LISTS = [
        '/api/v1/enquiries', '/api/v1/estimations', '/api/v1/offers', '/api/v1/fixtures', '/api/v1/contracts', '/api/v1/voyages',
        '/api/v1/vessels', '/api/v1/companies', '/api/v1/ports', '/api/v1/captain-reports', '/api/v1/bunker-stems', '/api/v1/port-das',
        '/api/v1/laytime-calculations', '/api/v1/offshore-activities', '/api/v1/offshore-projects', '/api/v1/users', '/api/v1/audit-logs',
    ];

    public function test_main_lists_answer_with_several_rows_and_do_not_n_plus_one(): void
    {
        $admin = $this->userWithRole(UserRole::SuperAdmin);
        $this->voyage();
        $this->voyage();

        $small = $this->counts($admin);
        $this->voyage();
        $this->voyage();
        $this->voyage();
        $large = $this->counts($admin);

        $grown = array_filter(array_keys($small), fn (string $u) => $large[$u] > $small[$u]);
        $this->assertSame([], array_values($grown), 'Lists whose query count grew with more rows: '.implode(', ', array_map(fn ($u) => "{$u} ({$small[$u]}→{$large[$u]})", $grown)));
    }

    /** @return array<string, int> */
    private function counts($admin): array
    {
        $this->as($admin);
        $out = [];
        foreach (self::LISTS as $url) {
            $this->getJson($url)->assertOk();   // warm settings / permission caches
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $out[$url] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $out;
    }
}
