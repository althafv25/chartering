import type { ReactNode } from 'react';
import { Box, Breadcrumbs, Link, Stack, Typography } from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import NavigateNextIcon from '@mui/icons-material/NavigateNext';

export interface Crumb { label: string; to?: string }

export function PageHeader({ title, subtitle, breadcrumbs, actions }: {
  title: string;
  subtitle?: string;
  breadcrumbs?: Crumb[];
  actions?: ReactNode;
}) {
  return (
    <Box sx={{ mb: 3 }}>
      {breadcrumbs && breadcrumbs.length > 0 && (
        <Breadcrumbs separator={<NavigateNextIcon fontSize="small" />} sx={{ mb: 1, fontSize: 13 }}>
          {breadcrumbs.map((c) => c.to
            ? <Link key={c.label} component={RouterLink} to={c.to} underline="hover" color="text.secondary">{c.label}</Link>
            : <Typography key={c.label} color="text.primary" fontSize={13}>{c.label}</Typography>)}
        </Breadcrumbs>
      )}
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} spacing={2}>
        <Box>
          <Typography variant="h4" component="h1">{title}</Typography>
          {subtitle && <Typography color="text.secondary" sx={{ mt: 0.5 }}>{subtitle}</Typography>}
        </Box>
        {actions && <Stack direction="row" spacing={1} flexShrink={0}>{actions}</Stack>}
      </Stack>
    </Box>
  );
}
