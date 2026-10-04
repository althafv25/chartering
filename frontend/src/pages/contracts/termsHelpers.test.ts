import { clausesValid, currentClauses, currentRates, ratesValid } from './termsHelpers';
import type { Contract } from '../../types/contracts';

const contract = {
  current_version: 2,
  rates: [
    { id: 1, version_no: 1, rate_type: 'day_rate', offshore_activity_type_id: null, description: null, amount: '14500.0000', currency: 'USD', unit: 'per_day', effective_from: null, effective_to: null, notes: null },
    { id: 2, version_no: 2, rate_type: 'day_rate', offshore_activity_type_id: null, description: 'Escalated', amount: '15000.0000', currency: 'USD', unit: 'per_day', effective_from: null, effective_to: null, notes: null },
  ],
  clauses: [{ id: 1, version_no: 1, sequence: 1, clause_ref: '1', title: 'Old', body: 'x' }, { id: 2, version_no: 2, sequence: 1, clause_ref: '1', title: 'New', body: 'y' }],
} as unknown as Contract;

describe('contract terms helpers', () => {
  it('returns only the current version, without ids', () => {
    const r = currentRates(contract);
    expect(r).toHaveLength(1);
    expect(r[0].amount).toBe('15000.0000');
    expect(r[0]).not.toHaveProperty('id');
    expect(currentClauses(contract).map((c) => c.title)).toEqual(['New']);
  });

  it('validates amounts as decimal strings and clauses as non-empty', () => {
    expect(ratesValid(currentRates(contract))).toBe(true);
    expect(ratesValid([{ ...currentRates(contract)[0], amount: '1.23456' }])).toBe(false);
    expect(ratesValid([{ ...currentRates(contract)[0], amount: '' }])).toBe(false);
    expect(clausesValid([{ clause_ref: null, title: ' ', body: 'x' }])).toBe(false);
  });
});
