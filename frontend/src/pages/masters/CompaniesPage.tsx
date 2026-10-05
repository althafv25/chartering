import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Box, Button, Card, Chip, MenuItem, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import { companiesApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { Can } from '../../auth/guards';
import { P } from '../../constants/permissions';
import { countryName } from '../../constants/countries';
import { useDebounce } from '../../hooks/useDebounce';
import { humanize } from '../../utils/format';
import type { Company } from '../../types/masters';
import { CompanyDrawer } from './CompanyDrawer';

const TABS = ['', 'charterer', 'customer', 'owner', 'broker', 'agent', 'supplier'] as const;

export default function CompaniesPage() {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const role = params.get('role') ?? '';
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const debounced = useDebounce(search);

  const q = { page, per_page: perPage, search: debounced || undefined, role: role || undefined, status: status || undefined };
  const list = useQuery({ queryKey: ['companies', q], queryFn: () => companiesApi.list(q), placeholderData: keepPreviousData });

  const columns: Column<Company>[] = [
    { key: 'name', header: 'Company', render: (c) => (
      <Box>
        <Typography fontWeight={600} fontSize={14}>{c.legal_name}</Typography>
        <Typography variant="body2" color="text.secondary">{c.code}{c.trading_name ? ` · ${c.trading_name}` : ''}</Typography>
      </Box>
    ) },
    { key: 'roles', header: 'Roles', render: (c) => <Stack direction="row" gap={0.5} flexWrap="wrap">{c.roles.map((r) => <Chip key={r} size="small" label={humanize(r)} />)}</Stack> },
    { key: 'country', header: 'Country', hideBelow: 'md', render: (c) => countryName(c.country) },
    { key: 'email', header: 'Email', hideBelow: 'lg', render: (c) => c.email || '—' },
    { key: 'contacts', header: 'Contacts', hideBelow: 'md', align: 'right', render: (c) => c.contacts_count ?? 0 },
    { key: 'status', header: 'Status', render: (c) => <StatusChip status={c.status} /> },
  ];

  return (
    <>
      <PageHeader
        title="Companies"
        subtitle="Owners, charterers, brokers, agents, suppliers and other maritime contacts"
        breadcrumbs={[{ label: 'Masters' }, { label: 'Companies' }]}
        actions={<Can permission={P.CompaniesCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDrawerOpen(true)}>Add company</Button></Can>}
      />
      <Card>
        <Tabs value={role} onChange={(_, v) => { setParams(v ? { role: v } : {}); setPage(1); }} variant="scrollable" sx={{ px: 2, borderBottom: 1, borderColor: 'divider' }}>
          {TABS.map((t) => <Tab key={t} value={t} label={t ? `${humanize(t)}s` : 'All'} />)}
        </Tabs>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search name, code, alias, contact, email…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All statuses</MenuItem><MenuItem value="active">Active</MenuItem><MenuItem value="inactive">Inactive</MenuItem><MenuItem value="blocked">Blocked</MenuItem>
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(c) => c.id} loading={list.isFetching} meta={list.data?.meta}
            onPageChange={setPage} onPerPageChange={(n) => { setPerPage(n); setPage(1); }} onRowClick={(c) => navigate(`/masters/companies/${c.id}`)}
            emptyTitle="No companies found" emptyDescription="Adjust the filters or add a new company." />
        )}
      </Card>
      <CompanyDrawer open={drawerOpen} company={null} defaultRole={role || undefined} onClose={() => setDrawerOpen(false)} onSaved={(c) => navigate(`/masters/companies/${c.id}`)} />
    </>
  );
}
