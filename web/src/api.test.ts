import { afterEach, describe, expect, it, vi } from 'vitest';

import { ApiError, getHealth } from './api';

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}

describe('getHealth', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('returns the health payload', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ status: 'ok', version: '1.0.0', time: '2026-01-24T08:27:26Z' }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(getHealth()).resolves.toEqual({
      status: 'ok',
      version: '1.0.0',
      time: '2026-01-24T08:27:26Z',
    });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/health',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
  });

  it('turns the error envelope into an ApiError', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({ error: { code: 'not_found', message: 'no such endpoint' } }, 404),
      ),
    );

    const failure = getHealth();

    await expect(failure).rejects.toBeInstanceOf(ApiError);
    await expect(failure).rejects.toMatchObject({
      status: 404,
      code: 'not_found',
      message: 'no such endpoint',
    });
  });

  it('falls back to the status when the body is not the envelope', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('gateway down', { status: 502 })),
    );

    await expect(getHealth()).rejects.toMatchObject({
      status: 502,
      code: 'unexpected',
    });
  });
});
