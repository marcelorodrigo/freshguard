import { describe, expect, it } from 'vitest';

import { ApiError } from './api';
import { errorMessageKey, fieldErrorKey } from './errorMessages';

describe('errorMessageKey', () => {
  it('maps a missing endpoint to its own message', () => {
    expect(errorMessageKey(new ApiError(404, 'not_found', 'no such endpoint'))).toBe(
      'errors.not_found',
    );
  });

  it('maps auth and rate-limit codes to their own messages', () => {
    expect(errorMessageKey(new ApiError(401, 'unauthorized', 'sign in'))).toBe(
      'errors.unauthorized',
    );
    expect(errorMessageKey(new ApiError(429, 'rate_limited', 'slow down'))).toBe(
      'errors.rate_limited',
    );
  });

  it('maps every other API error to the generic message', () => {
    expect(errorMessageKey(new ApiError(500, 'internal', 'boom'))).toBe(
      'errors.unexpected',
    );
  });

  it('maps a failed request to the transport message', () => {
    expect(errorMessageKey(new TypeError('fetch failed'))).toBe('errors.transport');
  });
});

describe('fieldErrorKey', () => {
  it('maps rule tokens to their sentence', () => {
    expect(fieldErrorKey('required')).toBe('errors.required');
    expect(fieldErrorKey('unique')).toBe('errors.unique');
  });

  it('falls back to the generic validation sentence', () => {
    expect(fieldErrorKey('something_new')).toBe('errors.invalid_input');
  });
});
