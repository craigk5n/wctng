import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import HttpBackend from 'i18next-http-backend';

const savedLocale = localStorage.getItem('wctng_locale') ?? 'en';

void i18n
  .use(HttpBackend)
  .use(initReactI18next)
  .init({
    lng: savedLocale,
    fallbackLng: 'en',
    supportedLngs: ['en', 'fr', 'de', 'es'],
    interpolation: {
      escapeValue: false, // React already escapes
    },
    backend: {
      loadPath: '/locales/{{lng}}.json',
    },
  });

export default i18n;

/**
 * Changes the active locale and persists to localStorage.
 */
export function changeLocale(locale: string): void {
  localStorage.setItem('wctng_locale', locale);
  void i18n.changeLanguage(locale);
}

/**
 * Returns the current active locale.
 */
export function getLocale(): string {
  return i18n.language ?? 'en';
}
