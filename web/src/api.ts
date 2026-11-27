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
  setup_required: boolean;
  time: string;
}

/** The User resource from docs/api.md. */
export interface User {
  id: string;
  name: string;
  email: string;
  is_admin: boolean;
  must_reset: boolean;
  created_at: string;
  updated_at: string;
}

/** The Item resource from docs/api.md. */
export interface Item {
  id: string;
  name: string;
  barcode: string;
  description: string;
  tags: string[];
  quantity: number;
  earliest_expiration: string;
  batch_count: number;
  created_at: string;
  updated_at: string;
}

/** A batch inside an item detail response. */
export interface Batch {
  id: string;
  item_id: string;
  location: { id: string; name: string };
  expires_at: string;
  quantity: number;
  created_at: string;
  updated_at: string;
}

/** The Item detail resource: the list shape plus its batches. */
export interface ItemDetail extends Item {
  batches: Batch[];
}

/** A tag suggestion from `GET /api/v1/tags`. */
export interface Tag {
  id: string;
  name: string;
  item_count: number;
}

/** Collection metadata from docs/api.md. */
export interface PageMeta {
  page: number;
  per_page: number;
  total: number;
  pages: number;
}

export interface Page<T> {
  data: T[];
  meta: PageMeta;
}

export type ItemSort =
  | 'name'
  | 'quantity'
  | 'earliest_expiration'
  | 'created_at'
  | 'updated_at';

export interface ItemFilters {
  q?: string;
  sort?: ItemSort;
  dir?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface ItemInput {
  name: string;
  barcode?: string;
  description?: string;
  tags: string[];
}

export interface ItemPatch {
  name?: string;
  barcode?: string;
  description?: string;
  tags?: string[];
}

export interface SetupInput {
  name: string;
  email: string;
  password: string;
}

export interface LoginInput {
  email: string;
  password: string;
}

export interface ProfilePatch {
  name?: string;
  email?: string;
  password?: string;
  current_password?: string;
}

interface SessionResponse {
  user: User;
}

/** A failed request, carrying the server's error code for the UI to localize. */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields: Record<string, string>;

  constructor(
    status: number,
    code: string,
    message: string,
    fields: Record<string, string> = {},
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
  }
}

interface ErrorEnvelope {
  error?: {
    code?: string;
    message?: string;
    fields?: Record<string, string>;
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

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

async function toApiError(response: Response): Promise<ApiError> {
  let code = 'unexpected';
  let message = `request failed with ${response.status}`;
  let fields: Record<string, string> = {};

  try {
    const body = (await response.json()) as ErrorEnvelope;
    code = body.error?.code ?? code;
    message = body.error?.message ?? message;
    fields = body.error?.fields ?? fields;
  } catch {
    // Not the JSON envelope; the status-based message stands.
  }

  return new ApiError(response.status, code, message, fields);
}

function jsonInit(method: string, body: unknown): RequestInit {
  return {
    method,
    body: JSON.stringify(body),
    headers: { 'Content-Type': 'application/json' },
  };
}

function itemPath(id: string): string {
  return `/api/v1/items/${encodeURIComponent(id)}`;
}

/** Reads the server's liveness, build version, clock and setup state. */
export function getHealth(): Promise<Health> {
  return request<Health>('/api/v1/health');
}

/** Creates the first administrator and signs them in. */
export function setup(input: SetupInput): Promise<SessionResponse> {
  return request<SessionResponse>('/api/v1/setup', jsonInit('POST', input));
}

/** Signs in with an email and password. */
export function login(input: LoginInput): Promise<SessionResponse> {
  return request<SessionResponse>('/api/v1/session', jsonInit('POST', input));
}

/** Deletes the server-side session and clears the cookie. */
export function logout(): Promise<void> {
  return request<void>('/api/v1/session', { method: 'DELETE' });
}

/** Reads the signed-in account. */
export function getMe(): Promise<User> {
  return request<User>('/api/v1/me');
}

/** Changes the signed-in account's profile or password. */
export function updateMe(patch: ProfilePatch): Promise<User> {
  return request<User>('/api/v1/me', jsonInit('PATCH', patch));
}

/** Lists items with search, sorting and pagination. */
export function listItems(filters: ItemFilters = {}): Promise<Page<Item>> {
  const params = new URLSearchParams();

  if (filters.q !== undefined && filters.q !== '') {
    params.set('q', filters.q);
  }
  if (filters.sort !== undefined) {
    params.set('sort', filters.sort);
  }
  if (filters.dir !== undefined) {
    params.set('dir', filters.dir);
  }
  if (filters.page !== undefined) {
    params.set('page', String(filters.page));
  }
  if (filters.per_page !== undefined) {
    params.set('per_page', String(filters.per_page));
  }

  const query = params.toString();
  return request<Page<Item>>(`/api/v1/items${query === '' ? '' : `?${query}`}`);
}

/** Reads one item with its batches. */
export function getItem(id: string): Promise<ItemDetail> {
  return request<ItemDetail>(itemPath(id));
}

/** Creates an item with its tags. */
export function createItem(input: ItemInput): Promise<ItemDetail> {
  return request<ItemDetail>('/api/v1/items', jsonInit('POST', input));
}

/** Changes a subset of an item; `tags` replaces the whole set. */
export function updateItem(id: string, patch: ItemPatch): Promise<ItemDetail> {
  return request<ItemDetail>(itemPath(id), jsonInit('PATCH', patch));
}

/** Deletes one item and its batches. */
export function deleteItem(id: string): Promise<void> {
  return request<void>(itemPath(id), { method: 'DELETE' });
}

/** Deletes many items atomically. */
export function bulkDeleteItems(ids: string[]): Promise<{ deleted: number }> {
  return request<{ deleted: number }>(
    '/api/v1/items/bulk-delete',
    jsonInit('POST', { ids }),
  );
}

/** Reads the 50 most used tags. */
export async function listTags(): Promise<Tag[]> {
  const body = await request<{ data: Tag[] }>('/api/v1/tags');
  return body.data;
}
