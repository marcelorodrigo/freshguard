import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import App from './App.vue';
import { ApiError, getHealth } from './api';

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>();

  return { ...actual, getHealth: vi.fn() };
});

const getHealthMock = vi.mocked(getHealth);

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

    const wrapper = mount(App);
    expect(wrapper.text()).toContain('Checking the server');
    await flushPromises();

    expect(wrapper.get('[data-test="status"]').text()).toBe('ok');
    expect(wrapper.get('[data-test="version"]').text()).toBe('1.0.0');
    expect(wrapper.get('[data-test="time"]').text()).toBe('2026-01-24T08:27:26Z');
  });

  it('shows the failure when the server is unreachable', async () => {
    getHealthMock.mockRejectedValue(new ApiError(503, 'unavailable', 'upstream down'));

    const wrapper = mount(App);
    await flushPromises();

    expect(wrapper.get('[data-test="error"]').text()).toContain('upstream down');
    expect(wrapper.find('[data-test="version"]').exists()).toBe(false);
  });
});
