import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Card, Typography } from '@mui/material';
import { fixturesApi } from '../../api/chartering';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { BUSINESS_TYPES, RATE_BASES, labelOf } from '../../constants/chartering';
import { useDebounce } from '../../hooks/useDebounce';
import { formatDate } from '../../utils/format';
import { groupDigits } from '../../utils/decimal';
import type { Fixture } from '../../types/chartering';

export default function FixturesPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const debounced = useDebounce(search);
  const q = { page, per_page: 15, search: debounced || undefined };
  const list = useQuery({ queryKey: ['fixtures', q], queryFn: () => fixturesApi.list(q), placeholderData: keepPreviousData });
  const columns: Column<Fixture>[] = [
    { key: 'no', header: 'Fixture', render: (f) => <Typography fontWeight={600} fontSize={14}>{f.fixture_number}</Typography> },
    { key: 'date', header: 'Fixed', render: (f) => formatDate(f.fixture_date) },
    { key: 'vessel', header: 'Vessel', render: (f) => f.vessel?.name },
    { key: 'cp', header: 'Charterer', render: (f) => f.charterer?.legal_name ?? '—' },
    { key: 'type', header: 'Type', hideBelow: 'md', render: (f) => labelOf(BUSINESS_TYPES, f.business_type) },
    { key: 'rate', header: 'Rate', align: 'right', render: (f) => `${groupDigits(f.rate)} ${f.currency} ${labelOf(RATE_BASES, f.rate_basis)}` },
    { key: 'status', header: 'Status', render: (f) => <StatusChip status={f.status} /> },
  ];
  return (
    <>
       <PageHeader title="Fixtures" subtitle="Fixed business — recap snapshots of accepted offers" breadcrumbs={[{ label: 'Chartering' }, { label: 'Fixtures' }]} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search fixture, vessel…" />
        <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(f) => f.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
          onRowClick={(f) => navigate(`/chartering/fixtures/${f.id}`)} emptyTitle="No fixtures yet" />
      </Card>
    </>
  );
}
