import { afterEach, describe, expect, it, vi } from 'vitest';

import { dismissToast, pushToast, useToasts } from './toast';

describe('toast store', () => {
  afterEach(() => {
    useToasts().items.splice(0);
    vi.useRealTimers();
  });

  it('adds a toast and removes it after five seconds', () => {
    vi.useFakeTimers();

    const id = pushToast({ level: 'success', title: 'Item created' });

    expect(useToasts().items).toEqual([
      { id, level: 'success', title: 'Item created' },
    ]);

    vi.advanceTimersByTime(4999);
    expect(useToasts().items).toHaveLength(1);

    vi.advanceTimersByTime(1);
    expect(useToasts().items).toHaveLength(0);
  });

  it('dismisses one toast and ignores an unknown id', () => {
    const first = pushToast({ level: 'warning', title: 'Careful' });
    const second = pushToast({ level: 'error', title: 'Failed' });

    dismissToast(first);
    dismissToast(9999);

    expect(useToasts().items).toEqual([
      { id: second, level: 'error', title: 'Failed' },
    ]);
  });
});
