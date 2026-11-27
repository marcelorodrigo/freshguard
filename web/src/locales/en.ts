/**
 * The English message catalogue. It is the schema every other locale is typed
 * against, so a missing or renamed key fails `vue-tsc` instead of falling back
 * to English in production.
 */

/** Widens the literal strings of `typeof en` so a translation may differ. */
type Widen<T> = { [K in keyof T]: T[K] extends string ? string : Widen<T[K]> };

export const en = {
  app: {
    title: 'FreshGuard',
    tagline: 'Household inventory, one binary.',
    loading: 'Checking the server…',
    status: 'Status',
    version: 'Version',
    serverTime: 'Server time',
  },
  errors: {
    title: 'Server unreachable',
    transport: 'The server did not answer. Check the connection and try again.',
    not_found: 'This server does not expose the health endpoint.',
    unexpected: 'The server answered with an error.',
  },
} as const;

export type MessageSchema = Widen<typeof en>;
