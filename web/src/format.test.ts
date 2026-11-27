import { describe, expect, it } from 'vitest';

import { expiryStatus, formatDate, formatDateSlashed, formatDateTime } from './format';

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

describe('formatDateTime', () => {
  it('renders a timestamp as d-m-Y H:i', () => {
    expect(formatDateTime('2026-01-24T08:27:26Z')).toBe('24-01-2026 08:27');
  });

  it('renders a date without a time as the date alone', () => {
    expect(formatDateTime('2026-02-01')).toBe('2026-02-01');
  });

  it('renders an empty value as an empty string', () => {
    expect(formatDateTime('')).toBe('');
    expect(formatDateTime(null)).toBe('');
  });
});

describe('formatDateSlashed', () => {
  it('renders a date as d/m/Y for Quick Consume', () => {
    expect(formatDateSlashed('2026-02-01')).toBe('01/02/2026');
  });

  it('drops the time from a timestamp', () => {
    expect(formatDateSlashed('2026-01-24T08:27:26Z')).toBe('24/01/2026');
  });

  it('renders an empty value as an empty string', () => {
    expect(formatDateSlashed('')).toBe('');
    expect(formatDateSlashed(null)).toBe('');
    expect(formatDateSlashed(undefined)).toBe('');
  });

  it('leaves an unrecognized value alone', () => {
    expect(formatDateSlashed('tomorrow')).toBe('tomorrow');
  });
});

describe('expiryStatus', () => {
  const today = new Date(2026, 0, 24, 15, 30);

  it('marks a date before today as past', () => {
    expect(expiryStatus('2026-01-23', today)).toBe('past');
  });

  it('marks today and the next seven days as soon', () => {
    expect(expiryStatus('2026-01-24', today)).toBe('soon');
    expect(expiryStatus('2026-01-25', today)).toBe('soon');
    expect(expiryStatus('2026-01-31', today)).toBe('soon');
  });

  it('marks the eighth day and later as later', () => {
    expect(expiryStatus('2026-02-01', today)).toBe('later');
  });

  it('marks a missing or unusable date as none', () => {
    expect(expiryStatus('', today)).toBe('none');
    expect(expiryStatus(null, today)).toBe('none');
    expect(expiryStatus('tomorrow', today)).toBe('none');
  });
});
