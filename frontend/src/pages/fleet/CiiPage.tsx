import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, Card, Grid, MenuItem, TextField } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import AssessmentOutlined from '@mui/icons-material/AssessmentOutlined';
import WarningAmberOutlined from '@mui/icons-material/WarningAmberOutlined';
import DirectionsBoatOutlined from '@mui/icons-material/DirectionsBoatOutlined';
import SpeedOutlined from '@mui/icons-material/SpeedOutlined';
import { PageHeader } from '../../components/PageHeader';
import { StatCard } from '../../components/StatCard';
import { StatusChip } from '../../components/StatusChip';
import { DataTable, type Column } from '../../components/DataTable';
import { ErrorState } from '../../components/Feedback';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { ciiApi } from '../../api/cii';
import { vesselsApi } from '../../api/masters';
import { trimZeros } from '../../utils/decimal';
import { OperationsFilterBar } from '../operations/OperationsFilterBar';
import { CiiVesselYearDialog } from './CiiVesselYearDialog';
import type { CiiVesselYear } from '../../types/cii';

const CURRENT_YEAR = new Date().getFullYear();
const YEARS = Array.from({ length: 6 }, (_, i) => CURRENT_YEAR - 4 + i);

export default function CiiPage() {
  const [year, setYear] = useState(CURRENT_YEAR);
  const [vessel, setVessel] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [editing, setEditing] = useState<CiiVesselYear | null | undefined>(undefined);

  const vessels = useQuery({ queryKey: ['vessels', 'active-all'], queryFn: () => vesselsApi.list({ per_page: 100, status: 'active' }) });
  const summary = useQuery({ queryKey: ['cii', 'fleet-summary', year], queryFn: () => ciiApi.fleetSummary(year).then((r) => r.data) });
  const list = useQuery({
    queryKey: ['cii', 'vessel-years', { year, vessel, status, page, perPage }],
    queryFn: () => ciiApi.list({ reporting_year: year, vessel_id: vessel || undefined, status: status || undefined, page, per_page: perPage }),
  });

  const columns: Column<CiiVesselYear>[] = [
    { key: 'vessel', header: 'Vessel', render: (r) => r.vessel?.name ?? `#${r.vessel_id}` },
    { key: 'year', header: 'Year', render: (r) => r.reporting_year, align: 'center', width: 80 },
    { key: 'capacity', header: 'Capacity', render: (r) => `${trimZeros(r.capacity_value)} ${r.capacity_metric}`, hideBelow: 'sm' },
    { key: 'distance', header: 'Distance (nm)', render: (r) => trimZeros(r.distance_sailed_nm), align: 'right', hideBelow: 'md' },
    { key: 'attained', header: 'Attained CII', render: (r) => r.attained_cii ?? '—', align: 'right' },
    { key: 'rating', header: 'Rating', render: (r) => (r.rating ? <StatusChip status={r.rating} label={`Rating ${r.rating}`} /> : '—'), align: 'center' },
    { key: 'status', header: 'Status', render: (r) => <StatusChip status={r.status} /> },
    { key: 'formula', header: 'Formula set', render: (r) => r.formula_set?.code ?? '—', hideBelow: 'lg' },
  ];

  if (summary.isError) return <ErrorState error={summary.error} onRetry={() => summary.refetch()} />;

  return (
    <>
      <PageHeader
        title="CII — Carbon Intensity Indicator"
        subtitle="IMO MARPOL Annex VI attained CII, reference line and rating per vessel and reporting year."
        breadcrumbs={[{ label: 'Fleet' }, { label: 'CII' }]}
        actions={<Can permission={P.CiiManage}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing(null)}>New record</Button></Can>}
      />

      <Grid container spacing={2} sx={{ mb: 3 }}>
        <Grid size={{ xs: 12, sm: 6, md: 3 }}>
          <StatCard label="Vessels rated" value={summary.data?.vessel_count ?? '—'} icon={<DirectionsBoatOutlined />} hint={`Reporting year ${year}`} />
        </Grid>
        <Grid size={{ xs: 12, sm: 6, md: 3 }}>
          <StatCard label="Fleet average ratio" value={summary.data?.fleet_average_ratio ?? '—'} icon={<SpeedOutlined />} hint="Attained ÷ required CII" tone="secondary" />
        </Grid>
        <Grid size={{ xs: 12, sm: 6, md: 3 }}>
          <StatCard label="At risk (D/E)" value={summary.data?.at_risk_count ?? '—'} icon={<WarningAmberOutlined />} tone={summary.data?.at_risk_count ? 'error' : 'success'}
            hint="Rating D or E" />
        </Grid>
        <Grid size={{ xs: 12, sm: 6, md: 3 }}>
          <StatCard label="Rating A/B" value={(summary.data?.by_rating.A ?? 0) + (summary.data?.by_rating.B ?? 0)} icon={<AssessmentOutlined />} tone="success" hint="Best performers" />
        </Grid>
      </Grid>

      <Card>
        <OperationsFilterBar active={!!vessel || !!status} onClear={() => { setVessel(''); setStatus(''); }}>
          <TextField select size="small" label="Reporting year" value={year} onChange={(e) => setYear(Number(e.target.value))}>
            {YEARS.map((y) => <MenuItem key={y} value={y}>{y}</MenuItem>)}
          </TextField>
          <TextField select size="small" label="Vessel" value={vessel} onChange={(e) => setVessel(e.target.value)}>
            <MenuItem value="">All</MenuItem>{(vessels.data?.data ?? []).map((v) => <MenuItem key={v.id} value={String(v.id)}>{v.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <MenuItem value="">All</MenuItem>
            {['draft', 'calculated', 'verified'].map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
          </TextField>
        </OperationsFilterBar>
        <DataTable
          columns={columns}
          rows={list.data?.data ?? []}
          rowKey={(r) => r.id}
          loading={list.isLoading}
          meta={list.data?.meta}
          onPageChange={setPage}
          onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
          onRowClick={(r) => setEditing(r)}
          emptyTitle="No CII records for this year"
          emptyDescription="Create a record for a vessel to calculate its attained CII and rating."
        />
      </Card>

      <CiiVesselYearDialog open={editing !== undefined} onClose={() => setEditing(undefined)} year={editing ?? null} />
    </>
  );
}
