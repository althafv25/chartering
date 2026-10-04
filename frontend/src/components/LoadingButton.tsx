import { Button, CircularProgress, type ButtonProps } from '@mui/material';

/** Button that disables itself and shows a spinner while `loading` (prevents double submission). */
export function LoadingButton({ loading, disabled, children, startIcon, ...props }: ButtonProps & { loading?: boolean }) {
  return (
    <Button
      {...props}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      startIcon={loading ? <CircularProgress size={16} color="inherit" /> : startIcon}
    >
      {children}
    </Button>
  );
}
