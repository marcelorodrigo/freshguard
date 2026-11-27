import { ApiError } from './api';
import type { MessageSchema } from './locales/en';

/** A message key under `errors`, the only branch error text comes from. */
export type ErrorMessageKey = `errors.${keyof MessageSchema['errors']}`;

/** API error codes that have a specific sentence instead of the generic one. */
const API_ERROR_KEYS: Partial<Record<string, ErrorMessageKey>> = {
  not_found: 'errors.not_found',
  unauthorized: 'errors.unauthorized',
  forbidden: 'errors.forbidden',
  must_reset: 'errors.must_reset',
  rate_limited: 'errors.rate_limited',
  conflict: 'errors.conflict',
};

/** Rule tokens the server puts in `fields`, from docs/api.md. */
const FIELD_RULES = [
  'required',
  'max',
  'min',
  'email',
  'unique',
  'mismatch',
  'date',
  'exists',
  'cycle',
  'invalid_input',
] as const;

type FieldRule = (typeof FIELD_RULES)[number];

/**
 * Maps a failure to a localized message. The server answers rule codes, never
 * prose, so the screen picks the sentence; anything unrecognized is generic.
 */
export function errorMessageKey(failure: unknown): ErrorMessageKey {
  if (failure instanceof ApiError) {
    return API_ERROR_KEYS[failure.code] ?? 'errors.unexpected';
  }

  return 'errors.transport';
}

/** Maps a validation rule token to the sentence shown under its field. */
export function fieldErrorKey(rule: string): ErrorMessageKey {
  const known = FIELD_RULES.includes(rule as FieldRule);
  return known ? `errors.${rule as FieldRule}` : 'errors.invalid_input';
}
