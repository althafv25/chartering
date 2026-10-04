<?php

use App\Models\BunkerStem;
use App\Models\CaptainReport;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Enquiry;
use App\Models\Estimation;
use App\Models\Fixture;
use App\Models\Invoice;
use App\Models\LaytimeCalculation;
use App\Models\Offer;
use App\Models\OffshoreActivity;
use App\Models\OffshoreLocation;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\Port;
use App\Models\PortDa;
use App\Models\User;
use App\Models\Vessel;
use App\Models\Voyage;

return [

    /*
    |--------------------------------------------------------------------------
    | Base (reporting) currency
    |--------------------------------------------------------------------------
    | All monetary rows store their own currency + FX snapshot + base amount.
    | BR-FX-01 (requires confirmation) — proposed USD.
    */
    'base_currency' => env('BASE_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | AIS (docs/09). Provider: none (default) | manual. Credentials for a future commercial
    | provider belong in .env only; BR-AIS-01 (provider choice) is still open.
    |--------------------------------------------------------------------------
    */
    /*
    | Report size limits: rows shown on screen, and rows allowed in a file. A file over its limit is refused
    | (422 report_too_large) instead of being cut silently.
    */
    'reports' => [
        'view_limit' => (int) env('REPORT_VIEW_LIMIT', 2000),
        'pdf_limit' => (int) env('REPORT_PDF_LIMIT', 3000),
        'export_limit' => (int) env('REPORT_EXPORT_LIMIT', 50000),
    ],

    'ais' => [
        'provider' => env('AIS_PROVIDER', 'none'),
    ],

    /** Requests per minute per user (per IP when signed out). Raised only for automated browser tests. */
    'api_rate_limit' => (int) env('API_RATE_LIMIT', 120),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */
    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 'documents'),
        'max_kb' => (int) env('DOCUMENTS_MAX_KB', 51200),
        // Extension allow-list; MIME is additionally sniffed server-side.
        'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'msg', 'eml'],

        /*
         * Morph aliases that may own documents, and the permissions needed
         * on the parent record to read / attach / delete. Later phases add
         * enquiries, fixtures, contracts, voyages, invoices, ...
         */
        'parents' => [
            'users' => ['model' => User::class, 'view' => 'users.view', 'upload' => 'users.update', 'delete' => 'users.update'],
            'vessels' => ['model' => Vessel::class, 'view' => 'vessels.view', 'upload' => 'vessels.update', 'delete' => 'vessels.update'],
            'companies' => ['model' => Company::class, 'view' => 'companies.view', 'upload' => 'companies.update', 'delete' => 'companies.update'],
            'ports' => ['model' => Port::class, 'view' => 'ports.view', 'upload' => 'ports.update', 'delete' => 'ports.update'],
            'enquiries' => ['model' => Enquiry::class, 'view' => 'chartering.enquiries.view', 'upload' => 'chartering.enquiries.update', 'delete' => 'chartering.enquiries.update'],
            'estimations' => ['model' => Estimation::class, 'view' => 'chartering.estimations.view', 'upload' => 'chartering.estimations.update', 'delete' => 'chartering.estimations.update'],
            'offers' => ['model' => Offer::class, 'view' => 'chartering.offers.view', 'upload' => 'chartering.offers.update', 'delete' => 'chartering.offers.update'],
            'fixtures' => ['model' => Fixture::class, 'view' => 'chartering.fixtures.view', 'upload' => 'chartering.fixtures.update', 'delete' => 'chartering.fixtures.update'],
            'contracts' => ['model' => Contract::class, 'view' => 'contracts.view', 'upload' => 'contracts.update', 'delete' => 'contracts.update'],
            'voyages' => ['model' => Voyage::class, 'view' => 'operations.voyages.view', 'upload' => 'operations.voyages.update', 'delete' => 'operations.voyages.update'],
            'captain-reports' => ['model' => CaptainReport::class, 'view' => 'operations.captain-reports.view', 'upload' => 'operations.captain-reports.update', 'delete' => 'operations.captain-reports.update'],
            'offshore-activities' => ['model' => OffshoreActivity::class, 'view' => 'operations.offshore-activities.view', 'upload' => 'operations.offshore-activities.update', 'delete' => 'operations.offshore-activities.update'],
            'bunker-stems' => ['model' => BunkerStem::class, 'view' => 'operations.bunkers.view', 'upload' => 'operations.bunkers.manage', 'delete' => 'operations.bunkers.manage'],
            'invoices' => ['model' => Invoice::class, 'view' => 'invoices.view', 'upload' => 'invoices.update', 'delete' => 'invoices.update'],
            'payables' => ['model' => Payable::class, 'view' => 'payables.view', 'upload' => 'payables.create', 'delete' => 'payables.create'],
            'payments' => ['model' => Payment::class, 'view' => 'payments.view', 'upload' => 'payments.create', 'delete' => 'payments.create'],
            'port-das' => ['model' => PortDa::class, 'view' => 'operations.port-da.view', 'upload' => 'operations.port-da.update', 'delete' => 'operations.port-da.update'],
            'laytime-calculations' => ['model' => LaytimeCalculation::class, 'view' => 'operations.laytime.view', 'upload' => 'operations.laytime.update', 'delete' => 'operations.laytime.update'],
            'offshore-locations' => ['model' => OffshoreLocation::class, 'view' => 'ports.view', 'upload' => 'ports.update', 'delete' => 'ports.update'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings registry
    |--------------------------------------------------------------------------
    | Only keys listed here can be read/written through the Settings API.
    | type: string|int|decimal|bool ; rules: Laravel validation rules.
    */
    'settings' => [
        'company.name' => ['group' => 'company', 'type' => 'string', 'default' => 'Offshore Chartering', 'rules' => ['string', 'max:150']],
        'company.address' => ['group' => 'company', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:500']],
        'company.tax_number' => ['group' => 'company', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:50']],
        'company.email' => ['group' => 'company', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'email', 'max:150']],
        'company.phone' => ['group' => 'company', 'type' => 'string', 'default' => '', 'rules' => ['nullable', 'string', 'max:50']],
        'general.date_format' => ['group' => 'general', 'type' => 'string', 'default' => 'dd MMM yyyy', 'rules' => ['string', 'in:dd MMM yyyy,dd/MM/yyyy,yyyy-MM-dd,MM/dd/yyyy']],
        'general.default_timezone' => ['group' => 'general', 'type' => 'string', 'default' => 'Asia/Dubai', 'rules' => ['string', 'timezone:all']],
        'notifications.contract_expiry_days' => ['group' => 'notifications', 'type' => 'int', 'default' => 60, 'rules' => ['integer', 'min:1', 'max:365']],
        'notifications.document_expiry_days' => ['group' => 'notifications', 'type' => 'int', 'default' => 30, 'rules' => ['integer', 'min:1', 'max:365']],
        'contracts.auto_expire' => ['group' => 'contracts', 'type' => 'bool', 'default' => true, 'rules' => ['boolean']],
        // BR-OA-01/02/03 are unconfirmed: implemented as switchable settings with the documented proposals as defaults.
        'offshore.day_rate_proration' => ['group' => 'offshore', 'type' => 'string', 'default' => 'hourly', 'rules' => ['string', 'in:hourly,half_day,full_day'],
            'help' => 'BR-OA-01 (awaiting confirmation): hourly = hours/24; half_day = per started 12 h; full_day = per started 24 h. Applied when an activity is saved or submitted.'],
        'offshore.standby_basis' => ['group' => 'offshore', 'type' => 'string', 'default' => 'standby_rate', 'rules' => ['string', 'in:standby_rate,full_rate'],
            'help' => 'BR-OA-02 (awaiting confirmation): price standby hours at the contract standby day rate or at the full billable rate. No cap is applied.'],
        'offshore.mob_demob_auto' => ['group' => 'offshore', 'type' => 'bool', 'default' => true, 'rules' => ['boolean'],
            'help' => 'BR-OA-03 (awaiting confirmation): add the contract mobilization/demobilization fee to the first MOB/DEMOB activity of the contract.'],
        'bunker.discrepancy_threshold_pct' => ['group' => 'bunker', 'type' => 'decimal', 'default' => '5', 'rules' => ['numeric', 'min:0', 'max:100'],
            'help' => 'BK-03 (default awaiting confirmation): flag a ROB-ledger period when actual consumption differs from the estimate by more than this percentage.'],
        'ais.enabled' => ['group' => 'ais', 'type' => 'bool', 'default' => false, 'rules' => ['boolean'],
            'help' => 'AIS is optional. While off, the fleet map and AIS endpoints are unavailable; positions still come from captain reports.'],
        'ais.stale_hours' => ['group' => 'ais', 'type' => 'int', 'default' => 6, 'rules' => ['integer', 'min:1', 'max:168'],
            'help' => 'AIS-02 (default awaiting confirmation): a position older than this is stale; a vessel on an active voyage with a stale or missing position triggers a notification.'],
        'approvals.self_approval_allowed' => ['group' => 'approvals', 'type' => 'bool', 'default' => false, 'rules' => ['boolean']],
        'notifications.email_enabled' => ['group' => 'notifications', 'type' => 'bool', 'default' => false, 'rules' => ['boolean']],
    ],
];
