/** The year, month and day of an API date, or null when it is not one. */
function dateParts(value: string): readonly [string, string, string] | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (match === null) {
    return null;
  }

  return [match[1] ?? '', match[2] ?? '', match[3] ?? ''];
}

/** Renders an API date with the given separator, or returns it unchanged. */
function renderDate(
  value: string | null | undefined,
  separator: '-' | '/',
): string {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  const parts = dateParts(value);
  if (parts === null) {
    return value;
  }

  const [year, month, day] = parts;
  return `${day}${separator}${month}${separator}${year}`;
}

/** Renders an API date or timestamp as the table format `d-m-Y`. */
export function formatDate(value: string | null | undefined): string {
  return renderDate(value, '-');
}

/** Renders an API date as Quick Consume's `d/m/Y`, which differs from tables. */
export function formatDateSlashed(value: string | null | undefined): string {
  return renderDate(value, '/');
}

/** How Quick Consume colours an expiry date. */
export type ExpiryStatus = 'none' | 'past' | 'soon' | 'later';

/**
 * Classifies an expiry date against today: before today is `past`, today
 * through seven days ahead is `soon`, anything later is `later`. A missing or
 * unusable date is `none`; the legacy screen showed a question mark for it.
 */
export function expiryStatus(
  value: string | null | undefined,
  today: Date = new Date(),
): ExpiryStatus {
  if (value === null || value === undefined || value === '') {
    return 'none';
  }

  const parts = dateParts(value);
  if (parts === null) {
    return 'none';
  }

  const expires = Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
  const start = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
  const days = Math.round((expires - start) / 86_400_000);

  if (days < 0) {
    return 'past';
  }
  if (days <= 7) {
    return 'soon';
  }
  return 'later';
}

/** Renders an API timestamp as `d-m-Y H:i`, dropping the seconds and zone. */
export function formatDateTime(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(value);
  if (match === null) {
    return value;
  }

  const year = match[1] ?? '';
  const month = match[2] ?? '';
  const day = match[3] ?? '';
  const hours = match[4] ?? '';
  const minutes = match[5] ?? '';
  return `${day}-${month}-${year} ${hours}:${minutes}`;
}
