import { describe, expect, it } from 'vitest';

import { detectLocale } from './index';

describe('detectLocale', () => {
  it('detects Portuguese from a regional tag', () => {
    expect(detectLocale(['pt-BR'])).toBe('pt-BR');
    expect(detectLocale(['pt-PT', 'en'])).toBe('pt-BR');
  });

  it('falls back to English for anything unsupported', () => {
    expect(detectLocale(['de-DE', 'fr'])).toBe('en');
    expect(detectLocale([])).toBe('en');
  });
});
