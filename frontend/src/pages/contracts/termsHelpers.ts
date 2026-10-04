import { isDecimal } from '../../utils/decimal';
import type { Contract, ContractClause, ContractRate } from '../../types/contracts';

export const blankRate = (currency: string): ContractRate => ({
  rate_type: 'day_rate', offshore_activity_type_id: null, description: null, amount: '', currency, unit: 'per_day', effective_from: null, effective_to: null, notes: null,
});

export const ratesValid = (rates: ContractRate[]) => rates.every((r) => isDecimal(r.amount, 4) && !!r.unit);
export const clausesValid = (clauses: ContractClause[]) => clauses.every((c) => c.title.trim() !== '' && c.body.trim() !== '');

/** Rates/clauses of the contract's current version, stripped to editable fields. */
export const currentRates = (c: Contract): ContractRate[] => (c.rates ?? []).filter((r) => r.version_no === c.current_version)
  .map((r) => ({ rate_type: r.rate_type, offshore_activity_type_id: r.offshore_activity_type_id, description: r.description, amount: r.amount, currency: r.currency,
    unit: r.unit, effective_from: r.effective_from, effective_to: r.effective_to, notes: r.notes }));

export const currentClauses = (c: Contract): ContractClause[] => (c.clauses ?? []).filter((x) => x.version_no === c.current_version)
  .map((x) => ({ clause_ref: x.clause_ref, title: x.title, body: x.body }));
