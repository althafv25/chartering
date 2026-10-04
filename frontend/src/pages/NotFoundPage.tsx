import { Link as RouterLink } from 'react-router-dom';
import { Button, Stack, Typography } from '@mui/material';

export default function NotFoundPage() {
  return (
    <Stack alignItems="center" spacing={2} sx={{ py: 10, textAlign: 'center' }}>
      <Typography variant="h3" component="p" color="text.disabled" fontWeight={700}>404</Typography>
      <Typography variant="h5" component="h1">Page not found</Typography>
      <Button component={RouterLink} to="/" variant="outlined">Back to dashboard</Button>
    </Stack>
  );
}
