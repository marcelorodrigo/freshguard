import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import App from './App.vue';
import { ApiError, getHealth } from './api';
import { createI18nInstance, type Locale } from './i18n';

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>();

  return { ...actual, getHealth: vi.fn() };
});

const getHealthMock = vi.mocked(getHealth);

function mountApp(locale: Locale = 'en') {
  return mount(App, { global: { plugins: [createI18nInstance(locale)] } });
}

describe('App', () => {
  afterEach(() => {
    vi.resetAllMocks();
  });

  it('renders the version reported by the health endpoint', async () => {
    getHealthMock.mockResolvedValue({
      status: 'ok',
      version: '1.0.0',
      time: '2026-01-24T08:27:26Z',
    });

    const wrapper = mountApp();
    expect(wrapper.text()).toContain('Checking the server');
    await flushPromises();

    expect(wrapper.get('[data-test="status"]').text()).toBe('ok');
    expect(wrapper.get('[data-test="version"]').text()).toBe('1.0.0');
    expect(wrapper.get('[data-test="time"]').text()).toBe('2026-01-24T08:27:26Z');
  });

  it('localizes the not-found failure instead of showing server prose', async () => {
    getHealthMock.mockRejectedValue(
      new ApiError(404, 'not_found', 'no such endpoint'),
    );

    const wrapper = mountApp();
    await flushPromises();

    expect(wrapper.get('[data-test="error"]').text()).toContain(
      'This server does not expose the health endpoint.',
    );
    expect(wrapper.text()).not.toContain('no such endpoint');
    expect(wrapper.find('[data-test="version"]').exists()).toBe(false);
  });

  it('localizes a transport failure', async () => {
    getHealthMock.mockRejectedValue(new TypeError('fetch failed'));

    const wrapper = mountApp();
    await flushPromises();

    expect(wrapper.get('[data-test="error"]').text()).toContain(
      'The server did not answer.',
    );
  });

  it('renders in pt-BR when the browser asks for it', async () => {
    getHealthMock.mockResolvedValue({
      status: 'ok',
      version: '1.0.0',
      time: '2026-01-24T08:27:26Z',
    });

    const wrapper = mountApp('pt-BR');
    await flushPromises();

    expect(wrapper.text()).toContain('Estoque da casa');
    expect(wrapper.text()).toContain('Versão');
    expect(wrapper.text()).toContain('Hora do servidor');
  });
});
