import { reactive } from 'vue';

export type ToastLevel = 'success' | 'warning' | 'error';

export interface Toast {
  id: number;
  level: ToastLevel;
  title: string;
  body?: string;
}

const state = reactive<{ items: Toast[] }>({ items: [] });
let nextID = 1;

/** Shows a toast top-right and removes it after five seconds. */
export function pushToast(toast: Omit<Toast, 'id'>): number {
  const id = nextID++;
  state.items.push({ ...toast, id });

  setTimeout(() => {
    dismissToast(id);
  }, 5000);

  return id;
}

export function dismissToast(id: number): void {
  const index = state.items.findIndex((item) => item.id === id);
  if (index >= 0) {
    state.items.splice(index, 1);
  }
}

export function useToasts(): { items: Toast[] } {
  return state;
}
