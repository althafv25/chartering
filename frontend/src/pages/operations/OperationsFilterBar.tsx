import type { ReactNode } from 'react';
import { Box, Button, Stack, Typography } from '@mui/material';
import FilterAltOutlined from '@mui/icons-material/FilterAltOutlined';
import { FormFieldGrid } from '../../components/FormSection';

export function OperationsFilterBar({ children, active, onClear }: { children: ReactNode; active: boolean; onClear: () => void }) {
  return (
    <Box role="region" aria-label="Filters" sx={{ p: 2, borderBottom: 1, borderColor: 'divider', bgcolor: 'action.hover', containerType: 'inline-size' }}>
      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5, minHeight: 32 }}>
        <FilterAltOutlined fontSize="small" color="primary" />
        <Typography variant="subtitle2" sx={{ flex: 1 }}>Filters</Typography>
        {active && <Button size="small" onClick={onClear}>Clear filters</Button>}
      </Stack>
      <Box sx={{ maxWidth: 740 }}><FormFieldGrid columns={3}>{children}</FormFieldGrid></Box>
    </Box>
  );
}
