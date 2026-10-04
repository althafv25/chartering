import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Box, Card, FormControlLabel, MenuItem, Switch, TextField, Typography } from '@mui/material';
import { voyagesApi } from '../../api/operations';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { OPERATIONAL_STATUSES } from '../../constants/operations';
import { useDebounce } from '../../hooks/useDebounce';
import { formatDate, formatDateTime, humanize } from '../../utils/format';
import type { OpsVoyage } from '../../types/operations';

export default function VoyagesPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [open, setOpen] = useState(true);
  const [page, setPage] = useState(1);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined, operation_type: type || undefined, open: open && !status ? 1 : undefined };
  const list = useQuery({ queryKey: ['voyages', q], queryFn: () => voyagesApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<OpsVoyage>[] = [
    { key: 'no', header: 'Voyage', render: (v) => <Box><Typography fontWeight={600} fontSize={14}>{v.voyage_number}</Typography><Typography variant="body2" color="text.secondary">{v.vessel?.name}</Typography></Box> },
    { key: 'type', header: 'Operation', render: (v) => humanize(v.operation_type) },
    { key: 'charterer', header: 'Charterer', hideBelow: 'md', render: (v) => v.charterer?.legal_name ?? '—' },
    { key: 'next', header: 'Next call', hideBelow: 'md', render: (v) => (v.next_port_call ? `${v.next_port_call.label}${v.next_port_call.eta ? ` · ETA ${formatDateTime(v.next_port_call.eta)} UTC` : ''}` : '—') },
    { key: 'commenced', header: 'Commenced', hideBelow: 'lg', render: (v) => formatDate(v.commenced_at) },
    { key: 'src', header: 'From', hideBelow: 'lg', render: (v) => (v.conversion_type === 'direct_estimation' ? 'Direct estimation' : 'Fixture') },
    { key: 'status', header: 'Status', render: (v) => <StatusChip status={v.status} /> },
  ];

  return (
    <>
      <PageHeader title="Voyages" subtitle="Voyages and offshore operations — itinerary, milestones, off-hire, reports and estimate vs actual" breadcrumbs={[{ label: 'Operations' }, { label: 'Voyages' }]} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search voyage number or vessel…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 190 } }}>
            <MenuItem value="">All</MenuItem>{OPERATIONAL_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <TextField select label="Operation" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">All</MenuItem>{['voyage', 'time_charter', 'offshore'].map((t) => <MenuItem key={t} value={t}>{humanize(t)}</MenuItem>)}
          </TextField>
          <FormControlLabel control={<Switch checked={open} disabled={!!status} onChange={(e) => { setOpen(e.target.checked); setPage(1); }} />} label="Open only" />
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(v) => v.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(v) => navigate(`/operations/voyages/${v.id}`)} emptyTitle="No voyages" emptyDescription="Voyages are created from approved fixtures or approved estimations." />
        )}
      </Card>
    </>
  );
}
