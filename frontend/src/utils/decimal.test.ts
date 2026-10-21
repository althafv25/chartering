import { days, groupDigits, isDecimal, isNegative, money, quantity, rate, trimZeros } from './decimal';

describe('decimal display helpers', () => {
  it('groups digits without float conversion', () => {
    expect(groupDigits('1234567.50')).toBe('1,234,567.50');
    expect(groupDigits('-9876543210.123456')).toBe('-9,876,543,210.123456');
    expect(groupDigits('999')).toBe('999');
    expect(groupDigits('99999999999999999.99')).toBe('99,999,999,999,999,999.99'); // beyond float precision
    expect(groupDigits(null)).toBe('—');
  });

  it('formats money with currency', () => {
    expect(money('712500.00', 'USD')).toBe('712,500.00 USD');
    expect(money(null, 'USD')).toBe('—');
  });

  it('trims zeros and rounds days for display', () => {
    expect(trimZeros('12.500000')).toBe('12.5');
    expect(trimZeros('4.000000')).toBe('4');
    expect(days('4.203125')).toBe('4.2');
    expect(days('12.416667', 2)).toBe('12.42');
    expect(days('0.995', 2)).toBe('1');
    expect(days('16.5')).toBe('16.5');
  });

  it('detects negatives and validates decimals', () => {
    expect(isNegative('-0.01')).toBe(true);
    expect(isNegative('-0.00')).toBe(false);
    expect(isNegative('5')).toBe(false);
    expect(isDecimal('12.34', 2)).toBe(true);
    expect(isDecimal('12.345', 2)).toBe(false);
    expect(isDecimal('-1', 2)).toBe(false);
    expect(isDecimal('1e5', 2)).toBe(false);
  });
});

describe('rate and quantity display', () => {
  it('shows rates with at least two decimals and keeps significant precision', () => {
    expect(rate('55.0000')).toBe('55.00');
    expect(rate('4.6231')).toBe('4.6231');
    expect(rate('1200')).toBe('1,200.00');
    expect(rate('0.5000')).toBe('0.50');
    expect(rate(null)).toBe('—');
  });

  it('drops trailing zeros from quantities', () => {
    expect(quantity('2000.000')).toBe('2,000');
    expect(quantity('2500.500')).toBe('2,500.5');
    expect(quantity('')).toBe('—');
  });
});
