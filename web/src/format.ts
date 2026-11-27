/** Renders an API date or timestamp as the table format `d-m-Y`. */
export function formatDate(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (match === null) {
    return value;
  }

  const year = match[1] ?? '';
  const month = match[2] ?? '';
  const day = match[3] ?? '';
  return `${day}-${month}-${year}`;
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
