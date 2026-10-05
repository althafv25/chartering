import { alpha, createTheme } from '@mui/material/styles';
import { surfaceShadows } from './surfaces';

/** Maritime palette: deep navy primary, ocean-teal secondary. */
const navy = '#0b3d5c';
const teal = '#1f8fb8';
const border = '#e8edf3';

export const theme = createTheme({
  palette: {
    mode: 'light',
    primary: { main: navy, light: '#2d6185', dark: '#062639', contrastText: '#fff' },
    secondary: { main: teal, contrastText: '#fff' },
    success: { main: '#2e7d5b' },
    warning: { main: '#c77700' },
    error: { main: '#c62828' },
    background: { default: '#ffffff', paper: '#ffffff' },
    text: { primary: '#172b40', secondary: '#64748b' },
    divider: border,
    action: { hover: alpha(navy, 0.035), selected: alpha(teal, 0.08), focus: alpha(teal, 0.12) },
  },
  shape: { borderRadius: 10 },
  typography: {
    fontFamily: '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    h4: { fontWeight: 700, fontSize: 'clamp(1.5rem, 2vw, 1.9rem)', letterSpacing: '-0.035em', lineHeight: 1.3 },
    h5: { fontWeight: 700, fontSize: '1.35rem', letterSpacing: '-0.025em' },
    h6: { fontWeight: 600, fontSize: '1.05rem', letterSpacing: '-0.015em' },
    body1: { fontSize: '0.9375rem', lineHeight: 1.65 },
    body2: { fontSize: '0.875rem', lineHeight: 1.6 },
    subtitle2: { fontWeight: 600 },
    button: { textTransform: 'none', fontWeight: 600 },
  },
  components: {
    MuiCssBaseline: {
      styleOverrides: {
        body: { WebkitFontSmoothing: 'antialiased' },
        '#root': { minHeight: '100vh' },
        '::selection': { backgroundColor: alpha(teal, 0.18), color: navy },
      },
    },
    MuiButton: {
      defaultProps: { disableElevation: true },
      styleOverrides: {
        root: { borderRadius: 10, minHeight: 40, padding: '8px 18px', fontSize: '0.875rem' },
        sizeSmall: { minHeight: 32, padding: '5px 12px', fontSize: '0.8125rem' },
        sizeLarge: { minHeight: 46, padding: '11px 22px' },
        contained: { boxShadow: surfaceShadows.subtle, '&:hover': { boxShadow: surfaceShadows.card } },
      },
    },
    MuiIconButton: {
      styleOverrides: {
        root: { borderRadius: 10, '&.Mui-focusVisible': { outline: `2px solid ${alpha(teal, 0.5)}`, outlineOffset: 2 } },
      },
    },
    MuiCard: {
      defaultProps: { variant: 'elevation', elevation: 0 },
      styleOverrides: {
        root: { backgroundColor: '#fff', border: `1px solid ${border}`, borderRadius: 16, boxShadow: surfaceShadows.card },
      },
    },
    MuiCardHeader: {
      styleOverrides: { root: { padding: '22px 24px 18px' }, title: { fontSize: '1rem', fontWeight: 600 }, action: { marginTop: 0, marginRight: 0 } },
    },
    MuiCardContent: {
      styleOverrides: { root: { padding: 24, '&:last-child': { paddingBottom: 24 } } },
    },
    MuiPaper: {
      styleOverrides: { root: { backgroundImage: 'none' }, outlined: { borderColor: border, boxShadow: surfaceShadows.subtle } },
    },
    MuiTextField: { defaultProps: { size: 'small', fullWidth: true } },
    MuiSelect: { defaultProps: { size: 'small' } },
    MuiOutlinedInput: {
      styleOverrides: {
        root: {
          backgroundColor: '#fff', borderRadius: 10, fontSize: '0.875rem',
          '&:not(.Mui-error):hover .MuiOutlinedInput-notchedOutline': { borderColor: alpha(navy, 0.35) },
          '&.Mui-focused': { boxShadow: `0 0 0 3px ${alpha(teal, 0.09)}` },
          '&.Mui-error.Mui-focused': { boxShadow: '0 0 0 3px rgba(198, 40, 40, 0.08)' },
          '&.Mui-disabled': { backgroundColor: '#f8fafc' },
        },
        notchedOutline: { borderColor: '#dce4ec' },
      },
    },
    MuiInputLabel: { styleOverrides: { root: { fontSize: '0.875rem' } } },
    MuiTableCell: {
      styleOverrides: {
        root: { borderColor: border, fontSize: '0.875rem' },
        head: { fontWeight: 600, fontSize: '0.75rem', letterSpacing: '0.045em', textTransform: 'uppercase', color: '#64748b', backgroundColor: '#f9fbfd', whiteSpace: 'nowrap', paddingTop: 14, paddingBottom: 14 },
        body: { fontVariantNumeric: 'tabular-nums' },
      },
    },
    MuiTableRow: {
      styleOverrides: { root: { '&.MuiTableRow-hover:hover': { backgroundColor: alpha(teal, 0.035) } } },
    },
    MuiTablePagination: {
      styleOverrides: { root: { borderTop: `1px solid ${border}` }, toolbar: { minHeight: 64 }, selectLabel: { fontSize: '0.8125rem' }, displayedRows: { fontSize: '0.8125rem', color: '#64748b' } },
    },
    MuiTabs: { styleOverrides: { root: { minHeight: 54 }, indicator: { height: 3, borderRadius: 3 } } },
    MuiTab: {
      styleOverrides: { root: { minHeight: 54, padding: '14px 20px', textTransform: 'none', fontWeight: 600, fontSize: '0.875rem', '&:hover': { backgroundColor: alpha(teal, 0.035) } } },
    },
    MuiChip: {
      styleOverrides: {
        root: { fontWeight: 600, borderRadius: 8 },
        outlined: ({ theme: t, ownerState }) => {
          const color = ownerState.color && ownerState.color !== 'default' ? t.palette[ownerState.color].main : t.palette.text.secondary;
          return { backgroundColor: alpha(color, 0.055), borderColor: alpha(color, 0.18) };
        },
      },
    },
    MuiMenu: { styleOverrides: { paper: { border: `1px solid ${border}`, borderRadius: 14, boxShadow: surfaceShadows.popover, marginTop: 8 }, list: { padding: 6 } } },
    MuiMenuItem: { styleOverrides: { root: { borderRadius: 8, fontSize: '0.875rem', minHeight: 38 } } },
    MuiPopover: { styleOverrides: { paper: { border: `1px solid ${border}`, borderRadius: 14, boxShadow: surfaceShadows.popover } } },
    MuiAutocomplete: { styleOverrides: { paper: { border: `1px solid ${border}`, borderRadius: 12, boxShadow: surfaceShadows.popover } } },
    MuiAlert: { styleOverrides: { root: { borderRadius: 12, fontSize: '0.875rem' } } },
    MuiBackdrop: {
      styleOverrides: { root: { '&:not(.MuiBackdrop-invisible)': { backgroundColor: alpha(navy, 0.22), backdropFilter: 'blur(2px)' } } },
    },
  },
});
