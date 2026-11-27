import { reactive } from 'vue';

import {
  ApiError,
  getHealth,
  getMe,
  login as apiLogin,
  logout as apiLogout,
  setup as apiSetup,
  updateMe,
  type SetupInput,
  type User,
} from './api';

interface SessionState {
  user: User | null;
  setupRequired: boolean;
  loaded: boolean;
}

/** The one copy of the signed-in account and the setup flag. */
export const session = reactive<SessionState>({
  user: null,
  setupRequired: false,
  loaded: false,
});

let loading: Promise<void> | null = null;

/**
 * Loads the health flag and, when setup is done, the current account. The
 * router calls this once per navigation; the promise keeps parallel calls
 * from issuing duplicate requests.
 */
export function ensureSession(): Promise<void> {
  if (session.loaded) {
    return Promise.resolve();
  }
  if (loading !== null) {
    return loading;
  }

  loading = load()
    .catch(() => {
      // The login screen can still surface the transport failure on submit.
      session.setupRequired = false;
      session.user = null;
    })
    .finally(() => {
      session.loaded = true;
      loading = null;
    });

  return loading;
}

async function load(): Promise<void> {
  const health = await getHealth();
  session.setupRequired = health.setup_required;

  if (health.setup_required) {
    session.user = null;
    return;
  }

  try {
    session.user = await getMe();
  } catch (error) {
    if (error instanceof ApiError && error.code === 'unauthorized') {
      session.user = null;
      return;
    }
    throw error;
  }
}

/** Creates the first administrator and records the session it opens. */
export async function createFirstAdmin(input: SetupInput): Promise<User> {
  const result = await apiSetup(input);
  session.user = result.user;
  session.setupRequired = false;
  session.loaded = true;
  return result.user;
}

/** Signs in and records the account. */
export async function signIn(email: string, password: string): Promise<User> {
  const result = await apiLogin({ email, password });
  session.user = result.user;
  session.setupRequired = false;
  session.loaded = true;
  return result.user;
}

/** Deletes the session server-side, clearing local state even when that fails. */
export async function signOut(): Promise<void> {
  try {
    await apiLogout();
  } finally {
    session.user = null;
  }
}

/** Changes the signed-in account's password and clears must_reset. */
export async function changePassword(
  currentPassword: string,
  newPassword: string,
): Promise<User> {
  const user = await updateMe({
    current_password: currentPassword,
    password: newPassword,
  });
  session.user = user;
  return user;
}

/** Drops local auth state after a 401, without another round trip. */
export function forgetSession(): void {
  session.user = null;
}
