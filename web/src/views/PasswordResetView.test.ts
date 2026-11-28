import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

import { ApiError, completePasswordReset, getPasswordReset } from '../api';
import { en } from '../locales/en';
import PasswordResetView from './PasswordResetView.vue';

vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');

  return { ...actual, completePasswordReset: vi.fn(), getPasswordReset: vi.fn() };
});

let wrapper: VueWrapper;
let router: Router;

async function mountView(token = 'a-valid-token'): Promise<void> {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/password-reset/:token', name: 'password-reset', component: PasswordResetView },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  });
  await router.push(`/password-reset/${token}`);
  await router.isReady();

  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } });
  wrapper = mount(PasswordResetView, { global: { plugins: [router, i18n] } });
  await flushPromises();
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getPasswordReset).mockResolvedValue({ email: 'm@example.com' });
  vi.mocked(completePasswordReset).mockResolvedValue(undefined);
});
afterEach(() => {
  wrapper?.unmount();
});

describe('PasswordResetView', () => {
  it('names the account the link belongs to', async () => {
    await mountView();

    expect(getPasswordReset).toHaveBeenCalledWith('a-valid-token');
    expect(wrapper.text()).toContain('m@example.com');
    expect(wrapper.find('[data-test="reset-form"]').exists()).toBe(true);
  });

  it('refuses a confirmation that does not match', async () => {
    await mountView();

    await wrapper.find('[data-test="reset-password"]').setValue('a good password');
    await wrapper.find('[data-test="reset-password-confirmation"]').setValue('different');
    await wrapper.find('[data-test="reset-submit"]').trigger('click');
    await flushPromises();

    expect(completePasswordReset).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain(en.auth.passwordMismatch);
  });

  it('sets the password once and consumes the link', async () => {
    await mountView();

    await wrapper.find('[data-test="reset-password"]').setValue('a good password');
    await wrapper
      .find('[data-test="reset-password-confirmation"]')
      .setValue('a good password');
    await wrapper.find('[data-test="reset-submit"]').trigger('click');
    await flushPromises();

    expect(completePasswordReset).toHaveBeenCalledWith('a-valid-token', 'a good password');
    expect(wrapper.find('[data-test="reset-done"]').exists()).toBe(true);
    expect(wrapper.text()).toContain(en.reset.doneTitle);
  });

  it('shows the invalid state for an expired or used link', async () => {
    vi.mocked(getPasswordReset).mockRejectedValue(
      new ApiError(404, 'not_found', 'no such resource'),
    );
    await mountView('used-token');

    expect(wrapper.find('[data-test="reset-invalid"]').exists()).toBe(true);
    expect(wrapper.text()).toContain(en.reset.invalidTitle);
  });

  it('shows the invalid state when the link was consumed elsewhere', async () => {
    vi.mocked(completePasswordReset).mockRejectedValue(
      new ApiError(404, 'not_found', 'no such resource'),
    );
    await mountView();

    await wrapper.find('[data-test="reset-password"]').setValue('a good password');
    await wrapper
      .find('[data-test="reset-password-confirmation"]')
      .setValue('a good password');
    await wrapper.find('[data-test="reset-submit"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-test="reset-invalid"]').exists()).toBe(true);
  });
});
