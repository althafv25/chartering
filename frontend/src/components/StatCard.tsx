import type { ReactNode } from 'react';
import { Avatar, Card, CardContent, Stack, Typography } from '@mui/material';

export function StatCard({ label, value, icon, hint, tone = 'primary' }: {
  label: string;
  value: ReactNode;
  icon: ReactNode;
  hint?: string;
  tone?: 'primary' | 'secondary' | 'success' | 'warning' | 'error';
}) {
  return (
    <Card sx={{ height: '100%' }}>
      <CardContent>
        <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={2}>
          <div>
            <Typography variant="body2" color="text.secondary" fontWeight={500}>{label}</Typography>
            <Typography variant="h5" sx={{ mt: 0.75 }}>{value}</Typography>
            {hint && <Typography variant="caption" color="text.secondary">{hint}</Typography>}
          </div>
          <Avatar variant="rounded" sx={{ bgcolor: `${tone}.main`, color: '#fff', width: 40, height: 40 }}>{icon}</Avatar>
        </Stack>
      </CardContent>
    </Card>
  );
}
