import { useSnackbar } from 'notistack';
import { useCallback } from 'react';
import { errorMessage } from '../api/client';

export function useNotify() {
  const { enqueueSnackbar } = useSnackbar();

  return {
    success: useCallback((msg: string) => enqueueSnackbar(msg, { variant: 'success' }), [enqueueSnackbar]),
    info: useCallback((msg: string) => enqueueSnackbar(msg, { variant: 'info' }), [enqueueSnackbar]),
    error: useCallback((err: unknown, fallback?: string) => enqueueSnackbar(errorMessage(err, fallback), { variant: 'error' }), [enqueueSnackbar]),
  };
}
