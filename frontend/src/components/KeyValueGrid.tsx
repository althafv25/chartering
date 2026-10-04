import type { ReactNode } from 'react';
import { Box, Grid, Typography } from '@mui/material';

/** Read-only label/value grid for detail pages. */
export function KeyValueGrid({ items, columns = 3 }: { items: [string, ReactNode][]; columns?: 2 | 3 | 4 }) {
  return (
    <Grid container spacing={2}>
      {items.map(([label, value]) => (
        <Grid key={label} size={{ xs: 12, sm: 6, md: 12 / columns }}>
          <Box>
            <Typography variant="caption" color="text.secondary" fontWeight={600} sx={{ textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</Typography>
            <Typography fontSize={14} sx={{ wordBreak: 'break-word' }}>{value === null || value === undefined || value === '' ? '—' : value}</Typography>
          </Box>
        </Grid>
      ))}
    </Grid>
  );
}
