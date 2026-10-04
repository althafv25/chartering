import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Box, Stack, Typography } from '@mui/material';
import { DataTable, type Column } from './DataTable';
import { StatusChip } from './StatusChip';
import { formatDateTime } from '../utils/format';
import type { ListParams, Paginated } from '../types/api';
import type { ActivityEntry } from '../types/chartering';

const summarize = (e: ActivityEntry) => {
  const parts: string[] = [];
  const n = e.new ?? {};
  const o = e.old ?? {};
  for (const k of Object.keys(n).slice(0, 4)) {
    const to = typeof n[k] === 'object' ? JSON.stringify(n[k]) : String(n[k] ?? '—');
    parts.push(k in o ? `${k}: ${String(o[k] ?? '—')} → ${to}` : `${k}: ${to}`);
  }
  return parts.join(' · ');
};

/** Record-scoped history (who did what, when) — uses the record's view permission, not audit-log access. */
export function ActivityPanel({ queryKey, fetcher }: { queryKey: unknown[]; fetcher: (p: ListParams) => Promise<Paginated<ActivityEntry>> }) {
  const [page, setPage] = useState(1);
  const q = useQuery({ queryKey: [...queryKey, 'activity', page], queryFn: () => fetcher({ page, per_page: 15 }), placeholderData: keepPreviousData });

  const columns: Column<ActivityEntry>[] = [
    { key: 'when', header: 'When', width: 170, render: (e) => formatDateTime(e.created_at) },
    { key: 'who', header: 'User', render: (e) => e.causer?.name ?? 'System' },
    { key: 'what', header: 'Activity', render: (e) => (
      <Box>
        <Stack direction="row" spacing={1} alignItems="center">
          {e.event && <StatusChip status={e.event} />}
          <Typography fontSize={14}>{e.description}</Typography>
        </Stack>
        <Typography variant="caption" color="text.secondary" sx={{ wordBreak: 'break-word' }}>{summarize(e)}</Typography>
      </Box>
    ) },
  ];

  return <DataTable columns={columns} rows={q.data?.data ?? []} rowKey={(e) => e.id} loading={q.isFetching} meta={q.data?.meta} onPageChange={setPage} emptyTitle="No activity yet" />;
}
