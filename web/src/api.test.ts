import { afterEach, describe, expect, it, vi } from 'vitest';

import { ApiError, bulkDeleteItems, getHealth, login, logout } from './api';

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}

const user = {
  id: '0190a1b2-0000-7000-8000-000000000001',
  name: 'Marcelo',
  email: 'm@example.com',
  is_admin: true,
  must_reset: false,
  created_at: '2026-01-24T08:27:26Z',
  updated_at: '2026-01-24T08:27:26Z',
};

describe('getHealth', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('returns the health payload', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        status: 'ok',
        version: '1.0.0',
        setup_required: true,
        time: '2026-01-24T08:27:26Z',
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(getHealth()).resolves.toEqual({
      status: 'ok',
      version: '1.0.0',
      setup_required: true,
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

describe('auth requests', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('posts the credentials and returns the signed-in user', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ user }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(login({ email: 'm@example.com', password: 'secret' })).resolves.toEqual({
      user,
    });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/session',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ email: 'm@example.com', password: 'secret' }),
        headers: expect.objectContaining({ 'Content-Type': 'application/json' }),
      }),
    );
  });

  it('keeps validation rule tokens on the error', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(
          {
            error: {
              code: 'invalid_input',
              message: 'invalid input',
              fields: { email: 'required' },
            },
          },
          422,
        ),
      ),
    );

    await expect(login({ email: '', password: '' })).rejects.toMatchObject({
      status: 422,
      code: 'invalid_input',
      fields: { email: 'required' },
    });
  });

  it('accepts the empty body of a 204 logout', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response(null, { status: 204 })),
    );

    await expect(logout()).resolves.toBeUndefined();
  });
});

describe('bulkDeleteItems', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('posts the ids and returns the deleted count', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ deleted: 2 }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(bulkDeleteItems(['a', 'b'])).resolves.toEqual({ deleted: 2 });
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/items/bulk-delete',
      expect.objectContaining({ method: 'POST', body: '{"ids":["a","b"]}' }),
    );
  });
});
