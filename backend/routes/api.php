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

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('/up', fn () => Response::HTTP_OK);

    // ===== ADMIN ROUTES (ROOT LEVEL, NOT NESTED) =====
    Route::apiResource('users', UserController::class);
    Route::get('roles/permissions', [RoleController::class, 'permissions']);
    Route::apiResource('roles', RoleController::class);
    Route::get('audit-logs', [AuditLogController::class, 'index']);
    Route::prefix('settings')->group(function () {
        Route::get('', [SettingsController::class, 'index']);
        Route::put('', [SettingsController::class, 'update']);
    });

    // ===== MASTERS ROUTES =====
    Route::apiResource('vessels', VesselController::class);
    Route::apiResource('companies', CompanyController::class);
    Route::get('companies/lookup', [CompanyController::class, 'lookup']);
    Route::get('companies/duplicates', [CompanyController::class, 'duplicates']);
    Route::apiResource('ports', PortController::class);
    Route::get('ports/lookup', [PortController::class, 'lookup']);
    Route::apiResource('currencies', CurrencyController::class);
    Route::apiResource('exchange-rates', ExchangeRateController::class);
    Route::get('exchange-rates/convert', [ExchangeRateController::class, 'convert']);
    Route::apiResource('distances', DistanceController::class);
    Route::get('distances/calculate', [DistanceController::class, 'calculate']);
    Route::put('distances/{from}/{to}', [DistanceController::class, 'override']);
    Route::apiResource('vessel-status', VesselStatusController::class)->only(['index', 'update']);
    Route::get('reference/{catalogue}', [ReferenceDataController::class, 'index']);
    Route::apiResource('offshore-locations', OffshoreLocationController::class);

    // ===== CHARTERING ROUTES =====
    Route::apiResource('enquiries', EnquiryController::class)->except(['destroy']);
    Route::post('enquiries/{enquiry}/submit', [EnquiryController::class, 'submit']);
    Route::post('enquiries/{enquiry}/fix', [EnquiryController::class, 'fix']);
    Route::post('enquiries/{enquiry}/unfix', [EnquiryController::class, 'unfix']);
    Route::post('enquiries/{enquiry}/reject', [EnquiryController::class, 'reject']);
    Route::get('enquiries/{enquiry}/activity', [EnquiryController::class, 'activity']);
    Route::get('enquiries/{enquiry}/status', [EnquiryController::class, 'status']);
    Route::get('enquiries/{enquiry}/vessels', [EnquiryController::class, 'vessels']);
    Route::post('enquiries/{enquiry}/vessels/{vessel}', [EnquiryController::class, 'addVessel']);

    Route::apiResource('offers', OfferController::class)->except(['destroy']);
    Route::post('offers/{offer}/send', [OfferController::class, 'send']);
    Route::post('offers/{offer}/accept', [OfferController::class, 'accept']);
    Route::post('offers/{offer}/reject', [OfferController::class, 'reject']);
    Route::get('offers/{offer}/activity', [OfferController::class, 'activity']);
    Route::get('offers/{offer}/revisions', [OfferController::class, 'revisions']);

    Route::apiResource('estimations', EstimationController::class);
    Route::post('estimations/{estimation}/submit', [EstimationController::class, 'submit']);
    Route::post('estimations/{estimation}/approve', [EstimationController::class, 'approve']);
    Route::post('estimations/{estimation}/reject', [EstimationController::class, 'reject']);
    Route::post('estimations/{estimation}/reopen', [EstimationController::class, 'reopen']);
    Route::post('estimations/{estimation}/clone', [EstimationController::class, 'clone']);
    Route::get('estimations/{estimation}/activity', [EstimationController::class, 'activity']);
    Route::get('estimations/{estimation}/compare', [EstimationController::class, 'compare']);
    Route::post('estimations/{estimation}/convert-to-voyage', [EstimationController::class, 'convertToVoyage']);
    Route::get('estimations/{estimation}/scenarios', [ScenarioController::class, 'index']);
    Route::post('estimations/{estimation}/scenarios', [ScenarioController::class, 'store']);
    Route::get('estimations/{estimation}/scenarios/{scenario}', [ScenarioController::class, 'show']);
    Route::post('estimations/{estimation}/scenarios/{scenario}/calculate', [ScenarioController::class, 'calculate']);
    Route::post('estimations/{estimation}/scenarios/{scenario}/refresh-defaults', [ScenarioController::class, 'refreshDefaults']);
    Route::post('estimations/{estimation}/scenarios/{scenario}/select', [ScenarioController::class, 'select']);

    Route::apiResource('fixtures', FixtureController::class);
    Route::post('fixtures/{fixture}/submit', [FixtureController::class, 'submit']);
    Route::post('fixtures/{fixture}/approve', [FixtureController::class, 'approve']);
    Route::post('fixtures/{fixture}/cancel', [FixtureController::class, 'cancel']);
    Route::post('fixtures/{fixture}/fail', [FixtureController::class, 'fail']);
    Route::post('fixtures/{fixture}/reject', [FixtureController::class, 'reject']);
    Route::get('fixtures/{fixture}/activity', [FixtureController::class, 'activity']);
    Route::post('fixtures/{fixture}/convert-to-contract', [FixtureController::class, 'convertToContract']);
    Route::post('fixtures/{fixture}/convert-to-voyage', [FixtureController::class, 'convertToVoyage']);

    // ===== CONTRACTS ROUTES =====
    Route::apiResource('contracts', ContractController::class)->except(['destroy']);
    Route::post('contracts/{contract}/submit', [ContractController::class, 'submit']);
    Route::post('contracts/{contract}/approve', [ContractController::class, 'approve']);
    Route::post('contracts/{contract}/activate', [ContractController::class, 'activate']);
    Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel']);
    Route::post('contracts/{contract}/complete', [ContractController::class, 'complete']);
    Route::get('contracts/{contract}/rates', [ContractController::class, 'rates']);
    Route::get('contracts/{contract}/effective-rates', [ContractController::class, 'effectiveRates']);
    Route::get('contracts/{contract}/activity', [ContractController::class, 'activity']);

    Route::get('contracts/{contract}/amendments', [AmendmentController::class, 'index']);
    Route::post('contracts/{contract}/amendments', [AmendmentController::class, 'store']);
    Route::get('contracts/{contract}/amendments/{amendment}', [AmendmentController::class, 'show']);
    Route::post('contracts/{contract}/amendments/{amendment}/submit', [AmendmentController::class, 'submit']);
    Route::post('contracts/{contract}/amendments/{amendment}/approve', [AmendmentController::class, 'approve']);
    Route::post('contracts/{contract}/amendments/{amendment}/reject', [AmendmentController::class, 'reject']);
    Route::post('contracts/{contract}/amendments/{amendment}/withdraw', [AmendmentController::class, 'withdraw']);

    // ===== OPERATIONS ROUTES =====
    Route::apiResource('voyages', VoyageController::class);
    Route::post('voyages/{voyage}/complete', [VoyageController::class, 'complete']);
    Route::post('voyages/{voyage}/finalize', [VoyageController::class, 'finalize']);
    Route::post('voyages/{voyage}/reopen', [VoyageController::class, 'reopen']);
    Route::post('voyages/{voyage}/cancel', [VoyageController::class, 'cancel']);

    Route::prefix('voyages/{voyage}')->group(function () {
        Route::apiResource('port-calls', PortCallController::class)->except(['show', 'destroy']);
        Route::apiResource('off-hires', OffHireController::class);
        Route::post('off-hires/{offHire}/agree', [OffHireController::class, 'agree']);
        Route::apiResource('milestones', MilestoneController::class)->only(['index', 'store']);
    });

    Route::apiResource('offshore-projects', OffshoreProjectController::class)->except(['destroy']);
    Route::apiResource('offshore-activities', OffshoreActivityController::class);

    Route::apiResource('bunker-stems', BunkerController::class);
    Route::post('bunker-stems/{bunker}/confirm', [BunkerController::class, 'confirm']);

    Route::apiResource('port-das', PortDaController::class);
    Route::post('port-das/{portDa}/approve', [PortDaController::class, 'approve']);

    Route::apiResource('laytime-calculations', LaytimeController::class);
    Route::post('laytime-calculations/{laytime}/agree', [LaytimeController::class, 'agree']);

    Route::apiResource('captain-reports', CaptainReportController::class);
    Route::post('captain-reports/{report}/verify', [CaptainReportController::class, 'verify']);

    // ===== VOYAGE FINANCIAL ROUTES =====
    Route::prefix('voyages/{voyage}')->group(function () {
        Route::apiResource('revenues', VoyageRevenueController::class);
        Route::post('revenues/{revenue}/confirm', [VoyageRevenueController::class, 'confirm']);
        Route::apiResource('expenses', VoyageExpenseController::class);
        Route::post('expenses/{expense}/confirm', [VoyageExpenseController::class, 'confirm']);
        Route::post('expenses/{expense}/approve', [VoyageExpenseController::class, 'approve']);
        Route::post('expenses/{expense}/pay', [VoyageExpenseController::class, 'pay']);
        Route::get('financial', [VoyageFinancialController::class, 'show']);
    });

    // ===== COMMERCIAL/FINANCE ROUTES =====
    Route::apiResource('invoices', InvoiceController::class);
    Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit']);
    Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve']);
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::get('invoices/{invoice}/print', [InvoiceController::class, 'print']);
    Route::post('invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote']);
    Route::post('invoices/{invoice}/lines', [InvoiceController::class, 'saveLines']);
    Route::get('invoices/{invoice}/lines', [InvoiceController::class, 'getLines']);

    Route::apiResource('payables', PayableController::class);
    Route::post('payables/{payable}/approve', [PayableController::class, 'approve']);

    Route::apiResource('payments', PaymentController::class);
    Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate']);
    Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse']);

    Route::prefix('reports')->group(function () {
        Route::get('aging', [AgingController::class, 'report']);
        Route::get('balancing', [BalancingController::class, 'report']);
        Route::get('balancing/accounts', [BalancingController::class, 'accounts']);
        Route::get('balancing/cash-flow', [BalancingController::class, 'cashFlow']);
        Route::get('chartering-activity', [ReportController::class, 'chartering']);
        Route::get('contract-status', [ReportController::class, 'contracts']);
    });

    Route::get('dashboard', [DashboardController::class, 'index']);

    // ===== AIS ROUTES =====
    Route::prefix('ais')->group(function () {
        Route::apiResource('positions', AisController::class)->only(['index', 'store']);
        Route::post('positions/manual', [AisController::class, 'storeManual']);
        Route::get('fleet', [AisController::class, 'fleet']);
        Route::get('status', [AisController::class, 'status']);
        Route::get('vessels/{vessel}/positions', [AisController::class, 'vesselPositions']);
        Route::get('vessels/{vessel}/track', [AisController::class, 'track']);
    });

    // ===== DOCUMENT ROUTES =====
    Route::apiResource('documents', DocumentController::class)->only(['index', 'store', 'show']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);

    // ===== AUTH ROUTES =====
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->withoutMiddleware('auth:sanctum');
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->withoutMiddleware('auth:sanctum');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->withoutMiddleware('auth:sanctum');
        Route::post('change-password', [AuthController::class, 'changePassword']);
        Route::get('me', [ProfileController::class, 'show']);
        Route::put('me', [ProfileController::class, 'update']);
    });

    // ===== NOTIFICATION ROUTES =====
    Route::apiResource('notifications', NotificationController::class)->only(['index']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
});
