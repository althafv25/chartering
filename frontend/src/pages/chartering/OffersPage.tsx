import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Card, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { offersApi } from '../../api/chartering';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { RATE_BASES, labelOf } from '../../constants/chartering';
import { useDebounce } from '../../hooks/useDebounce';
import { humanize } from '../../utils/format';
import { groupDigits } from '../../utils/decimal';
import type { Offer } from '../../types/chartering';

export default function OffersPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined, status: status || undefined };
  const list = useQuery({ queryKey: ['offers', q], queryFn: () => offersApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<Offer>[] = [
    { key: 'no', header: 'Offer', render: (o) => <Typography fontWeight={600} fontSize={14}>{o.offer_number}</Typography> },
    { key: 'enq', header: 'Enquiry', render: (o) => o.enquiry?.enquiry_number },
    { key: 'cp', header: 'Charterer', hideBelow: 'md', render: (o) => o.enquiry?.charterer ?? '—' },
    { key: 'vessel', header: 'Vessel', render: (o) => o.vessel?.name },
    { key: 'rev', header: 'Latest', render: (o) => (o.latest_revision ? <Stack direction="row" spacing={1} alignItems="center"><span>Rev {o.latest_revision.revision_no}</span><StatusChip status={o.latest_revision.status} /></Stack> : '—') },
    { key: 'rate', header: 'Rate', align: 'right', render: (o) => (o.latest_revision ? `${groupDigits(o.latest_revision.rate)} ${o.latest_revision.currency} ${labelOf(RATE_BASES, o.latest_revision.rate_basis)}` : '—') },
    { key: 'status', header: 'Offer', render: (o) => <StatusChip status={o.status} /> },
  ];

  return (
    <>
      <PageHeader title="Offers" subtitle="Negotiations with immutable revision history" breadcrumbs={[{ label: 'Chartering' }, { label: 'Offers' }]} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search offer, enquiry, vessel…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All</MenuItem>{['open', 'accepted', 'withdrawn'].map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(o) => o.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
            onRowClick={(o) => navigate(`/chartering/offers/${o.id}`)} emptyTitle="No offers" emptyDescription="Offers are created from an approved or draft estimation scenario." />
        )}
      </Card>
    </>
  );
}

