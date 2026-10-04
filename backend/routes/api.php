<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AisController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Chartering\EnquiryController;
use App\Http\Controllers\Api\V1\Chartering\EstimationController;
use App\Http\Controllers\Api\V1\Chartering\FixtureController;
use App\Http\Controllers\Api\V1\Chartering\OfferController;
use App\Http\Controllers\Api\V1\Chartering\ScenarioController;
use App\Http\Controllers\Api\V1\Contracts\AmendmentController;
use App\Http\Controllers\Api\V1\Contracts\ContractController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\Finance\AgingController;
use App\Http\Controllers\Api\V1\Finance\BalancingController;
use App\Http\Controllers\Api\V1\Finance\InvoiceController;
use App\Http\Controllers\Api\V1\Finance\PayableController;
use App\Http\Controllers\Api\V1\Finance\PaymentController;
use App\Http\Controllers\Api\V1\Finance\ReportController;
use App\Http\Controllers\Api\V1\Finance\VoyageExpenseController;
use App\Http\Controllers\Api\V1\Finance\VoyageFinancialController;
use App\Http\Controllers\Api\V1\Finance\VoyageRevenueController;
use App\Http\Controllers\Api\V1\Masters\CompanyController;
use App\Http\Controllers\Api\V1\Masters\ConsumptionProfileController;
use App\Http\Controllers\Api\V1\Masters\CurrencyController;
use App\Http\Controllers\Api\V1\Masters\DistanceController;
use App\Http\Controllers\Api\V1\Masters\ExchangeRateController;
use App\Http\Controllers\Api\V1\Masters\OffshoreLocationController;
use App\Http\Controllers\Api\V1\Masters\PortController;
use App\Http\Controllers\Api\V1\Masters\ReferenceDataController;
use App\Http\Controllers\Api\V1\Masters\VesselController;
use App\Http\Controllers\Api\V1\Masters\VesselStatusController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Operations\BunkerController;
use App\Http\Controllers\Api\V1\Operations\CaptainReportController;
use App\Http\Controllers\Api\V1\Operations\LaytimeController;
use App\Http\Controllers\Api\V1\Operations\MilestoneController;
use App\Http\Controllers\Api\V1\Operations\OffHireController;
use App\Http\Controllers\Api\V1\Operations\OffshoreActivityController;
use App\Http\Controllers\Api\V1\Operations\OffshoreProjectController;
use App\Http\Controllers\Api\V1\Operations\PortCallController;
use App\Http\Controllers\Api\V1\Operations\PortDaController;
use App\Http\Controllers\Api\V1\Operations\VoyageController;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('/up', fn () => Response::HTTP_OK);

    // ===== ADMIN ROUTES (ROOT LEVEL, NOT NESTED) =====
    Route::apiResource('users', UserController::class);
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
    Route::get('roles/permissions', [RoleController::class, 'permissions']);
    Route::apiResource('roles', RoleController::class);
    Route::get('audit-logs', [AuditLogController::class, 'index']);
    Route::get('audit-logs/filters', [AuditLogController::class, 'filters']);
    Route::prefix('settings')->group(function () {
        Route::get('', [SettingsController::class, 'index']);
        Route::put('', [SettingsController::class, 'update']);
    });

    // ===== MASTERS ROUTES =====
    Route::get('vessels/lookup', [VesselController::class, 'lookup']);
    Route::apiResource('vessels', VesselController::class);
    Route::get('vessel-status/catalogue', [VesselStatusController::class, 'catalogue']);
    Route::get('vessel-status/board', [VesselStatusController::class, 'board']);
    Route::get('vessels/{vessel}/status-history', [VesselStatusController::class, 'history']);
    Route::post('vessels/{vessel}/status', [VesselStatusController::class, 'store']);
    Route::post('vessels/{vessel}/status/undo', [VesselStatusController::class, 'undo']);
    Route::get('vessels/{vessel}/consumption-profiles', [ConsumptionProfileController::class, 'index']);
    Route::post('vessels/{vessel}/consumption-profiles', [ConsumptionProfileController::class, 'store']);
    Route::put('vessels/{vessel}/consumption-profiles/{profile}', [ConsumptionProfileController::class, 'update']);
    Route::delete('vessels/{vessel}/consumption-profiles/{profile}', [ConsumptionProfileController::class, 'destroy']);
    Route::get('companies/lookup', [CompanyController::class, 'lookup']);
    Route::get('companies/duplicates', [CompanyController::class, 'duplicates']);
    Route::apiResource('companies', CompanyController::class);
    Route::post('companies/{company}/contacts', [CompanyController::class, 'storeContact']);
    Route::put('companies/{company}/contacts/{contact}', [CompanyController::class, 'updateContact']);
    Route::delete('companies/{company}/contacts/{contact}', [CompanyController::class, 'destroyContact']);
    Route::post('companies/{company}/bank-accounts', [CompanyController::class, 'storeBankAccount']);
    Route::put('companies/{company}/bank-accounts/{bankAccount}', [CompanyController::class, 'updateBankAccount']);
    Route::delete('companies/{company}/bank-accounts/{bankAccount}', [CompanyController::class, 'destroyBankAccount']);
    Route::post('companies/{company}/aliases', [CompanyController::class, 'storeAlias']);
    Route::delete('companies/{company}/aliases/{alias}', [CompanyController::class, 'destroyAlias']);
    Route::get('ports/lookup', [PortController::class, 'lookup']);
    Route::apiResource('ports', PortController::class);
    Route::post('ports/{port}/agents', [PortController::class, 'addAgent']);
    Route::delete('ports/{port}/agents/{companyId}', [PortController::class, 'removeAgent']);
    Route::apiResource('currencies', CurrencyController::class)->only(['index', 'store', 'update']);
    Route::get('exchange-rates/convert', [ExchangeRateController::class, 'convert']);
    Route::apiResource('exchange-rates', ExchangeRateController::class)->except(['show']);
    Route::get('route-points', [DistanceController::class, 'points']);
    Route::post('distances/calculate', [DistanceController::class, 'calculate']);
    Route::apiResource('distances', DistanceController::class)->only(['index', 'store', 'destroy']);
    Route::get('reference', [ReferenceDataController::class, 'catalogue']);
    Route::get('reference/{type}', [ReferenceDataController::class, 'index'])->where('type', '[a-z-]+');
    Route::post('reference/{type}', [ReferenceDataController::class, 'store'])->where('type', '[a-z-]+');
    Route::put('reference/{type}/{id}', [ReferenceDataController::class, 'update'])->where('type', '[a-z-]+');
    Route::delete('reference/{type}/{id}', [ReferenceDataController::class, 'destroy'])->where('type', '[a-z-]+');
    Route::put('reference/vessel-types/{vesselType}/attributes', [ReferenceDataController::class, 'updateVesselTypeAttributes']);
    Route::apiResource('offshore-locations', OffshoreLocationController::class);

    // ===== CHARTERING ROUTES =====
    Route::apiResource('enquiries', EnquiryController::class);
    Route::get('enquiries/{enquiry}/activity', [EnquiryController::class, 'history']);
    Route::post('enquiries/{enquiry}/status', [EnquiryController::class, 'status']);
    Route::post('enquiries/{enquiry}/vessels', [EnquiryController::class, 'shortlist']);
    Route::delete('enquiries/{enquiry}/vessels/{vesselId}', [EnquiryController::class, 'unshortlist']);

    Route::apiResource('offers', OfferController::class)->only(['index', 'show', 'store']);
    Route::post('offers/{offer}/withdraw', [OfferController::class, 'withdraw']);
    Route::get('offers/{offer}/activity', [OfferController::class, 'history']);
    Route::post('offers/{offer}/revisions', [OfferController::class, 'storeRevision']);
    Route::put('offers/{offer}/revisions/{revision}', [OfferController::class, 'updateRevision']);
    foreach (['send', 'receive', 'accept', 'reject'] as $action) {
        Route::post("offers/{offer}/revisions/{revision}/{$action}", [OfferController::class, $action]);
    }
    Route::get('offers/{offer}/revisions/{revision}/diff/{other}', [OfferController::class, 'diff']);
    Route::post('offers/{offer}/revisions/{revision}/convert-to-fixture', [OfferController::class, 'createFixture']);

    Route::apiResource('estimations', EstimationController::class)->except(['destroy']);
    Route::post('estimations/{estimation}/submit', [EstimationController::class, 'submit']);
    Route::post('estimations/{estimation}/approve', [EstimationController::class, 'approve']);
    Route::post('estimations/{estimation}/reject', [EstimationController::class, 'reject']);
    Route::post('estimations/{estimation}/reopen', [EstimationController::class, 'reopen']);
    Route::post('estimations/{estimation}/clone', [EstimationController::class, 'clone']);
    Route::get('estimations/{estimation}/activity', [EstimationController::class, 'history']);
    Route::get('estimations/{estimation}/compare', [EstimationController::class, 'compare']);
    Route::post('estimations/{estimation}/convert-to-voyage', [EstimationController::class, 'convertToVoyage']);
    Route::post('estimations/{estimation}/scenarios', [ScenarioController::class, 'store']);
    Route::get('estimations/{estimation}/scenarios/{scenario}', [ScenarioController::class, 'show']);
    Route::put('estimations/{estimation}/scenarios/{scenario}', [ScenarioController::class, 'update']);
    Route::post('estimations/{estimation}/scenarios/{scenario}/calculate', [ScenarioController::class, 'calculate']);
    Route::post('estimations/{estimation}/scenarios/{scenario}/refresh-defaults', [ScenarioController::class, 'refreshDefaults']);
    Route::post('estimations/{estimation}/scenarios/{scenario}/select', [ScenarioController::class, 'select']);

    Route::apiResource('fixtures', FixtureController::class)->only(['index', 'show', 'update']);
    Route::post('fixtures/{fixture}/{action}', [FixtureController::class, 'transition'])->whereIn('action', ['submit', 'approve', 'cancel', 'fail', 'reject']);
    Route::get('fixtures/{fixture}/activity', [FixtureController::class, 'fixtureActivity']);
    Route::post('fixtures/{fixture}/convert-to-contract', [FixtureController::class, 'toContract']);
    Route::post('fixtures/{fixture}/convert-to-voyage', [FixtureController::class, 'toVoyage']);

    // ===== CONTRACTS ROUTES =====
    Route::apiResource('contracts', ContractController::class)->except(['destroy']);
    Route::post('contracts/{contract}/submit', [ContractController::class, 'submit']);
    Route::post('contracts/{contract}/approve', [ContractController::class, 'approve']);
    Route::post('contracts/{contract}/reject', [ContractController::class, 'reject']);
    Route::post('contracts/{contract}/activate', [ContractController::class, 'activate']);
    Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel']);
    Route::post('contracts/{contract}/complete', [ContractController::class, 'complete']);
    Route::put('contracts/{contract}/rates', [ContractController::class, 'rates']);
    Route::put('contracts/{contract}/clauses', [ContractController::class, 'clauses']);
    Route::get('contracts/{contract}/effective-rates', [ContractController::class, 'effectiveRates']);
    Route::get('contracts/{contract}/activity', [ContractController::class, 'history']);

    Route::post('contracts/{contract}/amendments', [AmendmentController::class, 'store']);
    Route::put('contracts/{contract}/amendments/{amendment}', [AmendmentController::class, 'update']);
    Route::post('contracts/{contract}/amendments/{amendment}/submit', [AmendmentController::class, 'submit']);
    Route::post('contracts/{contract}/amendments/{amendment}/approve', [AmendmentController::class, 'approve']);
    Route::post('contracts/{contract}/amendments/{amendment}/reject', [AmendmentController::class, 'reject']);
    Route::post('contracts/{contract}/amendments/{amendment}/withdraw', [AmendmentController::class, 'withdraw']);

    // ===== OPERATIONS ROUTES =====
    Route::apiResource('voyages', VoyageController::class)->only(['index', 'show', 'update']);
    Route::post('voyages/{voyage}/transition', [VoyageController::class, 'transition']);
    Route::post('voyages/{voyage}/complete', [VoyageController::class, 'complete']);
    Route::post('voyages/{voyage}/finalize', [VoyageController::class, 'finalize']);
    Route::post('voyages/{voyage}/reopen', [VoyageController::class, 'reopen']);
    Route::post('voyages/{voyage}/cancel', [VoyageController::class, 'cancel']);
    Route::get('voyages/{voyage}/finance-gates', [VoyageController::class, 'financeGates']);
    Route::post('voyages/{voyage}/snapshots', [VoyageController::class, 'storeSnapshot']);
    Route::get('voyages/{voyage}/comparison', [VoyageController::class, 'comparison']);
    Route::get('voyages/{voyage}/activity', [VoyageController::class, 'history']);
    Route::get('voyages/{voyage}/rob-ledger', [BunkerController::class, 'robLedger']);

    Route::prefix('voyages/{voyage}')->group(function () {
        Route::apiResource('port-calls', PortCallController::class)->only(['store', 'update', 'destroy']);
        Route::post('port-calls/{portCall}/cancel', [PortCallController::class, 'cancel']);
        Route::apiResource('off-hire', OffHireController::class)->parameters(['off-hire' => 'offHire'])->only(['store', 'update', 'destroy']);
        Route::post('off-hire/{offHire}/agree', [OffHireController::class, 'agree']);
        Route::post('off-hire/{offHire}/dispute', [OffHireController::class, 'dispute']);
        Route::apiResource('milestones', MilestoneController::class)->only(['store', 'update', 'destroy']);
        Route::post('milestones/{milestone}/verify', [MilestoneController::class, 'verify']);
    });

    Route::apiResource('offshore-projects', OffshoreProjectController::class)->except(['destroy']);
    Route::apiResource('offshore-activities', OffshoreActivityController::class);
    foreach (['submit', 'verify', 'reject'] as $action) {
        Route::post("offshore-activities/{offshoreActivity}/{$action}", [OffshoreActivityController::class, $action]);
    }

    Route::apiResource('bunker-stems', BunkerController::class)->except(['destroy']);
    Route::post('bunker-stems/{bunkerStem}/deliver', [BunkerController::class, 'deliver']);
    Route::post('bunker-stems/{bunkerStem}/cancel', [BunkerController::class, 'cancel']);

    Route::apiResource('port-das', PortDaController::class)->except(['destroy']);
    Route::post('port-das/{portDa}/items', [PortDaController::class, 'saveItems']);
    foreach (['submit', 'approve', 'reject'] as $action) {
        Route::post("port-das/{portDa}/{$action}", [PortDaController::class, $action]);
    }

    Route::apiResource('laytime-calculations', LaytimeController::class)->parameters(['laytime-calculations' => 'laytimeCalculation'])->except(['destroy']);
    foreach (['calculate', 'submit', 'agree', 'dispute'] as $action) {
        Route::post("laytime-calculations/{laytimeCalculation}/{$action}", [LaytimeController::class, $action]);
    }
    Route::post('laytime-calculations/{laytimeCalculation}/sof-events', [LaytimeController::class, 'addSofEvent']);
    Route::delete('laytime-calculations/{laytimeCalculation}/sof-events/{sofEvent}', [LaytimeController::class, 'removeSofEvent']);
    Route::post('laytime-calculations/{laytimeCalculation}/exceptions', [LaytimeController::class, 'addException']);
    Route::put('laytime-calculations/{laytimeCalculation}/exceptions/{exception}', [LaytimeController::class, 'updateException']);
    Route::delete('laytime-calculations/{laytimeCalculation}/exceptions/{exception}', [LaytimeController::class, 'removeException']);

    Route::apiResource('captain-reports', CaptainReportController::class);
    foreach (['submit', 'verify', 'reject'] as $action) {
        Route::post("captain-reports/{captainReport}/{$action}", [CaptainReportController::class, $action]);
    }

    // ===== VOYAGE FINANCIAL ROUTES =====
    Route::apiResource('voyage-revenues', VoyageRevenueController::class);
    Route::post('voyage-revenues/{voyageRevenue}/confirm', [VoyageRevenueController::class, 'confirm']);
    Route::post('voyage-revenues/{voyageRevenue}/cancel', [VoyageRevenueController::class, 'cancel']);
    Route::apiResource('voyage-expenses', VoyageExpenseController::class);
    Route::post('voyage-expenses/{voyageExpense}/confirm', [VoyageExpenseController::class, 'confirm']);
    Route::post('voyage-expenses/{voyageExpense}/approve', [VoyageExpenseController::class, 'approve']);
    Route::post('voyage-expenses/{voyageExpense}/cancel', [VoyageExpenseController::class, 'cancel']);
    Route::get('voyages/{voyage}/financials', [VoyageFinancialController::class, 'show']);

    // ===== COMMERCIAL/FINANCE ROUTES =====
    Route::apiResource('invoices', InvoiceController::class);
    Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit']);
    Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve']);
    Route::post('invoices/{invoice}/reject', [InvoiceController::class, 'reject']);
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::post('invoices/{invoice}/pdf', [InvoiceController::class, 'generatePdf']);
    Route::post('invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote']);
    Route::post('invoices/{invoice}/lines', [InvoiceController::class, 'saveLines']);
    Route::post('invoices/{invoice}/revenue-lines', [InvoiceController::class, 'attachRevenueLines']);

    Route::apiResource('payables', PayableController::class);
    Route::post('payables/{payable}/approve', [PayableController::class, 'approve']);
    Route::post('payables/{payable}/reapprove', [PayableController::class, 'reapprove']);
    Route::post('payables/{payable}/cancel', [PayableController::class, 'cancel']);

    Route::apiResource('payments', PaymentController::class)->except(['update']);
    Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate']);
    Route::delete('payments/{payment}/allocations/{allocation}', [PaymentController::class, 'unallocate']);
    Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse']);

    Route::get('receivables/aging', [AgingController::class, 'index']);
    Route::get('balancing/accounts', [BalancingController::class, 'accounts']);
    Route::get('balancing/cash-flow', [BalancingController::class, 'cashFlow']);
    Route::get('reports', [ReportController::class, 'catalogue']);
    Route::get('reports/{slug}', [ReportController::class, 'show']);
    Route::get('statistics', [ReportController::class, 'statisticsCatalogue']);
    Route::get('statistics/{metric}', [ReportController::class, 'statistic']);

    Route::get('dashboard', [DashboardController::class, 'index']);

    // ===== AIS ROUTES =====
    Route::prefix('ais')->group(function () {
        Route::post('positions/manual', [AisController::class, 'manual']);
        Route::get('fleet', [AisController::class, 'fleet']);
        Route::get('status', [AisController::class, 'status']);
        Route::get('vessels/{vessel}/positions', [AisController::class, 'positions']);
        Route::get('vessels/{vessel}/track', [AisController::class, 'track']);
    });

    // ===== DOCUMENT ROUTES =====
    Route::get('document-types', [DocumentController::class, 'types']);
    Route::get('documents', [DocumentController::class, 'register']);
    Route::apiResource('documents', DocumentController::class)->only(['show', 'destroy']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);
    Route::get('{parentType}/{parentId}/documents', [DocumentController::class, 'index'])->whereNumber('parentId');
    Route::post('{parentType}/{parentId}/documents', [DocumentController::class, 'store'])->whereNumber('parentId')->middleware('throttle:uploads');

    // ===== AUTH ROUTES =====
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->withoutMiddleware('auth:sanctum')->middleware('throttle:login');
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->withoutMiddleware('auth:sanctum')->middleware('throttle:password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->withoutMiddleware('auth:sanctum')->middleware('throttle:password');
        Route::post('change-password', [AuthController::class, 'changePassword']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [ProfileController::class, 'update']);
    });
    Route::put('profile', [ProfileController::class, 'update']);

    // ===== NOTIFICATION ROUTES =====
    Route::apiResource('notifications', NotificationController::class)->only(['index']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
});
