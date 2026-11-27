import { describe, expect, it } from 'vitest';

import { ApiError } from './api';
import { errorMessageKey } from './errorMessages';

describe('errorMessageKey', () => {
  it('maps a missing endpoint to its own message', () => {
    expect(errorMessageKey(new ApiError(404, 'not_found', 'no such endpoint'))).toBe(
      'errors.not_found',
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
