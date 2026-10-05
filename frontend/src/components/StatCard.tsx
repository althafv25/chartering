import type { ReactNode } from 'react';
import { alpha } from '@mui/material/styles';
import { Avatar, Card, CardContent, Stack, Typography, useTheme } from '@mui/material';

export function StatCard({ label, value, icon, hint, tone = 'primary' }: {
  label: string;
  value: ReactNode;
  icon: ReactNode;
  hint?: string;
  tone?: 'primary' | 'secondary' | 'success' | 'warning' | 'error';
}) {
  const theme = useTheme();
  const toneColor = theme.palette[tone].main;

  return (
    <Card sx={{
      height: '100%',
      position: 'relative',
      overflow: 'hidden',
      '&::before': { content: '""', position: 'absolute', inset: '0 auto 0 0', width: 4, bgcolor: `${tone}.main` },
    }}>
      <CardContent sx={{ p: 2.5, '&:last-child': { pb: 2.5 } }}>
        <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={2}>
          <Stack spacing={0.75} sx={{ minWidth: 0 }}>
            <Typography variant="body2" color="text.secondary" fontWeight={600} sx={{ letterSpacing: '0.02em' }}>{label}</Typography>
            <Typography variant="h4" sx={{ mt: 0.15, fontVariantNumeric: 'tabular-nums' }}>{value}</Typography>
            {hint && <Typography variant="caption" color="text.secondary" sx={{ lineHeight: 1.45 }}>{hint}</Typography>}
          </Stack>
          <Avatar variant="rounded" sx={{ bgcolor: alpha(toneColor, 0.12), color: toneColor, width: 44, height: 44, flexShrink: 0 }}>{icon}</Avatar>
        </Stack>
      </CardContent>
    </Card>
  );
}
