<?php

namespace App\Enums;

/**
 * System permissions, format `module.action`.
 *
 * Single source of truth: seeded by RolesAndPermissionsSeeder and mirrored in
 * frontend/src/constants/permissions.ts. Later phases append their modules.
 */
enum Permission: string
{
    // Dashboard
    case DashboardView = 'dashboard.view';
    case FinancialsView = 'commercial.financials.view';

    // AIS (advisory positions, AIS-01)
    case AisView = 'ais.view';
    case AisManualPosition = 'ais.manual-position';

    // Reports & statistics
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case StatisticsView = 'statistics.view';

    // Users
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';

    // Roles & permissions
    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';

    // Settings
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    // Audit
    case AuditLogsView = 'audit-logs.view';

    // Documents (parent-entity permission is also required)
    case DocumentsView = 'documents.view';
    case DocumentsUpload = 'documents.upload';
    case DocumentsDelete = 'documents.delete';

    // Vessels & status
    case VesselsView = 'vessels.view';
    case VesselsCreate = 'vessels.create';
    case VesselsUpdate = 'vessels.update';
    case VesselsDelete = 'vessels.delete';
    case VesselStatusView = 'vessel-status.view';
    case VesselStatusUpdate = 'vessel-status.update';

    // Address book
    case CompaniesView = 'companies.view';
    case CompaniesCreate = 'companies.create';
    case CompaniesUpdate = 'companies.update';
    case CompaniesDelete = 'companies.delete';
    case CompaniesBankView = 'companies.bank-view';

    // Ports, offshore locations, distances
    case PortsView = 'ports.view';
    case PortsCreate = 'ports.create';
    case PortsUpdate = 'ports.update';
    case PortsDelete = 'ports.delete';
    case DistancesView = 'distances.view';
    case DistancesOverride = 'distances.override';

    // Reference data (fuel/cargo/activity/milestone/vessel types, categories)
    case MastersView = 'masters.view';
    case MastersUpdate = 'masters.update';

    // Currencies & exchange rates
    case CurrenciesView = 'currencies.view';
    case CurrenciesUpdate = 'currencies.update';
    case ExchangeRatesView = 'exchange-rates.view';
    case ExchangeRatesManage = 'exchange-rates.manage';

    // Chartering — enquiries
    case EnquiriesView = 'chartering.enquiries.view';
    case EnquiriesCreate = 'chartering.enquiries.create';
    case EnquiriesUpdate = 'chartering.enquiries.update';
    case EnquiriesDelete = 'chartering.enquiries.delete';

    // Chartering — estimations
    case EstimationsView = 'chartering.estimations.view';
    case EstimationsCreate = 'chartering.estimations.create';
    case EstimationsUpdate = 'chartering.estimations.update';
    case EstimationsSubmit = 'chartering.estimations.submit';
    case EstimationsApprove = 'chartering.estimations.approve';
    case EstimationsReject = 'chartering.estimations.reject';
    case EstimationsClone = 'chartering.estimations.clone';

    // Chartering — offers
    case OffersView = 'chartering.offers.view';
    case OffersCreate = 'chartering.offers.create';
    case OffersUpdate = 'chartering.offers.update';
    case OffersSend = 'chartering.offers.send';
    case OffersAccept = 'chartering.offers.accept';
    case OffersReject = 'chartering.offers.reject';

    // Chartering — fixtures
    case FixturesView = 'chartering.fixtures.view';
    case FixturesCreate = 'chartering.fixtures.create';
    case FixturesUpdate = 'chartering.fixtures.update';
    case FixturesSubmit = 'chartering.fixtures.submit';
    case FixturesApprove = 'chartering.fixtures.approve';
    case FixturesCancel = 'chartering.fixtures.cancel';

    // Contracts
    case ContractsView = 'contracts.view';
    case ContractsCreate = 'contracts.create';
    case ContractsUpdate = 'contracts.update';
    case ContractsSubmit = 'contracts.submit';
    case ContractsApprove = 'contracts.approve';
    case ContractsActivate = 'contracts.activate';
    case ContractsCancel = 'contracts.cancel';
    case ContractsAmend = 'contracts.amend';
    case ContractsRatesView = 'contracts.rates.view';

    // Operations — voyages (phase 4: creation by conversion; lifecycle in phase 6)
    case VoyagesView = 'operations.voyages.view';
    case VoyagesCreate = 'operations.voyages.create';
    case VoyagesUpdate = 'operations.voyages.update';
    case VoyagesComplete = 'operations.voyages.complete';
    case VoyagesFinalize = 'operations.voyages.finalize';
    case VoyagesReopen = 'operations.voyages.reopen';
    case VoyagesCancel = 'operations.voyages.cancel';
    case PortCallsManage = 'operations.port-calls.manage';
    case MilestonesManage = 'operations.milestones.manage';
    case OffHireManage = 'operations.off-hire.manage';
    case OffHireAgree = 'operations.off-hire.agree';
    case CaptainReportsView = 'operations.captain-reports.view';
    case CaptainReportsCreate = 'operations.captain-reports.create';
    case CaptainReportsUpdate = 'operations.captain-reports.update';
    case CaptainReportsVerify = 'operations.captain-reports.verify';
    case OffshoreProjectsView = 'operations.offshore-projects.view';
    case OffshoreProjectsManage = 'operations.offshore-projects.manage';
    case OffshoreActivitiesView = 'operations.offshore-activities.view';
    case OffshoreActivitiesCreate = 'operations.offshore-activities.create';
    case OffshoreActivitiesUpdate = 'operations.offshore-activities.update';
    case OffshoreActivitiesVerify = 'operations.offshore-activities.verify';
    case BunkersView = 'operations.bunkers.view';
    case BunkersManage = 'operations.bunkers.manage';
    case PortDaView = 'operations.port-da.view';
    case PortDaCreate = 'operations.port-da.create';
    case PortDaUpdate = 'operations.port-da.update';
    case PortDaApprove = 'operations.port-da.approve';
    case LaytimeView = 'operations.laytime.view';
    case LaytimeCreate = 'operations.laytime.create';
    case LaytimeUpdate = 'operations.laytime.update';
    case LaytimeAgree = 'operations.laytime.agree';

    // Finance — voyage revenues & expenses
    case RevenuesView = 'revenues.view';
    case RevenuesCreate = 'revenues.create';
    case RevenuesUpdate = 'revenues.update';
    case ExpensesView = 'expenses.view';
    case ExpensesCreate = 'expenses.create';
    case ExpensesUpdate = 'expenses.update';
    case ExpensesApprove = 'expenses.approve';

    // Finance — invoices
    case InvoicesView = 'invoices.view';
    case InvoicesCreate = 'invoices.create';
    case InvoicesUpdate = 'invoices.update';
    case InvoicesSubmit = 'invoices.submit';
    case InvoicesApprove = 'invoices.approve';
    case InvoicesIssue = 'invoices.issue';
    case InvoicesCancel = 'invoices.cancel';
    case InvoicesPrint = 'invoices.print';
    case InvoicesExport = 'invoices.export';

    // Finance — payments & payables
    case PaymentsView = 'payments.view';
    case PaymentsCreate = 'payments.create';
    case PaymentsAllocate = 'payments.allocate';
    case PaymentsReverse = 'payments.reverse';
    case PayablesView = 'payables.view';
    case PayablesCreate = 'payables.create';
    case PayablesApprove = 'payables.approve';
    case BalancingView = 'balancing.view';

    case CiiView = 'cii.view';
    case CiiManage = 'cii.manage';

    /** Everything before the last segment, e.g. "chartering.offers". */
    public function module(): string
    {
        return substr($this->value, 0, (int) strrpos($this->value, '.'));
    }

    public function action(): string
    {
        return substr($this->value, (int) strrpos($this->value, '.') + 1);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
