import { createI18n } from 'vue-i18n';

import { en, type MessageSchema } from '../locales/en';
import { ptBR } from '../locales/pt-BR';

export const LOCALES = ['en', 'pt-BR'] as const;

/** The locales the SPA ships. */
export type Locale = (typeof LOCALES)[number];

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

/** Builds an i18n instance; tests pass a locale, the app detects one. */
export function createI18nInstance(locale: Locale = detectLocale()) {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: { en, 'pt-BR': ptBR },
  });
}

export const i18n = createI18nInstance();
