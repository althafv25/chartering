import { alpha, createTheme } from '@mui/material/styles';

/** Maritime palette: deep navy primary, ocean-teal secondary. */
const navy = '#0b3d5c';
const teal = '#1f8fb8';

export const theme = createTheme({
  palette: {
    mode: 'light',
    primary: { main: navy, light: '#2d6185', dark: '#062639', contrastText: '#fff' },
    secondary: { main: teal, contrastText: '#fff' },
    success: { main: '#2e7d5b' },
    warning: { main: '#c77700' },
    error: { main: '#c62828' },
    background: { default: '#f4f6f9', paper: '#ffffff' },
    text: { primary: '#0f1d2b', secondary: '#55657a' },
    divider: '#e3e8ef',
  },
  shape: { borderRadius: 10 },
  typography: {
    fontFamily: '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    h4: { fontWeight: 700, fontSize: '1.6rem', letterSpacing: '-0.01em' },
    h5: { fontWeight: 700, fontSize: '1.25rem' },
    h6: { fontWeight: 600, fontSize: '1.05rem' },
    subtitle2: { fontWeight: 600 },
    button: { textTransform: 'none', fontWeight: 600 },
  },
  components: {
    MuiButton: { defaultProps: { disableElevation: true } },
    MuiCard: {
      defaultProps: { variant: 'outlined' },
      styleOverrides: { root: { borderColor: '#e3e8ef' } },
    },
    MuiPaper: { styleOverrides: { outlined: { borderColor: '#e3e8ef' } } },
    MuiTextField: { defaultProps: { size: 'small', fullWidth: true } },
    MuiSelect: { defaultProps: { size: 'small' } },
    MuiTableCell: {
      styleOverrides: {
        head: { fontWeight: 600, color: '#55657a', backgroundColor: '#f8fafc', whiteSpace: 'nowrap' },
      },
    },
    MuiTableRow: {
      styleOverrides: { root: ({ theme: t }) => ({ '&.MuiTableRow-hover:hover': { backgroundColor: alpha(t.palette.primary.main, 0.03) } }) },
    },
    MuiChip: { styleOverrides: { root: { fontWeight: 600 } } },
    MuiDialogTitle: { styleOverrides: { root: { fontWeight: 700 } } },
  },
});
