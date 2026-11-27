/**
 * The typed client for the JSON API in docs/api.md.
 *
 * Every request goes through `request`, so auth cookies, the error envelope
 * and the JSON headers stay in one place. Screens import one function each.
 */

/** The payload of `GET /api/v1/health`. */
export interface Health {
  status: string;
  version: string;
  time: string;
}

/** A failed request, carrying the server's error code for the UI to localize. */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;

  constructor(status: number, code: string, message: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
  }
}

interface ErrorEnvelope {
  error?: {
    code?: string;
    message?: string;
  };
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...init,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', ...init.headers },
  });

  if (!response.ok) {
    throw await toApiError(response);
  }

  return (await response.json()) as T;
}

async function toApiError(response: Response): Promise<ApiError> {
  let code = 'unexpected';
  let message = `request failed with ${response.status}`;

  try {
    const body = (await response.json()) as ErrorEnvelope;
    code = body.error?.code ?? code;
    message = body.error?.message ?? message;
  } catch {
    // Not the JSON envelope; the status-based message stands.
  }

  return new ApiError(response.status, code, message);
}

/** Reads the server's liveness, build version and clock. */
export function getHealth(): Promise<Health> {
  return request<Health>('/api/v1/health');
}
