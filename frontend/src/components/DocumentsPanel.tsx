import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Grid, IconButton, MenuItem, Stack, TextField, Tooltip, Typography } from '@mui/material';
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { documentsApi } from '../api/masters';
import { errorMessage } from '../api/client';
import { DataTable, type Column } from './DataTable';
import { ConfirmDialog } from './ConfirmDialog';
import { LoadingButton } from './LoadingButton';
import { useNotify } from '../hooks/useNotify';
import { useAuth } from '../auth/useAuth';
import { P } from '../constants/permissions';
import { formatDate } from '../utils/format';
import type { DocumentItem } from '../types/masters';

const size = (b: number) => (b < 1024 ? `${b} B` : b < 1048576 ? `${(b / 1024).toFixed(0)} KB` : `${(b / 1048576).toFixed(1)} MB`);

function isExpiring(date: string | null) {
  if (!date) return null;
  const days = (new Date(date).getTime() - Date.now()) / 86_400_000;
  return days < 0 ? 'expired' : days <= 30 ? 'soon' : null;
}

/**
 * Private documents attached to a record. Upload/delete visibility mirrors the
 * API rule: document permission AND parent permission (`canEdit`).
 */
export function DocumentsPanel({ parentType, parentId, canEdit }: { parentType: string; parentId: number; canEdit: boolean }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(false);
  const [deleting, setDeleting] = useState<DocumentItem | null>(null);
  const key = ['documents', parentType, parentId, page];

  const list = useQuery({ queryKey: key, queryFn: () => documentsApi.list(parentType, parentId, { page, per_page: 10 }) });
  const remove = useMutation({
    mutationFn: (d: DocumentItem) => documentsApi.remove(d.id),
    onSuccess: (r) => { notify.success(r.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['documents', parentType, parentId] }); },
    onError: (e) => notify.error(e),
  });

  const canUpload = canEdit && can(P.DocumentsUpload);
  const canDelete = canEdit && can(P.DocumentsDelete);

  const columns: Column<DocumentItem>[] = [
    { key: 'title', header: 'Document', render: (d) => (
      <Box>
        <Typography fontWeight={600} fontSize={14}>{d.title}</Typography>
        <Typography variant="body2" color="text.secondary">{d.original_filename} · {size(d.size_bytes)}</Typography>
      </Box>
    ) },
    { key: 'type', header: 'Type', render: (d) => d.document_type?.name ?? '—' },
    { key: 'number', header: 'Number', hideBelow: 'md', render: (d) => d.document_number || '—' },
    { key: 'expiry', header: 'Expiry', render: (d) => {
      const flag = isExpiring(d.expiry_date);
      return <Typography fontSize={14} color={flag === 'expired' ? 'error.main' : flag === 'soon' ? 'warning.main' : undefined} fontWeight={flag ? 600 : 400}>
        {formatDate(d.expiry_date)}{flag === 'expired' ? ' (expired)' : ''}
      </Typography>;
    } },
    { key: 'by', header: 'Uploaded', hideBelow: 'lg', render: (d) => `${d.uploaded_by?.name ?? '—'}, ${formatDate(d.created_at)}` },
    { key: 'actions', header: '', align: 'right', render: (d) => (
      <Stack direction="row" justifyContent="flex-end">
        <Tooltip title="Download"><IconButton size="small" aria-label={`Download ${d.title}`} onClick={() => documentsApi.download(d).catch((e) => notify.error(e))}><DownloadOutlined fontSize="small" /></IconButton></Tooltip>
        {canDelete && <Tooltip title="Delete"><IconButton size="small" color="error" aria-label={`Delete ${d.title}`} onClick={() => setDeleting(d)}><DeleteOutline fontSize="small" /></IconButton></Tooltip>}
      </Stack>
    ) },
  ];

  return (
    <Box>
      {canUpload && (
        <Stack direction="row" justifyContent="flex-end" sx={{ p: 2, pb: 0 }}>
          <Button startIcon={<UploadFileOutlined />} variant="outlined" onClick={() => setOpen(true)}>Upload document</Button>
        </Stack>
      )}
      <DataTable columns={columns} rows={list.data?.data ?? []} rowKey={(d) => d.id} loading={list.isFetching} meta={list.data?.meta} onPageChange={setPage} emptyTitle="No documents yet" />
      <UploadDialog open={open} parentType={parentType} parentId={parentId} onClose={() => setOpen(false)} />
      <ConfirmDialog open={!!deleting} title="Delete document" message={`Delete “${deleting?.title}”?`} confirmLabel="Delete" danger
        loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting)} onClose={() => setDeleting(null)} />
    </Box>
  );
}

function UploadDialog({ open, parentType, parentId, onClose }: { open: boolean; parentType: string; parentId: number; onClose: () => void }) {
  const qc = useQueryClient();
  const notify = useNotify();
  const fileRef = useRef<HTMLInputElement>(null);
  const types = useQuery({ queryKey: ['document-types'], queryFn: documentsApi.types, enabled: open, staleTime: Infinity });
  const [file, setFile] = useState<File | null>(null);
  const [form, setForm] = useState({ document_type_id: '', title: '', document_number: '', issue_date: '', expiry_date: '' });
  const [error, setError] = useState<string | null>(null);

  const reset = () => { setFile(null); setForm({ document_type_id: '', title: '', document_number: '', issue_date: '', expiry_date: '' }); setError(null); };
  const close = () => { reset(); onClose(); };
  const type = types.data?.find((t) => String(t.id) === form.document_type_id);

  const upload = useMutation({
    mutationFn: () => {
      const fd = new FormData();
      fd.append('file', file as File);
      Object.entries(form).forEach(([k, v]) => v && fd.append(k, v));
      return documentsApi.upload(parentType, parentId, fd);
    },
    onSuccess: (r) => { notify.success(r.message); qc.invalidateQueries({ queryKey: ['documents', parentType, parentId] }); close(); },
    onError: (e) => setError(errorMessage(e)),
  });

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Dialog open={open} onClose={upload.isPending ? undefined : close} maxWidth="sm" fullWidth>
      <DialogTitle>Upload document</DialogTitle>
      <DialogContent dividers>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <Stack spacing={2}>
          <Box>
            <input ref={fileRef} type="file" hidden onChange={(e) => { const f = e.target.files?.[0] ?? null; setFile(f); if (f && !form.title) setForm((x) => ({ ...x, title: f.name.replace(/\.[^.]+$/, '') })); }} />
            <Button variant="outlined" startIcon={<UploadFileOutlined />} onClick={() => fileRef.current?.click()}>{file ? 'Change file' : 'Choose file'}</Button>
            <Typography variant="body2" color="text.secondary" component="span" sx={{ ml: 1.5 }}>{file ? `${file.name} (${size(file.size)})` : 'PDF, images, Office files — max 50 MB'}</Typography>
          </Box>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField select label="Document type" required value={form.document_type_id} onChange={set('document_type_id')}>
                {(types.data ?? []).map((t) => <MenuItem key={t.id} value={String(t.id)}>{t.name}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Number / reference" value={form.document_number} onChange={set('document_number')} /></Grid>
            <Grid size={12}><TextField label="Title" value={form.title} onChange={set('title')} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}><TextField label="Issue date" type="date" value={form.issue_date} onChange={set('issue_date')} slotProps={{ inputLabel: { shrink: true } }} /></Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField label="Expiry date" type="date" required={type?.requires_expiry} value={form.expiry_date} onChange={set('expiry_date')}
                slotProps={{ inputLabel: { shrink: true } }} helperText={type?.requires_expiry ? 'Required for this document type' : undefined} />
            </Grid>
          </Grid>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={close} disabled={upload.isPending}>Cancel</Button>
        <LoadingButton variant="contained" loading={upload.isPending} disabled={!file || !form.document_type_id} onClick={() => upload.mutate()}>Upload</LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
