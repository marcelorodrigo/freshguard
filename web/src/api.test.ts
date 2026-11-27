import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  ApiError,
  bulkDeleteItems,
  bulkDeleteLocations,
  createBatch,
  createItem,
  createLocation,
  deleteBatch,
  deleteItem,
  deleteLocation,
  getHealth,
  getItem,
  listItems,
  listLocations,
  listTags,
  login,
  logout,
  updateBatch,
  updateItem,
  updateLocation,
} from './api';

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


describe('listItems', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('serializes filters into the collection query', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        data: [],
        meta: { page: 2, per_page: 10, total: 0, pages: 0 },
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await listItems({
      q: 'whole milk',
      sort: 'quantity',
      dir: 'desc',
      page: 2,
      per_page: 10,
    });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/items?q=whole+milk&sort=quantity&dir=desc&page=2&per_page=10',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
  });
});

describe('listTags', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('unwraps the data envelope', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse({
          data: [{ id: 'tag-1', name: 'Dairy', item_count: 2 }],
        }),
      ),
    );

    await expect(listTags()).resolves.toEqual([
      { id: 'tag-1', name: 'Dairy', item_count: 2 },
    ]);
  });
});


const item = {
  id: '0190a1b2-0000-7000-8000-000000000002',
  name: 'Whole milk',
  barcode: '7891000100103',
  description: '1 L',
  tags: ['Dairy'],
  quantity: 0,
  earliest_expiration: '',
  batch_count: 0,
  created_at: '2026-01-24T08:27:26Z',
  updated_at: '2026-01-24T08:27:26Z',
};

describe('item requests', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('encodes the item id when reading a detail', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ ...item, batches: [] }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await expect(getItem('id with space')).resolves.toMatchObject({
      id: item.id,
      batches: [],
    });
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/items/id%20with%20space',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
  });

  it('posts an item and its tags', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ ...item, batches: [] }, 201),
    );
    vi.stubGlobal('fetch', fetchMock);

    await createItem({
      name: 'Whole milk',
      barcode: '7891000100103',
      description: '1 L',
      tags: ['Dairy'],
    });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/items',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({
          name: 'Whole milk',
          barcode: '7891000100103',
          description: '1 L',
          tags: ['Dairy'],
        }),
      }),
    );
  });

  it('patches an item', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ ...item, batches: [] }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await updateItem(item.id, { name: 'Milk', tags: ['Fridge'] });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/items/' + item.id,
      expect.objectContaining({
        method: 'PATCH',
        body: '{"name":"Milk","tags":["Fridge"]}',
      }),
    );
  });

  it('accepts the empty body of a 204 delete', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(deleteItem(item.id)).resolves.toBeUndefined();
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/items/' + item.id,
      expect.objectContaining({ method: 'DELETE' }),
    );
  });
});


const batch = {
  id: 'batch-1',
  item_id: 'item-1',
  location: { id: 'loc-1', name: 'Fridge' },
  expires_at: '2026-02-01',
  quantity: 2,
  created_at: '2026-01-24T08:27:26Z',
  updated_at: '2026-01-24T08:27:26Z',
};

describe('batch requests', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('posts a batch under its item', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(batch, 201));
    vi.stubGlobal('fetch', fetchMock);

    await expect(
      createBatch('item with space', {
        location_id: 'loc-1',
        expires_at: '2026-02-01',
        quantity: 2,
      }),
    ).resolves.toEqual(batch);

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/items/item%20with%20space/batches',
      expect.objectContaining({
        method: 'POST',
        body: '{"location_id":"loc-1","expires_at":"2026-02-01","quantity":2}',
      }),
    );
  });

  it('patches a batch by id', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(batch));
    vi.stubGlobal('fetch', fetchMock);

    await updateBatch('batch with space', { quantity: 3 });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/batches/batch%20with%20space',
      expect.objectContaining({ method: 'PATCH', body: '{"quantity":3}' }),
    );
  });

  it('accepts the empty body of a 204 batch delete', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(deleteBatch('batch-1')).resolves.toBeUndefined();
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/batches/batch-1',
      expect.objectContaining({ method: 'DELETE' }),
    );
  });
});

describe('listLocations', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('serializes the collection query and unwraps the page', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        data: [
          {
            id: 'loc-1',
            name: 'Fridge',
            description: '',
            expiration_notify_days: 0,
            parent: null,
            batch_count: 2,
            created_at: '2026-01-24T08:27:26Z',
            updated_at: '2026-01-24T08:27:26Z',
          },
        ],
        meta: { page: 1, per_page: 100, total: 1, pages: 1 },
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const page = await listLocations({ per_page: 100, sort: 'name' });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/locations?sort=name&per_page=100',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
    expect(page.data).toHaveLength(1);
    expect(page.data[0]?.name).toBe('Fridge');
  });
});

const location = {
  id: 'loc-1',
  name: 'Fridge',
  description: '',
  expiration_notify_days: 0,
  parent: null,
  batch_count: 0,
  created_at: '2026-01-24T08:27:26Z',
  updated_at: '2026-01-24T08:27:26Z',
};

describe('location writes', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('posts a location with its parent', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(location, 201));
    vi.stubGlobal('fetch', fetchMock);

    await createLocation({
      name: 'Fridge',
      description: 'Top shelf',
      expiration_notify_days: 3,
      parent_id: 'loc-kitchen',
    });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/locations',
      expect.objectContaining({
        method: 'POST',
        body: '{"name":"Fridge","description":"Top shelf","expiration_notify_days":3,"parent_id":"loc-kitchen"}',
      }),
    );
  });

  it('patches a location and can clear its parent with null', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(location));
    vi.stubGlobal('fetch', fetchMock);

    await updateLocation('loc with space', { name: 'Fridge', parent_id: null });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/locations/loc%20with%20space',
      expect.objectContaining({
        method: 'PATCH',
        body: '{"name":"Fridge","parent_id":null}',
      }),
    );
  });

  it('deletes one location', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(deleteLocation('loc-1')).resolves.toBeUndefined();
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/locations/loc-1',
      expect.objectContaining({ method: 'DELETE' }),
    );
  });

  it('bulk deletes locations atomically', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(jsonResponse({ deleted: 2 }));
    vi.stubGlobal('fetch', fetchMock);

    await expect(bulkDeleteLocations(['loc-1', 'loc-2'])).resolves.toEqual({
      deleted: 2,
    });
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/locations/bulk-delete',
      expect.objectContaining({
        method: 'POST',
        body: '{"ids":["loc-1","loc-2"]}',
      }),
    );
  });
});
