import { lazy, Suspense, type ReactNode } from 'react';
import { createBrowserRouter } from 'react-router-dom';
import AppLayout from './layouts/AppLayout';
import AuthLayout from './layouts/AuthLayout';
import { GuestOnly, RequireAuth, RequirePermission } from './auth/guards';
import { SectionLoader } from './components/Feedback';
import { P } from './constants/permissions';
import { env } from './config/env';

const LoginPage = lazy(() => import('./pages/auth/LoginPage'));
const ForgotPasswordPage = lazy(() => import('./pages/auth/ForgotPasswordPage'));
const ResetPasswordPage = lazy(() => import('./pages/auth/ResetPasswordPage'));
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const ProfilePage = lazy(() => import('./pages/account/ProfilePage'));
const NotificationsPage = lazy(() => import('./pages/account/NotificationsPage'));
const UsersPage = lazy(() => import('./pages/admin/UsersPage'));
const RolesPage = lazy(() => import('./pages/admin/RolesPage'));
const AuditLogsPage = lazy(() => import('./pages/admin/AuditLogsPage'));
const SettingsPage = lazy(() => import('./pages/admin/SettingsPage'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));
const VesselsPage = lazy(() => import('./pages/fleet/VesselsPage'));
const VesselFormPage = lazy(() => import('./pages/fleet/VesselFormPage'));
const VesselDetailPage = lazy(() => import('./pages/fleet/VesselDetailPage'));
const VesselStatusBoardPage = lazy(() => import('./pages/fleet/VesselStatusBoardPage'));
const CompaniesPage = lazy(() => import('./pages/masters/CompaniesPage'));
const CompanyDetailPage = lazy(() => import('./pages/masters/CompanyDetailPage'));
const PortsPage = lazy(() => import('./pages/masters/PortsPage'));
const PortDetailPage = lazy(() => import('./pages/masters/PortDetailPage'));
const DistancesPage = lazy(() => import('./pages/masters/DistancesPage'));
const ReferenceDataPage = lazy(() => import('./pages/masters/ReferenceDataPage'));
const CurrenciesPage = lazy(() => import('./pages/masters/CurrenciesPage'));
const EnquiriesPage = lazy(() => import('./pages/chartering/EnquiriesPage'));
const EnquiryDetailPage = lazy(() => import('./pages/chartering/EnquiryDetailPage'));
const EstimationsPage = lazy(() => import('./pages/chartering/EstimationsPage'));
const EstimationDetailPage = lazy(() => import('./pages/chartering/EstimationDetailPage'));
const OffersPage = lazy(() => import('./pages/chartering/OffersPage'));
const OfferDetailPage = lazy(() => import('./pages/chartering/OfferDetailPage'));
const FixturesPage = lazy(() => import('./pages/chartering/FixturesPage'));
const FixtureDetailPage = lazy(() => import('./pages/chartering/FixtureDetailPage'));
const ContractsPage = lazy(() => import('./pages/contracts/ContractsPage'));
const ContractDetailPage = lazy(() => import('./pages/contracts/ContractDetailPage'));
const VoyagesPage = lazy(() => import('./pages/operations/VoyagesPage'));
const VoyageDetailPage = lazy(() => import('./pages/operations/VoyageDetailPage'));
const CaptainReportsPage = lazy(() => import('./pages/operations/CaptainReportsPage'));
const OffshoreActivitiesPage = lazy(() => import('./pages/operations/OffshoreActivitiesPage'));
const OffshoreProjectsPage = lazy(() => import('./pages/operations/OffshoreProjectsPage'));
const BunkersPage = lazy(() => import('./pages/operations/BunkersPage'));
const LedgerPage = lazy(() => import('./pages/commercial/LedgerPage'));
const InvoicesPage = lazy(() => import('./pages/commercial/InvoicesPage'));
const InvoiceDetailPage = lazy(() => import('./pages/commercial/InvoiceDetailPage'));
const PaymentsPage = lazy(() => import('./pages/commercial/PaymentsPage'));
const PaymentDetailPage = lazy(() => import('./pages/commercial/PaymentDetailPage'));
const PayablesPage = lazy(() => import('./pages/commercial/PayablesPage'));
const PayableDetailPage = lazy(() => import('./pages/commercial/PayableDetailPage'));
const AgingReportPage = lazy(() => import('./pages/commercial/AgingReportPage'));
const FleetMapPage = lazy(() => import('./pages/fleet/FleetMapPage'));
const PortDasPage = lazy(() => import('./pages/operations/PortDasPage'));
const PortDaDetailPage = lazy(() => import('./pages/operations/PortDaDetailPage'));
const LaytimePage = lazy(() => import('./pages/operations/LaytimePage'));
const LaytimeDetailPage = lazy(() => import('./pages/operations/LaytimeDetailPage'));
const DocumentsRegisterPage = lazy(() => import('./pages/documents/DocumentsRegisterPage'));
const ReportsPage = lazy(() => import('./pages/reports/ReportsPage'));
const BalancingPage = lazy(() => import('./pages/commercial/BalancingPage'));

const page = (node: ReactNode) => <Suspense fallback={<SectionLoader />}>{node}</Suspense>;
const guarded = (permission: string, node: ReactNode) => <RequirePermission permission={permission}>{page(node)}</RequirePermission>;

export const router = createBrowserRouter([
  {
    element: <GuestOnly />,
    children: [{
      element: <AuthLayout />,
      children: [
        { path: '/login', element: page(<LoginPage />) },
        { path: '/forgot-password', element: page(<ForgotPasswordPage />) },
      ],
    }],
  },
  // Reset is reachable signed-in or not (link from email).
  { element: <AuthLayout />, children: [{ path: '/reset-password', element: page(<ResetPasswordPage />) }] },
  {
    element: <RequireAuth />,
    children: [{
      element: <AppLayout />,
      children: [
        { index: true, element: guarded(P.DashboardView, <DashboardPage />) },
        { path: 'profile', element: page(<ProfilePage />) },
        { path: 'notifications', element: page(<NotificationsPage />) },
        { path: 'chartering/enquiries', element: guarded(P.EnquiriesView, <EnquiriesPage />) },
        { path: 'chartering/enquiries/:id', element: guarded(P.EnquiriesView, <EnquiryDetailPage />) },
        { path: 'chartering/estimations', element: guarded(P.EstimationsView, <EstimationsPage />) },
        { path: 'chartering/estimations/:id', element: guarded(P.EstimationsView, <EstimationDetailPage />) },
        { path: 'chartering/offers', element: guarded(P.OffersView, <OffersPage />) },
        { path: 'chartering/offers/:id', element: guarded(P.OffersView, <OfferDetailPage />) },
        { path: 'chartering/fixtures', element: guarded(P.FixturesView, <FixturesPage />) },
        { path: 'chartering/fixtures/:id', element: guarded(P.FixturesView, <FixtureDetailPage />) },
        { path: 'contracts', element: guarded(P.ContractsView, <ContractsPage />) },
        { path: 'contracts/:id', element: guarded(P.ContractsView, <ContractDetailPage />) },
        { path: 'operations/voyages', element: guarded(P.VoyagesView, <VoyagesPage />) },
        { path: 'operations/voyages/:id', element: guarded(P.VoyagesView, <VoyageDetailPage />) },
        { path: 'operations/captain-reports', element: guarded(P.CaptainReportsView, <CaptainReportsPage />) },
        { path: 'operations/offshore-activities', element: guarded(P.OffshoreActivitiesView, <OffshoreActivitiesPage />) },
        { path: 'operations/offshore-projects', element: guarded(P.OffshoreProjectsView, <OffshoreProjectsPage />) },
        { path: 'operations/port-da', element: guarded(P.PortDaView, <PortDasPage />) },
        { path: 'operations/port-da/:id', element: guarded(P.PortDaView, <PortDaDetailPage />) },
        { path: 'operations/laytime', element: guarded(P.LaytimeView, <LaytimePage />) },
        { path: 'operations/laytime/:id', element: guarded(P.LaytimeView, <LaytimeDetailPage />) },
        { path: 'operations/bunkers', element: guarded(P.BunkersView, <BunkersPage />) },
        { path: 'commercial/ledger', element: guarded(P.RevenuesView, <LedgerPage />) },
        { path: 'commercial/invoices', element: guarded(P.InvoicesView, <InvoicesPage />) },
        { path: 'commercial/invoices/:id', element: guarded(P.InvoicesView, <InvoiceDetailPage />) },
        { path: 'commercial/payments', element: guarded(P.PaymentsView, <PaymentsPage />) },
        { path: 'commercial/payments/:id', element: guarded(P.PaymentsView, <PaymentDetailPage />) },
        { path: 'commercial/payables', element: guarded(P.PayablesView, <PayablesPage />) },
        { path: 'commercial/payables/:id', element: guarded(P.PayablesView, <PayableDetailPage />) },
        { path: 'commercial/aging', element: guarded(P.InvoicesView, <AgingReportPage />) },
        { path: 'documents', element: guarded(P.DocumentsView, <DocumentsRegisterPage />) },
        { path: 'reports', element: guarded(P.ReportsView, <ReportsPage />) },
        { path: 'reports/:slug', element: guarded(P.ReportsView, <ReportsPage />) },
        { path: 'commercial/balancing', element: guarded(P.BalancingView, <BalancingPage />) },
        { path: 'fleet/vessels', element: guarded(P.VesselsView, <VesselsPage />) },
        { path: 'fleet/vessels/new', element: guarded(P.VesselsCreate, <VesselFormPage />) },
        { path: 'fleet/vessels/:id', element: guarded(P.VesselsView, <VesselDetailPage />) },
        { path: 'fleet/vessels/:id/edit', element: guarded(P.VesselsUpdate, <VesselFormPage />) },
        { path: 'fleet/map', element: guarded(P.AisView, <FleetMapPage />) },
        { path: 'fleet/status', element: guarded(P.VesselStatusView, <VesselStatusBoardPage />) },
        { path: 'masters/companies', element: guarded(P.CompaniesView, <CompaniesPage />) },
        { path: 'masters/companies/:id', element: guarded(P.CompaniesView, <CompanyDetailPage />) },
        { path: 'masters/ports', element: guarded(P.PortsView, <PortsPage />) },
        { path: 'masters/ports/:id', element: guarded(P.PortsView, <PortDetailPage />) },
        { path: 'masters/distances', element: guarded(P.DistancesView, <DistancesPage />) },
        { path: 'masters/reference', element: guarded(P.MastersView, <ReferenceDataPage />) },
        { path: 'masters/currencies', element: guarded(P.CurrenciesView, <CurrenciesPage />) },
        { path: 'admin/users', element: guarded(P.UsersView, <UsersPage />) },
        { path: 'admin/roles', element: guarded(P.RolesView, <RolesPage />) },
        { path: 'admin/audit-logs', element: guarded(P.AuditLogsView, <AuditLogsPage />) },
        { path: 'admin/settings', element: guarded(P.SettingsView, <SettingsPage />) },
        { path: '*', element: page(<NotFoundPage />) },
      ],
    }],
  },
], { basename: env.baseUrl.replace(/\/$/, '') || '/' });
