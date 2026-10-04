import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Box, Button, Card, FormControlLabel, Stack, Switch, Typography } from '@mui/material';
import DoneAllIcon from '@mui/icons-material/DoneAll';
import { notificationsApi } from '../../api/endpoints';
import { PageHeader } from '../../components/PageHeader';
import { DataTable, type Column } from '../../components/DataTable';
import { StatusChip } from '../../components/StatusChip';
import { ErrorState } from '../../components/Feedback';
import { useNotify } from '../../hooks/useNotify';
import { formatDateTime } from '../../utils/format';
import type { AppNotification } from '../../types/models';

export default function NotificationsPage() {
  const qc = useQueryClient();
  const notify = useNotify();
  const navigate = useNavigate();
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);

  const params = { page, per_page: perPage, unread: unreadOnly || undefined };
  const list = useQuery({ queryKey: ['notifications', 'page', params], queryFn: () => notificationsApi.list(params), placeholderData: keepPreviousData });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['notifications'] });
  const markRead = useMutation({ mutationFn: notificationsApi.markRead, onSuccess: invalidate });
  const markAll = useMutation({
    mutationFn: notificationsApi.markAllRead,
    onSuccess: (res) => { notify.success(res.message); invalidate(); },
    onError: (e) => notify.error(e),
  });

  const columns: Column<AppNotification>[] = [
    {
      key: 'title', header: 'Notification', render: (n) => (
        <Box>
          <Typography fontWeight={n.read_at ? 500 : 700} fontSize={14}>{n.title}</Typography>
          <Typography variant="body2" color="text.secondary">{n.message}</Typography>
        </Box>
      ),
    },
    { key: 'type', header: 'Type', render: (n) => <StatusChip status={n.type} /> },
    { key: 'when', header: 'Received', hideBelow: 'md', render: (n) => formatDateTime(n.created_at) },
    { key: 'actions', header: '', align: 'right', render: (n) => (!n.read_at ? <Button size="small" onClick={(e) => { e.stopPropagation(); markRead.mutate(n.id); }}>Mark read</Button> : null) },
  ];

  return (
    <>
      <PageHeader
        title="Notifications"
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'Notifications' }]}
        actions={<Button startIcon={<DoneAllIcon />} onClick={() => markAll.mutate()} disabled={markAll.isPending}>Mark all as read</Button>}
      />
      <Card>
        <Stack direction="row" sx={{ p: 2 }}>
          <FormControlLabel control={<Switch checked={unreadOnly} onChange={(e) => { setUnreadOnly(e.target.checked); setPage(1); }} />} label="Unread only" />
        </Stack>
        {list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : (
          <DataTable
            columns={columns}
            rows={list.data?.data ?? []}
            rowKey={(n) => n.id}
            loading={list.isFetching}
            meta={list.data?.meta}
            onPageChange={setPage}
            onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
            onRowClick={(n) => { if (!n.read_at) markRead.mutate(n.id); if (n.action_url) navigate(n.action_url); }}
            emptyTitle={unreadOnly ? 'No unread notifications' : 'No notifications yet'}
          />
        )}
      </Card>
    </>
  );
}
