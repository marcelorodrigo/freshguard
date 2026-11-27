import { describe, expect, it } from 'vitest';

import { formatDate } from './format';

describe('formatDate', () => {
  it('renders a date as d-m-Y', () => {
    expect(formatDate('2026-02-01')).toBe('01-02-2026');
  });

  it('drops the time from a timestamp', () => {
    expect(formatDate('2026-01-24T08:27:26Z')).toBe('24-01-2026');
  });

  it('renders an empty value as an empty string', () => {
    expect(formatDate('')).toBe('');
    expect(formatDate(null)).toBe('');
    expect(formatDate(undefined)).toBe('');
  });

  it('leaves an unrecognized value alone', () => {
    expect(formatDate('tomorrow')).toBe('tomorrow');
  });
});
