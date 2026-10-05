import { Button, DialogActions, DialogContent, DialogContentText } from '@mui/material';
import { DrawerDialog as Dialog, DrawerDialogTitle as DialogTitle } from './DrawerDialog';
import { LoadingButton } from './LoadingButton';

export function ConfirmDialog({ open, title, message, confirmLabel = 'Confirm', danger, loading, onConfirm, onClose }: {
  open: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  danger?: boolean;
  loading?: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  return (
    <Dialog open={open} onClose={loading ? undefined : onClose} maxWidth="xs" fullWidth>
      <DialogTitle>{title}</DialogTitle>
      <DialogContent>
        <DialogContentText>{message}</DialogContentText>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={loading}>Cancel</Button>
        <LoadingButton variant="contained" color={danger ? 'error' : 'primary'} loading={loading} onClick={onConfirm}>
          {confirmLabel}
        </LoadingButton>
      </DialogActions>
    </Dialog>
  );
}
