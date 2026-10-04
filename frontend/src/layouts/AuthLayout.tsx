import { Outlet } from 'react-router-dom';
import { Box, Paper, Stack, Typography } from '@mui/material';
import AnchorIcon from '@mui/icons-material/Anchor';
import { env } from '../config/env';

export default function AuthLayout() {
  return (
    <Box sx={{
      minHeight: '100vh', display: 'grid', placeItems: 'center', p: 2,
      background: 'linear-gradient(135deg, #062639 0%, #0b3d5c 55%, #1f8fb8 100%)',
    }}>
      <Paper sx={{ width: '100%', maxWidth: 420, p: { xs: 3, sm: 4 }, borderRadius: 3 }} elevation={8}>
        <Stack direction="row" spacing={1.25} alignItems="center" sx={{ mb: 3 }}>
          <AnchorIcon color="secondary" />
          <Box>
            <Typography fontWeight={700} lineHeight={1.1}>{env.appName}</Typography>
            <Typography variant="caption" color="text.secondary">Chartering & Vessel Operations</Typography>
          </Box>
        </Stack>
        <Outlet />
      </Paper>
    </Box>
  );
}
