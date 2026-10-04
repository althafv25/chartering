import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Chip, IconButton, Stack, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditOutlined from '@mui/icons-material/EditOutlined';
import VisibilityOutlined from '@mui/icons-material/VisibilityOutlined';
import DeleteOutline from '@mui/icons-material/DeleteOutline';
import { rolesApi } from '../../api/endpoints';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ErrorState } from '../../components/Feedback';
import { Can } from '../../auth/guards';
import { useAuth } from '../../auth/useAuth';
import { P } from '../../constants/permissions';
import { useNotify } from '../../hooks/useNotify';
import type { Role } from '../../types/models';
import { RoleDialog } from './RoleDialog';

export default function RolesPage() {
  const qc = useQueryClient();
  const notify = useNotify();
  const { can } = useAuth();
  const roles = useQuery({ queryKey: ['roles'], queryFn: rolesApi.list });
  const [dialog, setDialog] = useState<{ open: boolean; role: Role | null }>({ open: false, role: null });
  const [deleting, setDeleting] = useState<Role | null>(null);

  const remove = useMutation({
    mutationFn: (r: Role) => rolesApi.remove(r.id),
    onSuccess: (res) => { notify.success(res.message); setDeleting(null); qc.invalidateQueries({ queryKey: ['roles'] }); },
    onError: (e) => notify.error(e),
  });

  const canEdit = can(P.RolesUpdate);

  const columns: Column<Role>[] = [
    {
      key: 'name', header: 'Role', render: (r) => (
        <Stack direction="row" spacing={1} alignItems="center">
          <Typography fontWeight={600} fontSize={14}>{r.label}</Typography>
          {r.is_system && <Chip size="small" variant="outlined" label="System" />}
        </Stack>
      ),
    },
    { key: 'perms', header: 'Permissions', render: (r) => (r.is_locked ? 'All (implicit)' : r.permissions?.length ?? 0) },
    { key: 'users', header: 'Users', render: (r) => r.users_count ?? 0 },
    {
      key: 'actions', header: '', align: 'right', render: (r) => (
        <Stack direction="row" justifyContent="flex-end">
          <Tooltip title={canEdit && !r.is_locked ? 'Edit' : 'View'}>
            <IconButton size="small" onClick={() => setDialog({ open: true, role: r })} aria-label={`Open ${r.label}`}>
              {canEdit && !r.is_locked ? <EditOutlined fontSize="small" /> : <VisibilityOutlined fontSize="small" />}
            </IconButton>
          </Tooltip>
          <Can permission={P.RolesDelete}>
            {!r.is_system && (
              <Tooltip title="Delete"><IconButton size="small" color="error" onClick={() => setDeleting(r)} aria-label={`Delete ${r.label}`}><DeleteOutline fontSize="small" /></IconButton></Tooltip>
            )}
          </Can>
        </Stack>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Roles & permissions"
        subtitle="Control what each role can view, change and approve"
        breadcrumbs={[{ label: 'Administration' }, { label: 'Roles & permissions' }]}
        actions={<Can permission={P.RolesCreate}><Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog({ open: true, role: null })}>New role</Button></Can>}
      />
      <Card>
        {roles.isError ? <ErrorState error={roles.error} onRetry={() => roles.refetch()} /> : (
          <DataTable columns={columns} rows={roles.data ?? []} rowKey={(r) => r.id} loading={roles.isLoading} />
        )}
      </Card>
      <RoleDialog open={dialog.open} role={dialog.role} readOnly={!canEdit} onClose={() => setDialog({ open: false, role: null })} />
      <ConfirmDialog
        open={!!deleting}
        title="Delete role"
        message={`Delete the role "${deleting?.label}"? This cannot be undone.`}
        confirmLabel="Delete"
        danger
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting)}
        onClose={() => setDeleting(null)}
      />
    </>
  );
}
