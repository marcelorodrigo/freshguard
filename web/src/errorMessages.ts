import { ApiError } from './api';
import type { MessageSchema } from './locales/en';

/** A message key under `errors`, the only branch error text comes from. */
export type ErrorMessageKey = `errors.${keyof MessageSchema['errors']}`;

/**
 * Maps a failure to a localized message. The server answers rule codes, never
 * prose, so the screen picks the sentence; anything unrecognized is generic.
 */
export function errorMessageKey(failure: unknown): ErrorMessageKey {
  if (failure instanceof ApiError) {
    return failure.code === 'not_found' ? 'errors.not_found' : 'errors.unexpected';
  }

  return 'errors.transport';
}
