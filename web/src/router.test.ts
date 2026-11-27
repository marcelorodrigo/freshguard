import { createMemoryHistory } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { User } from './api';

vi.mock('./session', () => ({
  ensureSession: vi.fn().mockResolvedValue(undefined),
  session: reactiveSession(),
}));

import { session } from './session';
import { createAppRouter } from './router';

function reactiveSession(): {
  user: User | null;
  setupRequired: boolean;
  loaded: boolean;
} {
  return { user: null, setupRequired: false, loaded: true };
}

function user(overrides: Partial<User> = {}): User {
  return {
    id: '0190a1b2-0000-7000-8000-000000000001',
    name: 'Marcelo',
    email: 'm@example.com',
    is_admin: false,
    must_reset: false,
    created_at: '2026-01-24T08:27:26Z',
    updated_at: '2026-01-24T08:27:26Z',
    ...overrides,
  };
}

describe('router auth gate', () => {
  beforeEach(() => {
    session.user = null;
    session.setupRequired = false;
    session.loaded = true;
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('sends a fresh install to setup', async () => {
    session.setupRequired = true;
    const router = createAppRouter(createMemoryHistory());

    await router.push('/items');
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('setup');
  });

  it('sends a signed-out visitor to login with the deep link', async () => {
    const router = createAppRouter(createMemoryHistory());

    await router.push('/items/0190?tab=stock');
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('login');
    expect(router.currentRoute.value.query.redirect).toBe('/items/0190?tab=stock');
  });

  it('sends a flagged account to the forced password change', async () => {
    session.user = user({ must_reset: true });
    const router = createAppRouter(createMemoryHistory());

    await router.push('/items');
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('change-password');
  });

  it('keeps a signed-in account on the items route', async () => {
    session.user = user();
    const router = createAppRouter(createMemoryHistory());

    await router.push('/items');
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('items');
  });
});
