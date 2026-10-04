import type { ReactNode } from 'react';
import { Alert, AlertTitle, Box, Button, CircularProgress, Stack, Typography } from '@mui/material';
import InboxOutlinedIcon from '@mui/icons-material/InboxOutlined';
import { errorMessage } from '../api/client';

export function FullPageLoader() {
  return (
    <Box sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center' }} role="status" aria-label="Loading">
      <CircularProgress />
    </Box>
  );
}

export function SectionLoader({ label = 'Loading…' }: { label?: string }) {
  return (
    <Stack alignItems="center" justifyContent="center" spacing={1.5} sx={{ py: 8 }} role="status">
      <CircularProgress size={28} />
      <Typography variant="body2" color="text.secondary">{label}</Typography>
    </Stack>
  );
}

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <Stack alignItems="center" spacing={1} sx={{ py: 8, px: 2, textAlign: 'center' }}>
      <InboxOutlinedIcon sx={{ fontSize: 44, color: 'text.disabled' }} />
      <Typography variant="subtitle1" fontWeight={600}>{title}</Typography>
      {description && <Typography variant="body2" color="text.secondary" maxWidth={420}>{description}</Typography>}
      {action}
    </Stack>
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <Alert severity="error" sx={{ m: 2 }} action={onRetry && <Button color="inherit" size="small" onClick={onRetry}>Retry</Button>}>
      <AlertTitle>Could not load data</AlertTitle>
      {errorMessage(error)}
    </Alert>
  );
}
