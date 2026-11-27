import type { User } from './api';

export interface NavigationTarget {
  name?: string | null;
  fullPath: string;
  query?: Record<string, unknown>;
  requiresAuth?: boolean;
  adminOnly?: boolean;
}

export interface NavigationState {
  setupRequired: boolean;
  user: User | null;
}

/** A decision the router accepts: continue, a path, or a named destination. */
export type NavigationDecision =
  | true
  | string
  | { name: string; query?: Record<string, string>; replace?: true };

const DEFAULT_DESTINATION = '/items';

/** Keeps a login deep link local and away from the auth screens themselves. */
export function safeRedirect(value: unknown): string {
  if (typeof value !== 'string') {
    return DEFAULT_DESTINATION;
  }
  if (!value.startsWith('/') || value.startsWith('//')) {
    return DEFAULT_DESTINATION;
  }
  if (value === '/login' || value === '/setup' || value.startsWith('/login?')) {
    return DEFAULT_DESTINATION;
  }
  return value;
}

/**
 * The auth gate in one testable function: setup wins over everything, then a
 * missing session, then the forced password change, then admin routes.
 */
export function resolveNavigation(
  to: NavigationTarget,
  state: NavigationState,
): NavigationDecision {
  const onSetup = to.name === 'setup';
  const onLogin = to.name === 'login';

  if (state.setupRequired) {
    return onSetup ? true : { name: 'setup', replace: true };
  }

  if (onSetup) {
    return state.user
      ? { name: 'items', replace: true }
      : { name: 'login', replace: true };
  }

  if (state.user === null) {
    if (onLogin || to.requiresAuth !== true) {
      return true;
    }
    return {
      name: 'login',
      query: { redirect: to.fullPath },
      replace: true,
    };
  }

  if (state.user.must_reset) {
    return to.name === 'change-password'
      ? true
      : { name: 'change-password', replace: true };
  }

  if (to.name === 'change-password') {
    return { name: 'items', replace: true };
  }

  if (onLogin) {
    return safeRedirect(to.query?.redirect);
  }

  if (to.adminOnly === true && !state.user.is_admin) {
    return { name: 'items', replace: true };
  }

  return true;
}
