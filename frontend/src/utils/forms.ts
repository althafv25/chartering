import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import { ApiError } from '../api/client';

/** Maps Laravel 422 field errors onto react-hook-form fields. Returns true if mapped. */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>): boolean {
  if (!(error instanceof ApiError) || error.code !== 'validation_failed') return false;
  let mapped = false;
  for (const [field, messages] of Object.entries(error.fieldErrors)) {
    setError(field.split('.')[0] as Path<T>, { type: 'server', message: messages[0] });
    mapped = true;
  }
  return mapped;
}
