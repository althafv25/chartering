import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Box, Button, Card, Chip, IconButton, MenuItem, Stack, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import BlockOutlined from '@mui/icons-material/BlockOutlined';
import CheckCircleOutline from '@mui/icons-material/CheckCircleOutline';
import { rolesApi, usersApi } from '../../api/endpoints';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { FilterBar } from '../../components/FilterBar';
import { StatusChip } from '../../components/StatusChip';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ErrorState } from '../../components/Feedback';
import { Can } from '../../auth/guards';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useDebounce } from '../../hooks/useDebounce';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime } from '../../utils/format';
import type { User } from '../../types/models';
import { UserDialog } from './UserDialog';

export default function UsersPage() {
  const qc = useQueryClient();
  const notify = useNotify();
  const { user: me } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [role, setRole] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [editing, setEditing] = useState<User | null>(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [deleting, setDeleting] = useState<User | null>(null);
  const debounced = useDebounce(search);

  const params = { page, per_page: perPage, search: debounced || undefined, status: status || undefined, role: role || undefined };
  const users = useQuery({ queryKey: ['users', params], queryFn: () => usersApi.list(params), placeholderData: keepPreviousData });
  const roles = useQuery({ queryKey: ['roles'], queryFn: rolesApi.list });

  const toggleStatus = useMutation({
    mutationFn: (u: User) => usersApi.setStatus(u.id, u.status === 'active' ? 'inactive' : 'active'),
    onSuccess: (res) => { notify.success(res.message); qc.invalidateQueries({ queryKey: ['users'] }); },
    onError: (e) => notify.error(e),
  });

  const remove = useMutation({
    mutationFn: (u: User) => usersApi.remove(u.id),
    onSuccess: (res) => { notify.success(res.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['users'] }); },
    onError: (e) => notify.error(e),
  });

  const roleLabel = (name: string) => roles.data?.find((r) => r.name === name)?.label ?? name;

  const columns: Column<User>[] = [
    {
      key: 'name', header: 'Name', render: (u) => (
        <Box>
          <Typography fontWeight={600} fontSize={14}>{u.name}</Typography>
          <Typography variant="body2" color="text.secondary">{u.email}</Typography>
        </Box>
      ),
    },
    { key: 'job', header: 'Job title', hideBelow: 'md', render: (u) => u.job_title || '—' },
    { key: 'roles', header: 'Roles', render: (u) => <Stack direction="row" gap={0.5} flexWrap="wrap">{u.roles.map((r) => <Chip key={r} size="small" label={roleLabel(r)} />)}</Stack> },
    { key: 'status', header: 'Status', render: (u) => <StatusChip status={u.status} /> },
    { key: 'last', header: 'Last sign-in', hideBelow: 'lg', render: (u) => formatDateTime(u.last_login_at) },
    {
      key: 'actions', header: '', align: 'right', render: (u) => (
        <Stack direction="row" justifyContent="flex-end">
          <Can permission={P.UsersUpdate}>
            <Tooltip title="Edit"><IconButton size="small" onClick={() => { setEditing(u); setDialogOpen(true); }} aria-label={`Edit ${u.name}`}><EditOutlined fontSize="small" /></IconButton></Tooltip>
            {u.id !== me?.id && (
              <Tooltip title={u.status === 'active' ? 'Deactivate' : 'Activate'}>
                <IconButton size="small" onClick={() => toggleStatus.mutate(u)} disabled={toggleStatus.isPending} aria-label={u.status === 'active' ? `Deactivate ${u.name}` : `Activate ${u.name}`}>
                  {u.status === 'active' ? <BlockOutlined fontSize="small" /> : <CheckCircleOutline fontSize="small" />}
                </IconButton>
              </Tooltip>
            )}
          </Can>
          <Can permission={P.UsersDelete}>
            {u.id !== me?.id && (
              <Tooltip title="Delete"><IconButton size="small" color="error" onClick={() => setDeleting(u)} aria-label={`Delete ${u.name}`}><DeleteOutline fontSize="small" /></IconButton></Tooltip>
            )}
          </Can>
        </Stack>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Users"
        subtitle="Company accounts and role assignments"
        breadcrumbs={[{ label: 'Administration' }, { label: 'Users' }]}
        actions={<Can permission={P.UsersCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => { setEditing(null); setDialogOpen(true); }}>New user</Button></Can>}
      />
      <Card>
        <FilterBar search={search} onSearch={(v) => { setSearch(v); setPage(1); }} placeholder="Search name, email, job title…">
          <TextField select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 180 } }}>
            <MenuItem value="">All statuses</MenuItem>
            <MenuItem value="active">Active</MenuItem>
            <MenuItem value="inactive">Inactive</MenuItem>
          </TextField>
          <TextField select label="Role" value={role} onChange={(e) => { setRole(e.target.value); setPage(1); }} sx={{ maxWidth: { md: 220 } }}>
            <MenuItem value="">All roles</MenuItem>
            {(roles.data ?? []).map((r) => <MenuItem key={r.id} value={r.name}>{r.label}</MenuItem>)}
          </TextField>
        </FilterBar>
        {users.isError ? <ErrorState error={users.error} onRetry={() => users.refetch()} /> : (
          <DataTable
            columns={columns}
            rows={users.data?.data ?? []}
            rowKey={(u) => u.id}
            loading={users.isFetching}
            meta={users.data?.meta}
            onPageChange={setPage}
            onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
            emptyTitle="No users match your filters"
          />
        )}
      </Card>

      <UserDialog open={dialogOpen} user={editing} onClose={() => setDialogOpen(false)} />
      <ConfirmDialog
        open={!!deleting}
        title="Delete user"
        message={`Delete ${deleting?.name}? They will lose access immediately. Their audit history is preserved.`}
        confirmLabel="Delete"
        danger
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
        onClose={() => setDeleting(null)}
      />
    </>
  );
}
