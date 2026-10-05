import { useId, type ReactNode } from 'react';
import { Box, Chip, Stack, Typography } from '@mui/material';
import { alpha } from '@mui/material/styles';
import { surfaceShadows } from '../theme/surfaces';

/** Shared section treatment for estimation inputs and operational forms. */
export function FormSection({ title, hint, icon, count, action, children }: {
  title: string; hint?: string; icon: ReactNode; count?: number; action?: ReactNode; children: ReactNode;
}) {
  const headingId = useId();
  return (
    <Box component="section" aria-labelledby={headingId} sx={{ minWidth: 0, border: 1, borderColor: 'divider', borderRadius: 2.5, bgcolor: 'background.paper', boxShadow: surfaceShadows.subtle }}>
      <Stack direction="row" spacing={1.5} alignItems="flex-start" sx={{ p: { xs: 1.5, sm: 2 }, borderBottom: 1, borderColor: 'divider' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', width: 36, height: 36, flexShrink: 0, borderRadius: 1.5, color: 'primary.main', bgcolor: (t) => alpha(t.palette.primary.main, 0.06), '& .MuiSvgIcon-root': { fontSize: 20 } }}>{icon}</Box>
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
            <Typography id={headingId} component="h2" variant="subtitle2">{title}</Typography>
            {count !== undefined && <Chip label={count} size="small" sx={{ height: 22, minWidth: 26, bgcolor: 'action.hover', fontSize: 11 }} />}
          </Stack>
          {hint && <Typography variant="caption" color="text.secondary" display="block" sx={{ mt: 0.25, lineHeight: 1.5 }}>{hint}</Typography>}
        </Box>
        {action && <Box sx={{ flexShrink: 0 }}>{action}</Box>}
      </Stack>
      <Box sx={{ p: { xs: 1.5, sm: 2 }, minWidth: 0 }}>{children}</Box>
    </Box>
  );
}

export function FormLayout({ children }: { children: ReactNode }) {
  return (
    <Stack spacing={2.5} sx={{ containerType: 'inline-size', minWidth: 0, '& .MuiFormControl-root': { minWidth: 0 }, '& .MuiInputBase-root, & .MuiInputLabel-root': { fontSize: '0.8125rem' } }}>
      {children}
    </Stack>
  );
}

/** Uses the form's available width, including inside a drawer or voyage panel. */
export function FormFieldGrid({ children, columns = 2 }: { children: ReactNode; columns?: number | string }) {
  return (
    <Box sx={{
      display: 'grid', gridTemplateColumns: 'minmax(0, 1fr)', gap: 1.5, alignItems: 'start', minWidth: 0, '& > *': { minWidth: 0 },
      '@container (min-width: 320px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' },
      '@container (min-width: 560px)': { gridTemplateColumns: typeof columns === 'number' ? `repeat(${columns}, minmax(0, 1fr))` : columns },
    }}>
      {children}
    </Box>
  );
}

export function FormEmptyState({ children }: { children: ReactNode }) {
  return <Typography variant="body2" color="text.secondary" sx={{ p: 2, textAlign: 'center', border: '1px dashed', borderColor: 'divider', borderRadius: 2, bgcolor: 'action.hover' }}>{children}</Typography>;
}
