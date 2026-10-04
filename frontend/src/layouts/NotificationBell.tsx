import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge, Box, Button, Divider, IconButton, List, ListItemButton, ListItemText, Popover, Tooltip, Typography } from '@mui/material';
import NotificationsOutlined from '@mui/icons-material/NotificationsOutlined';
import { notificationsApi } from '../api/endpoints';
import { timeAgo } from '../utils/format';

export function NotificationBell() {
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: count } = useQuery({ queryKey: ['notifications', 'unread-count'], queryFn: notificationsApi.unreadCount, refetchInterval: 60_000 });
  const { data: latest } = useQuery({
    queryKey: ['notifications', 'latest'],
    queryFn: () => notificationsApi.list({ per_page: 6 }),
    enabled: !!anchor,
  });

  const markRead = useMutation({
    mutationFn: notificationsApi.markRead,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  });

  const unread = count?.count ?? 0;

  return (
    <>
      <Tooltip title="Notifications">
        <IconButton onClick={(e) => setAnchor(e.currentTarget)} aria-label={`Notifications, ${unread} unread`}>
          <Badge badgeContent={unread} color="error" max={99}><NotificationsOutlined /></Badge>
        </IconButton>
      </Tooltip>
      <Popover
        open={!!anchor}
        anchorEl={anchor}
        onClose={() => setAnchor(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}
        slotProps={{ paper: { sx: { width: 360, maxWidth: '95vw' } } }}
      >
        <Box sx={{ px: 2, py: 1.5 }}>
          <Typography variant="subtitle2">Notifications</Typography>
        </Box>
        <Divider />
        {latest?.data.length ? (
          <List dense disablePadding>
            {latest.data.map((n) => (
              <ListItemButton
                key={n.id}
                onClick={() => {
                  if (!n.read_at) markRead.mutate(n.id);
                  setAnchor(null);
                  if (n.action_url) navigate(n.action_url);
                }}
                sx={{ alignItems: 'flex-start', bgcolor: n.read_at ? undefined : 'action.hover' }}
              >
                <ListItemText
                  primary={n.title}
                  secondary={<>{n.message}<br />{timeAgo(n.created_at)}</>}
                  slotProps={{ primary: { fontWeight: n.read_at ? 500 : 700, fontSize: 14 } }}
                />
              </ListItemButton>
            ))}
          </List>
        ) : (
          <Typography variant="body2" color="text.secondary" sx={{ p: 3, textAlign: 'center' }}>You're all caught up.</Typography>
        )}
        <Divider />
        <Box sx={{ p: 1, textAlign: 'center' }}>
          <Button size="small" onClick={() => { setAnchor(null); navigate('/notifications'); }}>View all</Button>
        </Box>
      </Popover>
    </>
  );
}
