import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Box, Button, Card, MenuItem, TextField, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { estimationsApi } from '../../api/chartering';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { ESTIMATION_STATUSES, ESTIMATION_TYPES, labelOf } from '../../constants/chartering';
import { useDebounce } from '../../hooks/useDebounce';
import { formatDate, humanize } from '../../utils/format';
import { money } from '../../utils/decimal';
import type { Estimation } from '../../types/chartering';
import { NewEstimationDialog } from './NewEstimationDialog';

export default function EstimationsPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined, estimation_type: type || undefined };
  const list = useQuery({ queryKey: ['estimations', q], queryFn: () => estimationsApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<Estimation>[] = [
    { key: 'no', header: 'Estimation', render: (e) => <Box><Typography fontWeight={600} fontSize={14}>{e.estimation_number}</Typography><Typography variant="body2" color="text.secondary">{e.title}</Typography></Box> },
    { key: 'type', header: 'Type', render: (e) => labelOf(ESTIMATION_TYPES, e.estimation_type) },
    { key: 'vessel', header: 'Vessel', render: (e) => e.vessel?.name },
    { key: 'enq', header: 'Enquiry', hideBelow: 'md', render: (e) => e.enquiry?.enquiry_number ?? '—' },
    { key: 'sc', header: 'Scenarios', align: 'right', hideBelow: 'lg', render: (e) => e.scenarios_count ?? 0 },
    { key: 'profit', header: 'Selected profit', align: 'right', render: (e) => (e.selected_scenario ? `${e.selected_scenario.code}: ${money(e.selected_scenario.result?.profit, e.currency)}` : '—') },
    { key: 'tce', header: 'TCE/day', align: 'right', hideBelow: 'md', render: (e) => money(e.selected_scenario?.result?.tce_per_day, e.currency) },
    { key: 'date', header: 'Created', hideBelow: 'lg', render: (e) => formatDate(e.created_at) },
    { key: 'status', header: 'Status', render: (e) => <StatusChip status={e.status} /> },
  ];

  return (
    <>
      <PageHeader title="Estimations" subtitle="Voyage, time-charter, offshore day-rate and cargo-relet estimates with scenarios"
        breadcrumbs={[{ label: 'Chartering' }, { label: 'Estimations' }]}
        actions={<Can permission={P.EstimationsCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog(true)}>New estimation</Button></Can>} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search number, title, vessel, enquiry…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All statuses</MenuItem>{ESTIMATION_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <TextField select label="Type" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 200 } }}>
            <MenuItem value="">All types</MenuItem>{ESTIMATION_TYPES.map((t) => <MenuItem key={t.value} value={t.value}>{t.label}</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(e) => e.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(e) => navigate(`/chartering/estimations/${e.id}`)} emptyTitle="No estimations" />
        )}
      </Card>
      <NewEstimationDialog open={dialog} onClose={() => setDialog(false)} onCreated={(e) => navigate(`/chartering/estimations/${e.id}`)} />
    </>
  );
}
