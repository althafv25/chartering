import { fromIso, toOffsetIso } from './reportTime';

describe('captain report ship time', () => {
  it('composes ISO with the ship offset', () => {
    expect(toOffsetIso('2026-10-15T12:00', '+04:00')).toBe('2026-10-15T12:00:00+04:00');
  });
  it('re-expresses UTC in ship time', () => {
    expect(fromIso('2026-10-15T08:00:00+00:00', '+04:00')).toBe('2026-10-15T12:00');
    expect(fromIso('2026-10-15T02:00:00+00:00', '-05:00')).toBe('2026-10-14T21:00');
  });
});
