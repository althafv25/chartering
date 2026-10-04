import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Box, Card, IconButton, Link, MenuItem, Stack, TextField, Tooltip, Typography } from '@mui/material';
import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import { documentsApi } from '../../api/masters';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { ErrorState } from '../../components/Feedback';
import { DOCUMENT_PARENTS } from '../../constants/documents';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import { formatDate } from '../../utils/format';
import type { RegisterDocument } from '../../types/masters';

const size = (b: number) => (b < 1024 ? `${b} B` : b < 1048576 ? `${(b / 1024).toFixed(0)} KB` : `${(b / 1048576).toFixed(1)} MB`);

export default function DocumentsRegisterPage() {
  const notify = useNotify();
  const [search, setSearch] = useState('');
  const [parent, setParent] = useState('');
  const [type, setType] = useState('');
  const [expiry, setExpiry] = useState('');
  const [page, setPage] = useState(1);
  const debounced = useDebounce(search);
  const types = useQuery({ queryKey: ['document-types'], queryFn: documentsApi.types, staleTime: Infinity });
  const q = { page, per_page: 15, search: debounced || undefined, parent_type: parent || undefined, document_type_id: type || undefined, expiry: expiry || undefined };
  const list = useQuery({ queryKey: ['documents', 'register', q], queryFn: () => documentsApi.register(q), placeholderData: keepPreviousData });
  const reset = <T,>(set: (v: T) => void) => (v: T) => { set(v); setPage(1); };

  const columns: Column<RegisterDocument>[] = [
    { key: 'title', header: 'Document', render: (d) => <Box><Typography fontWeight={600} fontSize={14}>{d.title}</Typography><Typography variant="body2" color="text.secondary">{d.original_filename} · {size(d.size_bytes)}</Typography></Box> },
    { key: 'type', header: 'Type', hideBelow: 'md', render: (d) => d.document_type?.name ?? '—' },
    { key: 'parent', header: 'Linked record', render: (d) => {
      const def = DOCUMENT_PARENTS[d.parent.type];
      const text = `${def?.label ?? d.parent.type} · ${d.parent.label}`;
      return def?.path ? <Link component={RouterLink} to={def.path(d.parent.id)} underline="hover" fontSize={14}>{text}</Link> : <Typography fontSize={14}>{text}</Typography>;
    } },
    { key: 'expiry', header: 'Expiry', render: (d) => {
      if (!d.expiry_date) return '—';
      const days = (new Date(d.expiry_date).getTime() - Date.now()) / 86_400_000;
      return <Typography fontSize={14} color={days < 0 ? 'error.main' : days <= 30 ? 'warning.main' : undefined} fontWeight={days <= 30 ? 600 : 400}>{formatDate(d.expiry_date)}{days < 0 ? ' (expired)' : ''}</Typography>;
    } },
    { key: 'by', header: 'Uploaded', hideBelow: 'lg', render: (d) => `${d.uploaded_by?.name ?? '—'}, ${formatDate(d.created_at)}` },
    { key: 'dl', header: '', align: 'right', render: (d) => (
      <Tooltip title="Download"><IconButton size="small" aria-label={`Download ${d.title}`} onClick={() => documentsApi.download(d).catch((e) => notify.error(e))}><DownloadOutlined fontSize="small" /></IconButton></Tooltip>
    ) },
  ];

  return (
    <>
      <PageHeader title="Documents" subtitle="Every document you may see, across all records. Upload and delete happen on the record itself." breadcrumbs={[{ label: 'Documents' }]} />
      <Card>
        <FilterBar search={search} onSearch={reset(setSearch)} placeholder="Search title, number, file name…">
          <TextField select label="Record type" value={parent} onChange={(e) => reset(setParent)(e.target.value)} sx={{ maxWidth: { md: 190 } }}>
            <MenuItem value="">All</MenuItem>{Object.entries(DOCUMENT_PARENTS).map(([k, v]) => <MenuItem key={k} value={k}>{v.label}</MenuItem>)}
          </TextField>
          <TextField select label="Document type" value={type} onChange={(e) => reset(setType)(e.target.value)} sx={{ maxWidth: { md: 190 } }}>
            <MenuItem value="">All</MenuItem>{(types.data ?? []).map((t) => <MenuItem key={t.id} value={String(t.id)}>{t.name}</MenuItem>)}
          </TextField>
          <TextField select label="Expiry" value={expiry} onChange={(e) => reset(setExpiry)(e.target.value)} sx={{ maxWidth: { md: 170 } }}>
            <MenuItem value="">Any</MenuItem><MenuItem value="expired">Expired</MenuItem>{['30', '60', '90'].map((d) => <MenuItem key={d} value={d}>within {d} days</MenuItem>)}
          </TextField>
        </FilterBar>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <Stack>
            <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(d) => d.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage}
              emptyTitle="No documents" emptyDescription="Documents attached to records you can view will appear here." />
          </Stack>
        )}
      </Card>
    </>
  );
}
