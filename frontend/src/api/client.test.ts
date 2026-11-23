import { describe, expect, it } from 'vitest';
import { ApiError, errorMessage } from './client';

describe('errorMessage', () => {
  it('shows the reasons of a business-rule error, so the user knows what to fix', () => {
    const error = new ApiError('The voyage cannot be completed yet (OP-03).', 409, 'voyage_not_completable',
      { port_calls: ['1 port call(s) not sailed or cancelled.'], captain_reports: ['1 captain report(s) still draft or awaiting verification.'] });
    expect(errorMessage(error)).toBe('The voyage cannot be completed yet (OP-03). 1 port call(s) not sailed or cancelled. 1 captain report(s) still draft or awaiting verification.');
  });

  it('returns only the first message for a validation error', () => {
    const error = new ApiError('The given data was invalid.', 422, 'validation_failed', { eta: ['Must be before ATA.'], atd: ['Required.'] });
    expect(errorMessage(error)).toBe('Must be before ATA.');
  });

  it('returns the plain message when there are no reasons', () => {
    expect(errorMessage(new ApiError('Voyage is read-only.', 409, 'voyage_read_only'))).toBe('Voyage is read-only.');
  });

  it('adds the request reference on server errors', () => {
    expect(errorMessage(new ApiError('Server error.', 500, 'server_error', {}, 'req-123'))).toBe('Server error. (Ref: req-123)');
  });
});
