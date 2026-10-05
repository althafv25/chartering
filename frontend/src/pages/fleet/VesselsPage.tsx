import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Box, Button, Card, MenuItem, TextField, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { vesselsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { ReferenceSelect } from '../../components/MasterPickers';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { VESSEL_RECORD_STATUSES } from '../../constants/masters';
import { useDebounce } from '../../hooks/useDebounce';
import { humanize } from '../../utils/format';
import type { Vessel } from '../../types/masters';

export default function VesselsPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [typeId, setTypeId] = useState<number | null>(null);
  const [status, setStatus] = useState('active');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const debounced = useDebounce(search);

  const q = { page, per_page: perPage, search: debounced || undefined, vessel_type_id: typeId ?? undefined, status: status || undefined };
  const list = useQuery({ queryKey: ['vessels', q], queryFn: () => vesselsApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<Vessel>[] = [
    { key: 'name', header: 'Vessel', render: (v) => <Box><Typography fontWeight={600} fontSize={14}>{v.name}</Typography><Typography variant="body2" color="text.secondary">{v.code}{v.imo_number ? ` · IMO ${v.imo_number}` : ''}</Typography></Box> },
    { key: 'type', header: 'Type', render: (v) => v.vessel_type?.name ?? '—' },
    { key: 'owner', header: 'Owner', hideBelow: 'lg', render: (v) => v.owner?.legal_name ?? '—' },
    { key: 'built', header: 'Built', hideBelow: 'md', align: 'right', render: (v) => v.year_built ?? '—' },
    { key: 'dwt', header: 'DWT (t)', hideBelow: 'md', align: 'right', render: (v) => v.dwt_mt ?? '—' },
    { key: 'commercial', header: 'Commercial', render: (v) => (v.commercial_status ? <StatusChip status={v.commercial_status} /> : '—') },
    { key: 'operational', header: 'Operational', hideBelow: 'md', render: (v) => (v.operational_status ? <StatusChip status={v.operational_status} /> : '—') },
    { key: 'status', header: 'Record', hideBelow: 'lg', render: (v) => <StatusChip status={v.status} /> },
  ];

  return (
    <>
      <PageHeader title="Vessels" subtitle="Vessel particulars, performance assumptions and status"
        breadcrumbs={[{ label: 'Masters' }, { label: 'Vessels' }]}
        actions={<Can permission={P.VesselsCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => navigate('/fleet/vessels/new')}>Add vessel</Button></Can>} />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search name, code, IMO, MMSI, call sign, former name…">
          <ReferenceSelect type="vessel-types" label="Type" value={typeId} allowEmpty onChange={(v) => { setTypeId(v); setPage(1); }} sx={{ maxWidth: { md: 240 } }} />
          <TextField select label="Record status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All</MenuItem>
            {VESSEL_RECORD_STATUSES.map((s) => <MenuItem key={s} value={s}>{humanize(s)}</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(v) => v.id} loading={list.isFetching} meta={list.data?.meta}
            onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} onRowClick={(v) => navigate(`/fleet/vessels/${v.id}`)}
            emptyTitle="No vessels found" />
        )}
      </Card>
    </>
  );
}
