<?php

namespace Database\Seeders;

use App\Enums\Permission as P;
use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $perms = collect(P::cases())->map(fn (P $p) => ['name' => $p->value])->all();
        foreach ($perms as $perm) {
            Permission::query()->firstOrCreate(['name' => $perm['name']]);
        }

        foreach (UserRole::cases() as $role) {
            $r = Role::query()->firstOrCreate(['name' => $role->value]);
            match ($role) {
                UserRole::SuperAdmin => $r->syncPermissions(P::cases()),
                UserRole::Management => $r->syncPermissions([
                    P::DashboardView, P::UsersView, P::RolesView, P::SettingsView, P::SettingsUpdate, P::AuditLogsView,
                    P::VesselsView, P::CompaniesView, P::PortsView, P::CurrenciesView, P::ExchangeRatesView,
                    P::InvoicesView, P::PayablesView, P::PaymentsView, P::BalancingView, P::ReportsView, P::StatisticsView,
                    P::DocumentsView, P::AisView,
                ]),
                UserRole::Chartering => $r->syncPermissions([
                    P::EnquiriesView, P::EnquiriesCreate, P::EnquiriesUpdate,
                    P::OffersView, P::OffersCreate, P::OffersUpdate, P::OffersSend,
                    P::FixturesView, P::FixturesCreate, P::FixturesUpdate,
                    P::ContractsView, P::ContractsCreate, P::ContractsUpdate, P::ContractsAmend,
                    P::EstimationsView, P::EstimationsCreate, P::EstimationsUpdate, P::EstimationsSubmit, P::EstimationsApprove,
                    P::VesselsView, P::CompaniesView, P::PortsView, P::MastersView,
                    P::DocumentsView, P::DocumentsUpload,
                ]),
                UserRole::Operations => $r->syncPermissions([
                    P::VoyagesView, P::VoyagesCreate, P::VoyagesUpdate, P::VoyagesComplete, P::VoyagesFinalize,
                    P::PortCallsManage, P::OffHireManage,
                    P::CaptainReportsView, P::CaptainReportsCreate, P::CaptainReportsUpdate, P::CaptainReportsVerify,
                    P::OffshoreProjectsView, P::OffshoreProjectsManage,
                    P::OffshoreActivitiesView, P::OffshoreActivitiesCreate, P::OffshoreActivitiesVerify,
                    P::BunkersView, P::BunkersManage,
                    P::PortDaView, P::PortDaCreate, P::PortDaUpdate, P::PortDaApprove,
                    P::LaytimeView, P::LaytimeCreate, P::LaytimeUpdate, P::LaytimeAgree,
                    P::VesselsView, P::PortsView, P::DocumentsView, P::DocumentsUpload, P::AisView, P::AisManualPosition,
                ]),
                UserRole::Commercial => $r->syncPermissions([
                    P::InvoicesView, P::InvoicesCreate, P::InvoicesUpdate, P::InvoicesSubmit, P::InvoicesIssue, P::InvoicesPrint,
                    P::PayablesView, P::PayablesCreate, P::PayablesApprove,
                    P::PaymentsView, P::PaymentsCreate, P::PaymentsAllocate,
                    P::BalancingView, P::ReportsView, P::StatisticsView,
                    P::RevenuesView, P::RevenuesCreate, P::ExpensesView, P::ExpensesCreate, P::ExpensesApprove,
                    P::CompaniesView, P::DocumentsView, P::DocumentsUpload,
                ]),
                UserRole::Finance => $r->syncPermissions([
                    P::InvoicesView, P::InvoicesCreate, P::InvoicesUpdate, P::InvoicesApprove, P::InvoicesPrint,
                    P::PayablesView, P::PayablesCreate, P::PayablesApprove,
                    P::PaymentsView, P::PaymentsCreate, P::PaymentsAllocate, P::PaymentsReverse,
                    P::BalancingView, P::ReportsView, P::ReportsExport, P::StatisticsView,
                    P::RevenuesView, P::RevenuesCreate, P::ExpensesView, P::ExpensesCreate, P::ExpensesApprove,
                    P::SettingsView, P::CurrenciesView, P::ExchangeRatesView, P::ExchangeRatesManage,
                    P::CompaniesView, P::DocumentsView, P::DocumentsUpload,
                ]),
                UserRole::Accounts => $r->syncPermissions([
                    P::InvoicesView, P::PayablesView, P::PayablesApprove, P::PaymentsView, P::PaymentsCreate,
                    P::BalancingView, P::ReportsView, P::StatisticsView, P::ReportsExport,
                    P::CompaniesView, P::DocumentsView,
                ]),
                UserRole::MarineOperations => $r->syncPermissions([
                    P::VoyagesView, P::VesselsView, P::VesselStatusView, P::VesselStatusUpdate,
                    P::BunkersView, P::CaptainReportsView, P::CaptainReportsCreate, P::CaptainReportsVerify,
                    P::OffshoreActivitiesView, P::CiiView, P::CiiManage,
                    P::SettingsView, P::DocumentsView, P::AisView, P::AisManualPosition,
                ]),
                UserRole::ReadOnly => $r->syncPermissions([
                    P::DashboardView, P::VoyagesView, P::EnquiriesView, P::FixturesView, P::ContractsView, P::EstimationsView,
                    P::InvoicesView, P::PayablesView, P::PaymentsView, P::BalancingView, P::ReportsView, P::StatisticsView,
                    P::RevenuesView, P::ExpensesView, P::VesselsView, P::CompaniesView, P::PortsView,
                    P::OffshoreActivitiesView, P::PortDaView, P::LaytimeView, P::BunkersView, P::CaptainReportsView,
                    P::DocumentsView, P::AisView,
                ]),
            };
        }
    }
}
