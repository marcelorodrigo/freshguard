import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  ApiError,
  getHealth,
  getMe,
  login,
  logout,
  setup,
  updateMe,
  type User,
} from './api';
import {
  changePassword,
  createFirstAdmin,
  ensureSession,
  forgetSession,
  session,
  signIn,
  signOut,
} from './session';

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>();

  return {
    ...actual,
    getHealth: vi.fn(),
    getMe: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    setup: vi.fn(),
    updateMe: vi.fn(),
  };
});

const getHealthMock = vi.mocked(getHealth);
const getMeMock = vi.mocked(getMe);
const loginMock = vi.mocked(login);
const logoutMock = vi.mocked(logout);
const setupMock = vi.mocked(setup);
const updateMeMock = vi.mocked(updateMe);

function user(overrides: Partial<User> = {}): User {
  return {
    id: '0190a1b2-0000-7000-8000-000000000001',
    name: 'Marcelo',
    email: 'm@example.com',
    is_admin: true,
    must_reset: false,
    created_at: '2026-01-24T08:27:26Z',
    updated_at: '2026-01-24T08:27:26Z',
    ...overrides,
  };
}

function health(setupRequired: boolean) {
  return {
    status: 'ok',
    version: '1.0.0',
    setup_required: setupRequired,
    time: '2026-01-24T08:27:26Z',
  };
}

describe('session', () => {
  beforeEach(() => {
    session.user = null;
    session.setupRequired = false;
    session.loaded = false;
  });

  afterEach(() => {
    vi.resetAllMocks();
  });

  it('stops at setup while no account exists', async () => {
    getHealthMock.mockResolvedValue(health(true));

    await ensureSession();

    expect(getHealthMock).toHaveBeenCalledTimes(1);
    expect(getMeMock).not.toHaveBeenCalled();
    expect(session.setupRequired).toBe(true);
    expect(session.user).toBeNull();
    expect(session.loaded).toBe(true);
  });

  it('loads the signed-in account once setup is done', async () => {
    getHealthMock.mockResolvedValue(health(false));
    getMeMock.mockResolvedValue(user());

    await ensureSession();

    expect(session.user).toEqual(user());
    expect(session.setupRequired).toBe(false);
    expect(session.loaded).toBe(true);
  });

  it('treats a missing session as signed out', async () => {
    getHealthMock.mockResolvedValue(health(false));
    getMeMock.mockRejectedValue(new ApiError(401, 'unauthorized', 'sign in'));

    await ensureSession();

    expect(session.user).toBeNull();
    expect(session.loaded).toBe(true);
  });

  it('survives a transport failure and lets login report it', async () => {
    getHealthMock.mockRejectedValue(new TypeError('fetch failed'));

    await ensureSession();

    expect(session.user).toBeNull();
    expect(session.setupRequired).toBe(false);
    expect(session.loaded).toBe(true);
  });

  it('deduplicates parallel loads', async () => {
    getHealthMock.mockResolvedValue(health(false));
    getMeMock.mockResolvedValue(user());

    await Promise.all([ensureSession(), ensureSession(), ensureSession()]);

    expect(getHealthMock).toHaveBeenCalledTimes(1);
    expect(getMeMock).toHaveBeenCalledTimes(1);
  });

  it('records the admin created by setup', async () => {
    setupMock.mockResolvedValue({ user: user() });

    await expect(
      createFirstAdmin({ name: 'Marcelo', email: 'm@example.com', password: 'secret123' }),
    ).resolves.toEqual(user());

    expect(session.user).toEqual(user());
    expect(session.setupRequired).toBe(false);
    expect(session.loaded).toBe(true);
  });

  it('records the account opened by login', async () => {
    loginMock.mockResolvedValue({ user: user({ must_reset: true }) });

    await expect(signIn('m@example.com', 'secret123')).resolves.toEqual(
      user({ must_reset: true }),
    );

    expect(session.user?.must_reset).toBe(true);
  });

  it('clears local state even when logout fails', async () => {
    session.user = user();
    logoutMock.mockRejectedValue(new TypeError('fetch failed'));

    await expect(signOut()).rejects.toThrow('fetch failed');
    expect(session.user).toBeNull();
  });

  it('clears local state on a successful logout', async () => {
    session.user = user();
    logoutMock.mockResolvedValue();

    await signOut();

    expect(session.user).toBeNull();
  });

  it('replaces the account after a password change', async () => {
    session.user = user({ must_reset: true });
    updateMeMock.mockResolvedValue(user());

    await expect(changePassword('temporary', 'new password')).resolves.toEqual(user());

    expect(updateMeMock).toHaveBeenCalledWith({
      current_password: 'temporary',
      password: 'new password',
    });
    expect(session.user?.must_reset).toBe(false);
  });

  it('drops the account after a 401 without another request', () => {
    session.user = user();

    forgetSession();

    expect(session.user).toBeNull();
  });
});
