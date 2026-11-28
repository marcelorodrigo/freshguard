import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

import { ApiError, updateMe, type User } from '../api';
import { en } from '../locales/en';
import { session, signOut } from '../session';
import { pushToast } from '../toast';
import ProfileView from './ProfileView.vue';

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');

  return { ...actual, updateMe: vi.fn() };
});

vi.mock('../toast', () => ({ pushToast: vi.fn() }));

vi.mock('../session', () => ({
  session: reactive({ user: null }),
  signOut: vi.fn(),
}));

function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 'user-1',
    name: 'Marcelo',
    email: 'm@example.com',
    is_admin: true,
    must_reset: false,
    created_at: '2026-01-24T08:27:26Z',
    updated_at: '2026-01-24T08:27:26Z',
    ...overrides,
  };
}

let user: User;
let wrapper: VueWrapper;
let router: Router;

async function mountView(): Promise<void> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/profile', name: 'profile', component: ProfileView },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/items', name: 'items', component: { template: '<div />' } },
    ],
  });
  await router.push('/profile');
  await router.isReady();

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } });
  wrapper = mount(ProfileView, { global: { plugins: [router, i18n] } });
  await flushPromises();
}

beforeEach(() => {
  vi.clearAllMocks();
  user = makeUser();
  session.user = user;
  vi.mocked(updateMe).mockImplementation(async (patch) => ({
    ...user,
    ...patch,
  }));
});
afterEach(() => {
  wrapper?.unmount();
});

describe('ProfileView', () => {
  it('starts from the signed-in account', async () => {
    await mountView();

    expect(
      (wrapper.find('[data-test="profile-name"]').element as HTMLInputElement).value,
    ).toBe('Marcelo');
    expect(
      (wrapper.find('[data-test="profile-email"]').element as HTMLInputElement).value,
    ).toBe('m@example.com');
  });

  it('saves name and email without asking for the current password', async () => {
    await mountView();

    await wrapper.find('[data-test="profile-name"]').setValue('Marcelo Renamed');
    await wrapper.find('[data-test="profile-email"]').setValue('marcelo@example.com');
    await wrapper.find('[data-test="profile-save"]').trigger('click');
    await flushPromises();

    expect(updateMe).toHaveBeenCalledWith({
      name: 'Marcelo Renamed',
      email: 'marcelo@example.com',
    });
    expect(session.user?.name).toBe('Marcelo Renamed');
    expect(pushToast).toHaveBeenCalledWith({
      level: 'success',
      title: en.profile.updated,
    });
  });

  it('requires the current password before changing the password', async () => {
    await mountView();

    await wrapper.find('[data-test="profile-new-password"]').setValue('a good password');
    await wrapper
      .find('[data-test="profile-password-confirmation"]')
      .setValue('a good password');
    await wrapper.find('[data-test="profile-save"]').trigger('click');
    await flushPromises();

    expect(updateMe).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain(en.errors.required);
  });

  it('refuses a confirmation that does not match', async () => {
    await mountView();

    await wrapper.find('[data-test="profile-current-password"]').setValue('old password');
    await wrapper.find('[data-test="profile-new-password"]').setValue('a good password');
    await wrapper
      .find('[data-test="profile-password-confirmation"]')
      .setValue('another password');
    await wrapper.find('[data-test="profile-save"]').trigger('click');
    await flushPromises();

    expect(updateMe).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain(en.auth.passwordMismatch);
  });

  it('changes the password with the current one and clears the fields', async () => {
    await mountView();

    await wrapper.find('[data-test="profile-current-password"]').setValue('old password');
    await wrapper.find('[data-test="profile-new-password"]').setValue('a good password');
    await wrapper
      .find('[data-test="profile-password-confirmation"]')
      .setValue('a good password');
    await wrapper.find('[data-test="profile-save"]').trigger('click');
    await flushPromises();

    expect(updateMe).toHaveBeenCalledWith({
      name: 'Marcelo',
      email: 'm@example.com',
      current_password: 'old password',
      password: 'a good password',
    });
    expect(
      (wrapper.find('[data-test="profile-current-password"]').element as HTMLInputElement)
        .value,
    ).toBe('');
  });

  it('shows the server rule token under the current password field', async () => {
    vi.mocked(updateMe).mockRejectedValue(
      new ApiError(422, 'invalid_input', 'invalid input', { current_password: 'mismatch' }),
    );
    await mountView();

    await wrapper.find('[data-test="profile-current-password"]').setValue('wrong password');
    await wrapper.find('[data-test="profile-new-password"]').setValue('a good password');
    await wrapper
      .find('[data-test="profile-password-confirmation"]')
      .setValue('a good password');
    await wrapper.find('[data-test="profile-save"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain(en.errors.mismatch);
    expect(wrapper.find('[data-test="profile-error"]').exists()).toBe(true);
  });

  it('signs out and returns to login', async () => {
    await mountView();

    await wrapper.find('[data-test="profile-sign-out"]').trigger('click');
    await flushPromises();

    expect(signOut).toHaveBeenCalled();
    expect(router.currentRoute.value.name).toBe('login');
  });
});
