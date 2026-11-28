import { describe, expect, it } from 'vitest';

import type { User } from './api';
import { resolveNavigation, safeRedirect } from './navigation';

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

describe('resolveNavigation', () => {
  it('sends everything to setup while no account exists', () => {
    expect(
      resolveNavigation(
        { name: 'items', fullPath: '/items', requiresAuth: true },
        { setupRequired: true, user: null },
      ),
    ).toEqual({ name: 'setup', replace: true });
  });

  it('keeps setup reachable while it is required', () => {
    expect(
      resolveNavigation(
        { name: 'setup', fullPath: '/setup' },
        { setupRequired: true, user: null },
      ),
    ).toBe(true);
  });

  it('redirects setup to login once an account exists', () => {
    expect(
      resolveNavigation(
        { name: 'setup', fullPath: '/setup' },
        { setupRequired: false, user: null },
      ),
    ).toEqual({ name: 'login', replace: true });
  });

  it('keeps the deep link when a signed-out user opens a protected route', () => {
    expect(
      resolveNavigation(
        { name: 'item-edit', fullPath: '/items/0190?tab=stock', requiresAuth: true },
        { setupRequired: false, user: null },
      ),
    ).toEqual({
      name: 'login',
      query: { redirect: '/items/0190?tab=stock' },
      replace: true,
    });
  });

  it('allows the login screen to signed-out users', () => {
    expect(
      resolveNavigation(
        { name: 'login', fullPath: '/login' },
        { setupRequired: false, user: null },
      ),
    ).toBe(true);
  });

  it('forces a flagged account onto the password change', () => {
    expect(
      resolveNavigation(
        { name: 'items', fullPath: '/items', requiresAuth: true },
        { setupRequired: false, user: user({ must_reset: true }) },
      ),
    ).toEqual({ name: 'change-password', replace: true });
  });

  it('lets a flagged account stay on the password change', () => {
    expect(
      resolveNavigation(
        { name: 'change-password', fullPath: '/change-password', requiresAuth: true },
        { setupRequired: false, user: user({ must_reset: true }) },
      ),
    ).toBe(true);
  });

  it('lets a flagged account read their own profile', () => {
    expect(
      resolveNavigation(
        { name: 'profile', fullPath: '/profile', requiresAuth: true },
        { setupRequired: false, user: user({ must_reset: true }) },
      ),
    ).toBe(true);
  });

  it('keeps a reset link reachable while signed out', () => {
    expect(
      resolveNavigation(
        { name: 'password-reset', fullPath: '/password-reset/token' },
        { setupRequired: false, user: null },
      ),
    ).toBe(true);
  });

  it('sends a signed-in user away from login to the deep link', () => {
    expect(
      resolveNavigation(
        {
          name: 'login',
          fullPath: '/login',
          query: { redirect: '/items/0190' },
        },
        { setupRequired: false, user: user() },
      ),
    ).toBe('/items/0190');
  });

  it('sends a signed-in user away from the password change', () => {
    expect(
      resolveNavigation(
        { name: 'change-password', fullPath: '/change-password' },
        { setupRequired: false, user: user() },
      ),
    ).toEqual({ name: 'items', replace: true });
  });

  it('keeps non-admins out of admin screens', () => {
    expect(
      resolveNavigation(
        { name: 'users', fullPath: '/users', requiresAuth: true, adminOnly: true },
        { setupRequired: false, user: user() },
      ),
    ).toEqual({ name: 'items', replace: true });
  });

  it('lets admins into admin screens', () => {
    expect(
      resolveNavigation(
        { name: 'users', fullPath: '/users', requiresAuth: true, adminOnly: true },
        { setupRequired: false, user: user({ is_admin: true }) },
      ),
    ).toBe(true);
  });
});

describe('safeRedirect', () => {
  it('keeps local deep links', () => {
    expect(safeRedirect('/items/0190?tab=stock')).toBe('/items/0190?tab=stock');
  });

  it('rejects external and auth-screen redirects', () => {
    expect(safeRedirect('https://example.com')).toBe('/items');
    expect(safeRedirect('//example.com')).toBe('/items');
    expect(safeRedirect('/login')).toBe('/items');
    expect(safeRedirect(['/items'])).toBe('/items');
  });
});
