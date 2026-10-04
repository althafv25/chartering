import { Link as RouterLink } from 'react-router-dom';
import { Button, Stack, Typography } from '@mui/material';
import LockOutlined from '@mui/icons-material/LockOutlined';

export default function ForbiddenPage() {
  return (
    <Stack alignItems="center" spacing={2} sx={{ py: 10, textAlign: 'center' }}>
      <LockOutlined sx={{ fontSize: 56, color: 'text.disabled' }} />
      <Typography variant="h5" component="h1">Access denied</Typography>
      <Typography color="text.secondary">You don't have permission to view this page. Contact an administrator if you need access.</Typography>
      <Button component={RouterLink} to="/" variant="outlined">Back to dashboard</Button>
    </Stack>
  );
}
