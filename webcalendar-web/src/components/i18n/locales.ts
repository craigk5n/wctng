/**
 * Locale table and RTL helper, split out of LanguageSelector.tsx so that file
 * exports only its component — react-refresh/only-export-components warns
 * otherwise, since a mixed module breaks Fast Refresh.
 */

export interface LocaleOption {
  code: string;
  name: string;
  rtl: boolean;
}

export const LOCALES: LocaleOption[] = [
  { code: 'en', name: 'English', rtl: false },
  { code: 'fr', name: 'Français', rtl: false },
  { code: 'de', name: 'Deutsch', rtl: false },
  { code: 'es', name: 'Español', rtl: false },
  { code: 'ar', name: 'العربية', rtl: true },
  { code: 'he', name: 'עברית', rtl: true },
];

const RTL_LOCALES = new Set(LOCALES.filter((l) => l.rtl).map((l) => l.code));

export function isRtlLocale(locale: string): boolean {
  return RTL_LOCALES.has(locale);
}
