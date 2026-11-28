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
  if (state.setupRequired) {
    return to.name === 'setup' ? true : { name: 'setup', replace: true };
  }

  if (to.name === 'setup') {
    return state.user
      ? { name: 'items', replace: true }
      : { name: 'login', replace: true };
  }

  if (state.user === null) {
    return signedOutDecision(to);
  }

  if (state.user.must_reset) {
    return mustResetDecision(to);
  }

  return signedInDecision(to, state.user);
}

function signedOutDecision(to: NavigationTarget): NavigationDecision {
  if (to.name === 'login' || to.requiresAuth !== true) {
    return true;
  }
  return {
    name: 'login',
    query: { redirect: to.fullPath },
    replace: true,
  };
}

function mustResetDecision(to: NavigationTarget): NavigationDecision {
  // The profile is reachable so a flagged account can still read who it is
  // signed in as; every other screen waits for the password change.
  return to.name === 'change-password' || to.name === 'profile'
    ? true
    : { name: 'change-password', replace: true };
}

function signedInDecision(to: NavigationTarget, user: User): NavigationDecision {
  if (to.name === 'change-password') {
    return { name: 'items', replace: true };
  }
  if (to.name === 'login') {
    return safeRedirect(to.query?.redirect);
  }
  if (to.adminOnly === true && !user.is_admin) {
    return { name: 'items', replace: true };
  }
  return true;
}
