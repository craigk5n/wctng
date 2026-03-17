import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { changeLocale } from '../../i18n';

interface LocaleOption {
  code: string;
  name: string;
  rtl: boolean;
}

const LOCALES: LocaleOption[] = [
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

export function LanguageSelector() {
  const { i18n } = useTranslation();
  const [open, setOpen] = useState(false);

  const handleSelect = (code: string) => {
    changeLocale(code);
    setOpen(false);

    // Set document direction for RTL
    const rtl = isRtlLocale(code);
    document.documentElement.dir = rtl ? 'rtl' : 'ltr';
    document.documentElement.lang = code;
  };

  const currentCode = i18n.language?.toUpperCase().slice(0, 2) ?? 'EN';

  return (
    <div className="relative">
      <button
        type="button"
        title="Language"
        onClick={() => setOpen(!open)}
        className="inline-flex h-8 items-center gap-1 rounded-md border border-input px-2 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground"
      >
        <GlobeIcon />
        {currentCode}
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <div className="absolute end-0 top-full z-50 mt-1 w-40 rounded-md border bg-card py-1 shadow-lg">
            {LOCALES.map((locale) => (
              <button
                key={locale.code}
                onClick={() => handleSelect(locale.code)}
                className={`flex w-full items-center gap-2 px-3 py-1.5 text-sm hover:bg-accent ${
                  i18n.language === locale.code ? 'bg-accent/50 font-medium' : ''
                }`}
              >
                <span className="w-5 text-xs text-muted-foreground">{locale.code}</span>
                <span>{locale.name}</span>
              </button>
            ))}
          </div>
        </>
      )}
    </div>
  );
}

function GlobeIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="10" />
      <line x1="2" y1="12" x2="22" y2="12" />
      <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
    </svg>
  );
}
