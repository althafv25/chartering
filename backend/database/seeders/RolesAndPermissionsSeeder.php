<?php

namespace Database\Seeders;

use App\Enums\Permission as P;
use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = collect(P::cases())->map(fn (P $p) => ['name' => $p->value])->all();
        foreach ($perms as $perm) {
            Permission::query()->firstOrCreate(['name' => $perm['name']]);
        }

        $read = [
            P::DashboardView, P::DocumentsView, P::AisView, P::ReportsView, P::StatisticsView,
            P::VesselsView, P::VesselStatusView, P::CompaniesView, P::PortsView, P::DistancesView,
            P::MastersView, P::CurrenciesView, P::ExchangeRatesView,
            P::FixturesView, P::ContractsView, P::VoyagesView, P::CaptainReportsView,
            P::OffshoreProjectsView, P::OffshoreActivitiesView, P::BunkersView, P::PortDaView, P::LaytimeView,
        ];
        $charteringRead = [P::EnquiriesView, P::EstimationsView, P::OffersView];
        $financialRead = [P::InvoicesView, P::PayablesView, P::PaymentsView, P::BalancingView, P::RevenuesView, P::ExpensesView];
        $addressBook = [P::CompaniesCreate, P::CompaniesUpdate, P::CompaniesDelete];
        $charteringWork = [
            P::EnquiriesCreate, P::EnquiriesUpdate, P::EnquiriesDelete,
            P::EstimationsCreate, P::EstimationsUpdate, P::EstimationsSubmit, P::EstimationsClone,
            P::OffersCreate, P::OffersUpdate, P::OffersSend, P::OffersAccept, P::OffersReject,
            P::FixturesCreate, P::FixturesUpdate, P::FixturesSubmit, P::FixturesCancel,
            P::ContractsCreate, P::ContractsUpdate, P::ContractsSubmit, P::ContractsActivate, P::ContractsCancel, P::ContractsAmend,
        ];
        $operationsWork = [
            P::VesselsUpdate, P::VesselStatusUpdate, P::PortsCreate, P::PortsUpdate, P::PortsDelete, P::DistancesOverride, P::MastersUpdate,
            P::VoyagesCreate, P::VoyagesUpdate, P::VoyagesComplete, P::VoyagesCancel,
            P::PortCallsManage, P::MilestonesManage, P::OffHireManage, P::OffHireAgree,
            P::CaptainReportsCreate, P::CaptainReportsUpdate, P::CaptainReportsVerify,
            P::OffshoreProjectsManage, P::OffshoreActivitiesCreate, P::OffshoreActivitiesUpdate, P::OffshoreActivitiesVerify,
            P::BunkersManage, P::AisManualPosition, P::DocumentsUpload,
        ];

        foreach (UserRole::cases() as $role) {
            $r = Role::query()->firstOrCreate(['name' => $role->value]);
            $permissions = match ($role) {
                UserRole::SuperAdmin => P::cases(),
                UserRole::Management => [
                    ...$charteringRead, ...$financialRead,
                    P::UsersView, P::RolesView, P::SettingsView, P::AuditLogsView,
                    P::FinancialsView, P::CompaniesBankView, P::ContractsRatesView, P::ReportsExport,
                    P::EstimationsApprove, P::EstimationsReject, P::FixturesApprove, P::ContractsApprove,
                    P::InvoicesApprove,
                ],
                UserRole::Chartering => [
                    ...$charteringRead, ...$charteringWork, ...$addressBook,
                    P::FinancialsView, P::ContractsRatesView, P::ReportsExport, P::DocumentsUpload,
                ],
                UserRole::Operations => [
                    ...$charteringRead, ...$operationsWork, ...$addressBook,
                    P::VoyagesFinalize, P::VoyagesReopen, P::ContractsRatesView, P::ReportsExport, P::InvoicesView,
                    P::PortDaView, P::PortDaCreate, P::PortDaUpdate, P::PortDaApprove,
                    P::LaytimeView, P::LaytimeCreate, P::LaytimeUpdate, P::LaytimeAgree,
                ],
                UserRole::Commercial => [
                    ...$charteringRead, ...$charteringWork, ...$financialRead, ...$addressBook,
                    P::FinancialsView, P::ContractsRatesView, P::ContractsApprove, P::ReportsExport,
                    P::PortDaApprove, P::LaytimeAgree,
                    P::InvoicesView, P::InvoicesCreate, P::InvoicesUpdate, P::InvoicesSubmit, P::InvoicesIssue, P::InvoicesPrint,
                    P::PayablesView, P::PayablesCreate, P::PayablesApprove,
                    P::PaymentsView, P::PaymentsCreate, P::PaymentsAllocate,
                    P::RevenuesCreate, P::RevenuesUpdate, P::ExpensesCreate, P::ExpensesUpdate, P::ExpensesApprove,
                    P::DocumentsUpload,
                ],
                UserRole::Finance => [
                    ...$charteringRead, ...$financialRead, ...$addressBook,
                    P::FinancialsView, P::ContractsRatesView, P::CompaniesBankView, P::AuditLogsView,
                    P::InvoicesCreate, P::InvoicesUpdate, P::InvoicesSubmit, P::InvoicesApprove, P::InvoicesIssue, P::InvoicesCancel, P::InvoicesPrint, P::InvoicesExport,
                    P::PayablesView, P::PayablesCreate, P::PayablesApprove,
                    P::PaymentsView, P::PaymentsCreate, P::PaymentsAllocate, P::PaymentsReverse,
                    P::ReportsExport, P::RevenuesCreate, P::RevenuesUpdate, P::ExpensesCreate, P::ExpensesUpdate, P::ExpensesApprove,
                    P::SettingsView, P::CurrenciesUpdate, P::ExchangeRatesManage, P::DocumentsUpload,
                ],
                UserRole::Accounts => [
                    ...$financialRead, ...$addressBook,
                    P::FinancialsView, P::ContractsRatesView, P::CompaniesBankView, P::ExchangeRatesManage,
                    P::InvoicesCreate, P::InvoicesUpdate, P::InvoicesSubmit, P::InvoicesPrint,
                    P::PayablesCreate, P::PayablesApprove, P::PaymentsCreate, P::PaymentsAllocate,
                    P::RevenuesCreate, P::RevenuesUpdate, P::ExpensesCreate, P::ExpensesUpdate,
                    P::ReportsExport, P::DocumentsUpload,
                ],
                UserRole::MarineOperations => [
                    ...$charteringRead, ...$operationsWork,
                    P::VesselsCreate, P::VesselsDelete, P::CiiView, P::CiiManage, P::ReportsExport,
                ],
                UserRole::ReadOnly => [...$charteringRead, ...$financialRead],
            };
            $r->syncPermissions([...$read, ...$permissions]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
