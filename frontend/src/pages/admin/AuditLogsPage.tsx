import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Box, Card, Drawer, IconButton, MenuItem, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import { auditApi } from '../../api/endpoints';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { useDebounce } from '../../hooks/useDebounce';
import { formatDateTime, humanize } from '../../utils/format';
import type { AuditLog } from '../../types/models';

const show = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));

function ChangesTable({ log }: { log: AuditLog }) {
  const keys = Array.from(new Set([...Object.keys(log.old ?? {}), ...Object.keys(log.new ?? {})]));
  if (keys.length === 0) return <Typography variant="body2" color="text.secondary">No field changes recorded.</Typography>;

  return (
    <Table size="small">
      <TableHead><TableRow><TableCell>Field</TableCell><TableCell>Old value</TableCell><TableCell>New value</TableCell></TableRow></TableHead>
      <TableBody>
        {keys.map((k) => (
          <TableRow key={k}>
            <TableCell sx={{ fontWeight: 600 }}>{k}</TableCell>
            <TableCell sx={{ wordBreak: 'break-word', color: 'error.main' }}>{show(log.old?.[k])}</TableCell>
            <TableCell sx={{ wordBreak: 'break-word', color: 'success.main' }}>{show(log.new?.[k])}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

export default function AuditLogsPage() {
  const [search, setSearch] = useState('');
  const [logName, setLogName] = useState('');
  const [event, setEvent] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [selected, setSelected] = useState<AuditLog | null>(null);
  const debounced = useDebounce(search);

  const params = { page, per_page: perPage, search: debounced || undefined, log_name: logName || undefined, event: event || undefined, from: from || undefined, to: to || undefined };
  const logs = useQuery({ queryKey: ['audit-logs', params], queryFn: () => auditApi.list(params), placeholderData: keepPreviousData });
  const filters = useQuery({ queryKey: ['audit-logs', 'filters'], queryFn: auditApi.filters, staleTime: 5 * 60_000 });

  const reset = <T,>(setter: (v: T) => void) => (v: T) => { setter(v); setPage(1); };

  const columns: Column<AuditLog>[] = [
    { key: 'when', header: 'When', render: (l) => formatDateTime(l.created_at), width: 170 },
    { key: 'user', header: 'User', render: (l) => l.causer?.name ?? 'System' },
    { key: 'event', header: 'Event', render: (l) => (l.event ? <StatusChip status={l.event} /> : '—') },
    { key: 'desc', header: 'Description', render: (l) => l.description },
    { key: 'subject', header: 'Record', hideBelow: 'md', render: (l) => (l.subject_type ? `${humanize(l.subject_type)} #${l.subject_id}` : '—') },
    { key: 'ip', header: 'IP', hideBelow: 'lg', render: (l) => l.ip ?? '—' },
  ];

  return (
    <>
      <PageHeader title="Audit logs" subtitle="Who changed what, and when" breadcrumbs={[{ label: 'Administration' }, { label: 'Audit logs' }]} />
      <Card>
        <FilterBar search={search} onSearch={reset(setSearch)} placeholder="Search description…">
          <TextField select label="Area" value={logName} onChange={(e) => reset(setLogName)(e.target.value)} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All areas</MenuItem>
            {filters.data?.log_names.map((n) => <MenuItem key={n} value={n}>{humanize(n)}</MenuItem>)}
          </TextField>
          <TextField select label="Event" value={event} onChange={(e) => reset(setEvent)(e.target.value)} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All events</MenuItem>
            {filters.data?.events.map((n) => <MenuItem key={n} value={n}>{humanize(n)}</MenuItem>)}
          </TextField>
          <TextField type="date" label="From" value={from} onChange={(e) => reset(setFrom)(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ maxWidth: { md: 170 } }} />
          <TextField type="date" label="To" value={to} onChange={(e) => reset(setTo)(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ maxWidth: { md: 170 } }} />
        </FilterBar>
        {logs.isError ? <ErrorState error={logs.error} onRetry={() => logs.refetch()} /> : (
          <DataTable
            columns={columns}
            rows={logs.data?.data ?? []}
            rowKey={(l) => l.id}
            loading={logs.isFetching}
            meta={logs.data?.meta}
            onPageChange={setPage}
            onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
            onRowClick={setSelected}
            emptyTitle="No audit entries for these filters"
          />
        )}
      </Card>

      <Drawer anchor="right" open={!!selected} onClose={() => setSelected(null)} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 520 } } } }}>
        {selected && (
          <Box sx={{ p: 3 }}>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2 }}>
              <Typography variant="h6">{selected.description}</Typography>
              <IconButton onClick={() => setSelected(null)} aria-label="Close"><CloseIcon /></IconButton>
            </Stack>
            <Stack spacing={0.75} sx={{ mb: 3 }}>
              {[
                ['When', formatDateTime(selected.created_at)],
                ['User', selected.causer?.name ?? 'System'],
                ['Record', selected.subject_type ? `${humanize(selected.subject_type)} #${selected.subject_id}` : '—'],
                ['IP address', selected.ip ?? '—'],
                ['Request ID', selected.request_id ?? '—'],
                ['User agent', selected.user_agent ?? '—'],
              ].map(([k, v]) => (
                <Stack key={k} direction="row" spacing={2}>
                  <Typography variant="body2" color="text.secondary" sx={{ width: 110, flexShrink: 0 }}>{k}</Typography>
                  <Typography variant="body2" sx={{ wordBreak: 'break-word' }}>{v}</Typography>
                </Stack>
              ))}
            </Stack>
            <Typography variant="subtitle2" gutterBottom>Changes</Typography>
            <ChangesTable log={selected} />
          </Box>
        )}
      </Drawer>
    </>
  );
}
