import { createContext, useContext, useId, type ReactNode } from 'react';
import { Drawer, IconButton, Stack, Typography, dialogActionsClasses, dialogContentClasses, useTheme, type Breakpoint } from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';

interface DrawerDialogProps {
  open: boolean;
  children: ReactNode;
  onClose?: () => void;
  maxWidth?: Breakpoint | false;
  /** Accepted by existing dialog layouts; drawers always fill their configured width. */
  fullWidth?: boolean;
  width?: number;
  closeLabel?: string;
  'aria-labelledby'?: string;
  'aria-describedby'?: string;
}

const DrawerDialogContext = createContext<{
  titleId: string;
  onClose?: () => void;
  closeLabel: string;
} | null>(null);

/** Right-side dialog shell. Reuses MUI content/actions and supports a wrapping form. */
export function DrawerDialog({ open, children, onClose, maxWidth = 'sm', width, closeLabel = 'Close panel',
  'aria-labelledby': labelledBy, 'aria-describedby': describedBy }: DrawerDialogProps) {
  const generatedId = useId();
  const titleId = labelledBy ?? generatedId;
  const theme = useTheme();
  const panelWidth = width ?? (maxWidth === false ? '100%' : theme.breakpoints.values[maxWidth] || 440);

  return (
    <Drawer anchor="right" open={open} onClose={onClose}
      slotProps={{ paper: {
        role: 'dialog',
        'aria-modal': true,
        'aria-labelledby': titleId,
        'aria-describedby': describedBy,
        sx: {
          width: { xs: '100%', sm: panelWidth }, maxWidth: '100%', height: '100dvh', overflow: 'hidden',
          // Keep header and actions visible for both direct children and native form layouts.
          '& > form': { display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0 },
          [`& .${dialogContentClasses.root}`]: { flex: 1, minHeight: 0, overflow: 'auto', border: 0, p: { xs: 2, sm: 3 } },
          [`& .${dialogActionsClasses.root}`]: {
            flexShrink: 0, flexWrap: 'wrap', gap: 1, px: { xs: 2, sm: 3 }, py: 2,
            borderTop: 1, borderColor: 'divider',
            '& > :not(style) ~ :not(style)': { ml: 0 },
          },
        },
      } }}>
      <DrawerDialogContext.Provider value={{ titleId, onClose, closeLabel }}>
        {children}
      </DrawerDialogContext.Provider>
    </Drawer>
  );
}

export function DrawerDialogTitle({ children, id }: { children: ReactNode; id?: string }) {
  const context = useContext(DrawerDialogContext);
  if (!context) throw new Error('DrawerDialogTitle must be used inside DrawerDialog');

  return (
    <Stack direction="row" alignItems="center" spacing={2}
      sx={{ px: { xs: 2, sm: 3 }, py: 2, borderBottom: 1, borderColor: 'divider', flexShrink: 0 }}>
      <Typography id={id ?? context.titleId} variant="h6" component="h2"
        sx={{ flex: 1, minWidth: 0, overflowWrap: 'anywhere' }}>{children}</Typography>
      <IconButton type="button" onClick={context.onClose} disabled={!context.onClose} aria-label={context.closeLabel}>
        <CloseIcon />
      </IconButton>
    </Stack>
  );
}
