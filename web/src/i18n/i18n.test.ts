import { afterEach, describe, expect, it } from 'vitest';

import {
  detectLocale,
  i18n,
  initialLocale,
  setLocale,
  storedLocale,
  syncDocumentLanguage,
} from './index';

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


describe('locale persistence', () => {
  afterEach(() => {
    localStorage.clear();
    setLocale('en');
  });

  it('remembers a chosen locale', () => {
    localStorage.clear();
    expect(storedLocale()).toBeNull();

    setLocale('pt-BR');

    expect(storedLocale()).toBe('pt-BR');
    expect(i18n.global.locale.value).toBe('pt-BR');
    expect(document.documentElement.lang).toBe('pt-BR');
  });

  it('ignores an unsupported stored value', () => {
    localStorage.setItem('freshguard.locale', 'fr');

    expect(storedLocale()).toBeNull();
    expect(initialLocale()).toBe('en');
  });

  it('falls back to the browser locale when storage is empty', () => {
    localStorage.clear();

    expect(initialLocale()).toBe(detectLocale());
  });

  it('keeps the document language in sync', () => {
    syncDocumentLanguage('pt-BR');
    expect(document.documentElement.lang).toBe('pt-BR');
  });
});
