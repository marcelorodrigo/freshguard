import { createI18n } from 'vue-i18n';

import { en, type MessageSchema } from '../locales/en';
import { ptBR } from '../locales/pt-BR';

export const LOCALES = ['en', 'pt-BR'] as const;

/** The locales the SPA ships. */
export type Locale = (typeof LOCALES)[number];

const LOCALE_STORAGE_KEY = 'freshguard.locale';

declare module 'vue-i18n' {
  // Makes every t('…') call typed against the English catalogue.
  export interface DefineLocaleMessage extends MessageSchema {}
}

function browserLanguages(): readonly string[] {
  if (typeof navigator === 'undefined') {
    return [];
  }

  return navigator.languages.length > 0 ? navigator.languages : [navigator.language];
}

/** Picks the first supported locale the browser asks for, English otherwise. */
export function detectLocale(languages: readonly string[] = browserLanguages()): Locale {
  for (const language of languages) {
    const tag = language.toLowerCase();

    if (tag.startsWith('pt')) {
      return 'pt-BR';
    }

    if (tag.startsWith('en')) {
      return 'en';
    }
  }

  return 'en';
}

/** Reads the locale a previous visit stored, when storage is available. */
export function storedLocale(): Locale | null {
  if (typeof localStorage === 'undefined') {
    return null;
  }

  try {
    const value = localStorage.getItem(LOCALE_STORAGE_KEY);
    return LOCALES.includes(value as Locale) ? (value as Locale) : null;
  } catch {
    // Private modes can block storage; the detected locale still works.
    return null;
  }
}

export function initialLocale(): Locale {
  return storedLocale() ?? detectLocale();
}

/** Builds an i18n instance; tests pass a locale, the app uses the stored or browser one. */
export function createI18nInstance(locale: Locale = initialLocale()) {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: { en, 'pt-BR': ptBR },
  });
}

export const i18n = createI18nInstance();

/** Switches locale, remembers it and keeps the document language in sync. */
export function setLocale(locale: Locale): void {
  i18n.global.locale.value = locale;
  syncDocumentLanguage(locale);

  try {
    localStorage.setItem(LOCALE_STORAGE_KEY, locale);
  } catch {
    // The switch still works for this page; persistence is best effort.
  }
}

export function syncDocumentLanguage(locale: Locale = i18n.global.locale.value): void {
  if (typeof document !== 'undefined') {
    document.documentElement.lang = locale;
  }
}
