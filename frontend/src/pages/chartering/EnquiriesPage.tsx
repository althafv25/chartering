import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Box, Button, Card, MenuItem, TextField, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { enquiriesApi } from '../../api/chartering';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { BUSINESS_TYPES, ENQUIRY_STATUSES, labelOf } from '../../constants/chartering';
import { useDebounce } from '../../hooks/useDebounce';
import { formatDate, humanize } from '../../utils/format';
import { groupDigits } from '../../utils/decimal';
import type { Enquiry } from '../../types/chartering';
import { EnquiryDialog } from './EnquiryDialog';

export default function EnquiriesPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [dialog, setDialog] = useState(false);
  const debounced = useDebounce(search);

  const q = { page, per_page: perPage, search: debounced || undefined, status: status || undefined, business_type: type || undefined };
  const list = useQuery({ queryKey: ['enquiries', q], queryFn: () => enquiriesApi.list(q), placeholderData: keepPreviousData });

  const route = (e: Enquiry) => (e.ports ?? []).map((p) => p.point?.label.replace(/ \(.*\)$/, '')).join(' → ') || '—';
  const columns: Column<Enquiry>[] = [
    { key: 'no', header: 'Enquiry', render: (e) => <Box><Typography fontWeight={600} fontSize={14}>{e.enquiry_number}</Typography><Typography variant="body2" color="text.secondary">{formatDate(e.received_at)}</Typography></Box> },
    { key: 'type', header: 'Type', render: (e) => labelOf(BUSINESS_TYPES, e.business_type) },
    { key: 'charterer', header: 'Charterer', render: (e) => e.charterer?.legal_name ?? '—' },
    { key: 'cargo', header: 'Cargo / service', hideBelow: 'md', render: (e) => [e.cargo_description, e.quantity ? `${groupDigits(e.quantity)} ${e.quantity_unit ?? ''}` : null].filter(Boolean).join(' · ') || '—' },
    { key: 'route', header: 'Route', hideBelow: 'lg', render: route },
    { key: 'laycan', header: 'Laycan', hideBelow: 'md', render: (e) => (e.laycan_from ? `${formatDate(e.laycan_from)} – ${formatDate(e.laycan_to)}` : '—') },
    { key: 'work', header: 'Est. / offers', hideBelow: 'lg', align: 'right', render: (e) => `${e.estimations_count ?? 0} / ${e.offers_count ?? 0}` },
    { key: 'status', header: 'Status', render: (e) => <StatusChip status={e.status} /> },
  ];

  return (
    <>
      <PageHeader title="Enquiries" subtitle="Chartering requests (RFQ) and their pipeline status" breadcrumbs={[{ label: 'Chartering' }, { label: 'Enquiries' }]}
        actions={<Can permission={P.EnquiriesCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog(true)}>New enquiry</Button></Can>} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search number, cargo, charterer, broker…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All statuses</MenuItem>{ENQUIRY_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
          <TextField select label="Business type" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 200 } }}>
            <MenuItem value="">All types</MenuItem>{BUSINESS_TYPES.map((b) => <MenuItem key={b.value} value={b.value}>{b.label}</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(e) => e.id} loading={list.isFetching} meta={list.data?.meta}
            onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} onRowClick={(e) => navigate(`/chartering/enquiries/${e.id}`)}
            emptyTitle="No enquiries found" />
        )}
      </Card>
      <EnquiryDialog open={dialog} enquiry={null} onClose={() => setDialog(false)} onSaved={(e) => navigate(`/chartering/enquiries/${e.id}`)} />
    </>
  );
}
