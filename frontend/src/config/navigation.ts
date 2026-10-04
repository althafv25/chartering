import type { SvgIconComponent } from '@mui/icons-material';
import DashboardOutlined from '@mui/icons-material/DashboardOutlined';
import HandshakeOutlined from '@mui/icons-material/HandshakeOutlined';
import DescriptionOutlined from '@mui/icons-material/DescriptionOutlined';
import SailingOutlined from '@mui/icons-material/SailingOutlined';
import DirectionsBoatOutlined from '@mui/icons-material/DirectionsBoatOutlined';
import PaidOutlined from '@mui/icons-material/PaidOutlined';
import StorageOutlined from '@mui/icons-material/StorageOutlined';
import AssessmentOutlined from '@mui/icons-material/AssessmentOutlined';
import FolderOutlined from '@mui/icons-material/FolderOutlined';
import AdminPanelSettingsOutlined from '@mui/icons-material/AdminPanelSettingsOutlined';
import { P } from '../constants/permissions';

export interface NavLeaf {
  label: string;
  to: string;
  permission?: string;
  /** Hidden until this optional feature is switched on in Settings (docs/09: AIS). */
  feature?: 'ais';
  /** Phase in which the module is delivered; leaves with a phase are shown disabled. */
  phase?: number;
}

export interface NavSection {
  label: string;
  icon: SvgIconComponent;
  to?: string;
  permission?: string;
  phase?: number;
  children?: NavLeaf[];
}

/** Sidebar structure (docs/02-SYSTEM-ARCHITECTURE.md §B5). */
export const navigation: NavSection[] = [
  { label: 'Dashboard', icon: DashboardOutlined, to: '/', permission: P.DashboardView },
  {
    label: 'Chartering', icon: HandshakeOutlined, children: [
      { label: 'Enquiries', to: '/chartering/enquiries', permission: P.EnquiriesView },
      { label: 'Estimations', to: '/chartering/estimations', permission: P.EstimationsView },
      { label: 'Offers', to: '/chartering/offers', permission: P.OffersView },
      { label: 'Fixtures', to: '/chartering/fixtures', permission: P.FixturesView },
    ],
  },
  { label: 'Contracts', icon: DescriptionOutlined, to: '/contracts', permission: P.ContractsView },
  {
    label: 'Operations', icon: SailingOutlined, children: [
      { label: 'Voyages', to: '/operations/voyages', permission: P.VoyagesView },
      { label: 'Offshore Projects', to: '/operations/offshore-projects', permission: P.OffshoreProjectsView },
      { label: 'Offshore Activities', to: '/operations/offshore-activities', permission: P.OffshoreActivitiesView },
      { label: 'Captain Reports', to: '/operations/captain-reports', permission: P.CaptainReportsView },
      { label: 'Bunkers', to: '/operations/bunkers', permission: P.BunkersView },
      { label: 'Port DA', to: '/operations/port-da', permission: P.PortDaView },
      { label: 'Laytime', to: '/operations/laytime', permission: P.LaytimeView },
    ],
  },
  {
    label: 'Fleet', icon: DirectionsBoatOutlined, children: [
      { label: 'Vessels', to: '/fleet/vessels', permission: P.VesselsView },
      { label: 'Vessel Status', to: '/fleet/status', permission: P.VesselStatusView },
      { label: 'Vessel Performance', to: '/reports/vessel-performance', permission: P.ReportsView },
      { label: 'Fleet Map', to: '/fleet/map', permission: P.AisView, feature: 'ais' },
    ],
  },
  {
    label: 'Commercial', icon: PaidOutlined, children: [
      { label: 'Revenue & Expenses', to: '/commercial/ledger', permission: P.RevenuesView },
      { label: 'Invoices', to: '/commercial/invoices', permission: P.InvoicesView },
      { label: 'Payments', to: '/commercial/payments', permission: P.PaymentsView },
      { label: 'Payables', to: '/commercial/payables', permission: P.PayablesView },
      { label: 'Receivables Aging', to: '/commercial/aging', permission: P.InvoicesView },
      { label: 'Voyage P&L', to: '/reports/voyage-pnl', permission: P.FinancialsView },
      { label: 'Balancing', to: '/commercial/balancing', permission: P.BalancingView },
    ],
  },
  {
    label: 'Masters', icon: StorageOutlined, children: [
      { label: 'Address Book', to: '/masters/companies', permission: P.CompaniesView },
      { label: 'Ports & Locations', to: '/masters/ports', permission: P.PortsView },
      { label: 'Distances', to: '/masters/distances', permission: P.DistancesView },
      { label: 'Reference Data', to: '/masters/reference', permission: P.MastersView },
      { label: 'Currencies & FX', to: '/masters/currencies', permission: P.CurrenciesView },
    ],
  },
  { label: 'Reports', icon: AssessmentOutlined, to: '/reports', permission: P.ReportsView },
  { label: 'Documents', icon: FolderOutlined, to: '/documents', permission: P.DocumentsView },
  {
    label: 'Administration', icon: AdminPanelSettingsOutlined, children: [
      { label: 'Users', to: '/admin/users', permission: P.UsersView },
      { label: 'Roles & Permissions', to: '/admin/roles', permission: P.RolesView },
      { label: 'Audit Logs', to: '/admin/audit-logs', permission: P.AuditLogsView },
      { label: 'Settings', to: '/admin/settings', permission: P.SettingsView },
    ],
  },
];
