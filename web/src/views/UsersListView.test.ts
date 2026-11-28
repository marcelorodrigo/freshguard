import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

import {
  ApiError,
  createUser,
  deleteUser,
  listUsers,
  updateUser,
  type Page,
  type User,
} from '../api';
import { en } from '../locales/en';
import { session } from '../session';
import { pushToast } from '../toast';
import UsersListView from './UsersListView.vue';

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');

  return {
    ...actual,
    createUser: vi.fn(),
    deleteUser: vi.fn(),
    listUsers: vi.fn(),
    updateUser: vi.fn(),
  };
});

vi.mock('../toast', () => ({ pushToast: vi.fn() }));

vi.mock('../session', () => ({
  session: reactive({ user: null }),
  signOut: vi.fn(),
}));

const admin = makeUser({
  id: 'user-admin',
  name: 'Marcelo',
  email: 'm@example.com',
  is_admin: true,
});
const ana = makeUser({
  id: 'user-ana',
  name: 'Ana',
  email: 'ana@example.com',
  is_admin: false,
});

function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 'user-1',
    name: 'User',
    email: 'user@example.com',
    is_admin: false,
    must_reset: false,
    created_at: '2026-01-24T08:27:26Z',
    updated_at: '2026-01-24T08:27:26Z',
    ...overrides,
  };
}

function page(data: User[]): Page<User> {
  return {
    data,
    meta: { page: 1, per_page: 10, total: data.length, pages: data.length > 0 ? 1 : 0 },
  };
}

let listed: User[];
let wrapper: VueWrapper;
let router: Router;

async function mountView(): Promise<void> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/users', name: 'users', component: UsersListView }],
  });
  await router.push('/users');
  await router.isReady();

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } });

  wrapper = mount(UsersListView, { global: { plugins: [router, i18n] } });
  await flushPromises();
}

function row(userID: string) {
  return wrapper.find(`[data-user-id="${userID}"]`);
}

async function openCreate(): Promise<void> {
  await wrapper.find('[data-test="new-user"]').trigger('click');
}

beforeEach(() => {
  vi.clearAllMocks();
  session.user = admin;
  listed = [admin, ana];

  vi.mocked(listUsers).mockImplementation(async () => page(listed));
  vi.mocked(createUser).mockResolvedValue(ana);
  vi.mocked(updateUser).mockResolvedValue(admin);
  vi.mocked(deleteUser).mockResolvedValue(undefined);
});

afterEach(() => {
  wrapper?.unmount();
});

describe('UsersListView', () => {
  it('lists name, email and admin, hiding timestamps by default', async () => {
    await mountView();

    expect(wrapper.findAll('[data-test="user-row"]')).toHaveLength(2);
    expect(row(admin.id).find('[data-test="user-name"]').text()).toBe('Marcelo');
    expect(row(admin.id).find('[data-test="user-email"]').text()).toBe('m@example.com');
    expect(row(admin.id).find('[data-test="user-admin"]').text()).toBe(en.common.admin);
    expect(row(ana.id).find('[data-test="user-admin"]').text()).toBe(en.users.member);

    expect(wrapper.findAll('[data-test="user-created"]')).toHaveLength(0);
    expect(wrapper.findAll('[data-test="user-updated"]')).toHaveLength(0);

    await wrapper.find('[data-test="user-column-created"]').setValue(true);
    await wrapper.find('[data-test="user-column-updated"]').setValue(true);
    expect(wrapper.findAll('[data-test="user-created"]')).toHaveLength(2);
    expect(wrapper.findAll('[data-test="user-updated"]')).toHaveLength(2);
  });

  it('creates a member from the modal with every field', async () => {
    await mountView();
    await openCreate();

    expect(wrapper.find('[data-test="user-form-title"]').text()).toBe(
      en.users.createTitle,
    );

    await wrapper.find('[data-test="user-name-input"]').setValue('Ana');
    await wrapper.find('[data-test="user-email-input"]').setValue('ana@example.com');
    await wrapper.find('[data-test="user-password-input"]').setValue('a good password');
    await wrapper.find('[data-test="user-admin-input"]').setValue(true);
    await wrapper.find('[data-test="user-save"]').trigger('click');
    await flushPromises();
    expect(createUser).toHaveBeenCalledWith({
      name: 'Ana',
      email: 'ana@example.com',
      password: 'a good password',
      is_admin: true,
    });
    expect(pushToast).toHaveBeenCalledWith({
      level: 'success',
      title: en.users.created,
    });
    expect(listUsers).toHaveBeenCalledTimes(2);
  });

  it('edits a member and sends a password only when one was typed', async () => {
    await mountView();

    await row(ana.id).find('[data-test="user-edit"]').trigger('click');
    expect(wrapper.find('[data-test="user-form-title"]').text()).toBe(en.users.editTitle);
    expect(
      (wrapper.find('[data-test="user-password-input"]').element as HTMLInputElement).value,
    ).toBe('');
    expect(wrapper.text()).toContain(en.users.passwordKeep);
    expect(wrapper.text()).toContain(en.users.temporaryPasswordHelp);

    await wrapper.find('[data-test="user-name-input"]').setValue('Ana Maria');
    await wrapper.find('[data-test="user-save"]').trigger('click');
    await flushPromises();

    expect(updateUser).toHaveBeenCalledWith(ana.id, {
      name: 'Ana Maria',
      email: 'ana@example.com',
      is_admin: false,
    });

    vi.mocked(updateUser).mockClear();
    await row(ana.id).find('[data-test="user-edit"]').trigger('click');
    await wrapper.find('[data-test="user-password-input"]').setValue('temporary password');
    await wrapper.find('[data-test="user-save"]').trigger('click');
    await flushPromises();

    expect(updateUser).toHaveBeenCalledWith(ana.id, expect.objectContaining({
      password: 'temporary password',
    }));
  });

  it('disables the administrator toggle when an admin edits themselves', async () => {
    vi.mocked(updateUser).mockResolvedValue({
      ...admin,
      name: 'Marcelo Renamed',
    });
    await mountView();

    await row(admin.id).find('[data-test="user-edit"]').trigger('click');
    const toggle = wrapper.find('[data-test="user-admin-input"]');
    expect((toggle.element as HTMLInputElement).disabled).toBe(true);
    expect((toggle.element as HTMLInputElement).checked).toBe(true);
    expect(wrapper.text()).not.toContain(en.users.temporaryPasswordHelp);

    await wrapper.find('[data-test="user-name-input"]').setValue('Marcelo Renamed');
    await wrapper.find('[data-test="user-save"]').trigger('click');
    await flushPromises();

    expect(updateUser).toHaveBeenCalledWith(admin.id, {
      name: 'Marcelo Renamed',
      email: 'm@example.com',
      is_admin: true,
    });
    expect(session.user?.name).toBe('Marcelo Renamed');
  });

  it('deletes another member after confirming and refuses to delete yourself', async () => {
    await mountView();

    expect(row(admin.id).find('[data-test="user-delete"]').exists()).toBe(false);
    expect(
      row(admin.id).find('[data-test="user-delete-disabled"]').attributes('title'),
    ).toBe(en.users.selfDeleteTooltip);

    await row(ana.id).find('[data-test="user-delete"]').trigger('click');
    await wrapper.find('[data-test="confirm-dialog-confirm"]').trigger('click');
    await flushPromises();

    expect(deleteUser).toHaveBeenCalledWith(ana.id);
    expect(pushToast).toHaveBeenCalledWith({
      level: 'success',
      title: en.users.deleted,
    });
  });

  it('reports the last-administrator rule with the ported copy', async () => {
    vi.mocked(deleteUser).mockRejectedValue(
      new ApiError(409, 'last_admin', 'the last administrator cannot be deleted'),
    );
    await mountView();

    await row(ana.id).find('[data-test="user-delete"]').trigger('click');
    await wrapper.find('[data-test="confirm-dialog-confirm"]').trigger('click');
    await flushPromises();

    expect(pushToast).toHaveBeenCalledWith({
      level: 'error',
      title: en.users.deleteBlockedTitle,
      body: en.users.lastAdminBody,
    });
  });

  it('searches through the collection query', async () => {
    await mountView();
    vi.useFakeTimers();

    await wrapper.find('[data-test="users-search"]').setValue('ana');
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();

    expect(listUsers).toHaveBeenLastCalledWith(
      expect.objectContaining({ q: 'ana', page: 1 }),
    );

    vi.useRealTimers();
  });

  it('sorts by email in both directions', async () => {
    await mountView();

    await wrapper.find('[data-test="sort-email"]').trigger('click');
    await flushPromises();
    expect(listUsers).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'email', dir: 'asc' }),
    );

    await wrapper.find('[data-test="sort-email"]').trigger('click');
    await flushPromises();
    expect(listUsers).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'email', dir: 'desc' }),
    );
  });

  it('shows the empty state for an empty search result', async () => {
    listed = [];
    await mountView();

    expect(wrapper.find('[data-test="users-empty"]').text()).toContain(
      en.users.emptyTitle,
    );
  });
});
