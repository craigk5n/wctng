import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';

// Polyfill ResizeObserver for Radix primitives (Tooltip, Popover, etc.)
// which use it via @radix-ui/react-use-size. jsdom does not ship it.
if (typeof globalThis.ResizeObserver === 'undefined') {
  globalThis.ResizeObserver = class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  } as unknown as typeof ResizeObserver;
}

// Radix Tooltip calls these on triggers; jsdom doesn't implement them.
if (typeof Element !== 'undefined') {
  if (!Element.prototype.hasPointerCapture) {
    Element.prototype.hasPointerCapture = () => false;
  }
  if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = () => {};
  }
}

// Mock react-i18next globally so all components render untranslated keys as-is
vi.mock('react-i18next', async () => {
  const actual = await vi.importActual<typeof import('react-i18next')>('react-i18next');
  return {
    ...actual,
    useTranslation: () => ({
      t: (key: string) => key,
      i18n: {
        language: 'en',
        changeLanguage: vi.fn().mockResolvedValue(undefined),
      },
    }),
    // Pass through Trans component
    Trans: ({ children }: { children: React.ReactNode }) => children,
  };
});

// Mock i18n module
vi.mock('../i18n', () => ({
  default: { language: 'en', changeLanguage: vi.fn() },
  changeLocale: vi.fn(),
  getLocale: () => 'en',
}));

// Mock useToast globally so components don't need ToastProvider in tests
vi.mock('../components/toast/ToastProvider', () => ({
  useToast: () => ({ toast: vi.fn() }),
  ToastProvider: ({ children }: { children: React.ReactNode }) => children,
}));

// Mock useFeatureFlags globally
vi.mock('../hooks/useFeatureFlags', () => ({
  useFeatureFlags: () => ({
    ALLOW_HTML_DESCRIPTION: 'Y',
    DISABLE_LOCATION_FIELD: 'N',
    DISABLE_URL_FIELD: 'N',
    DISABLE_PRIORITY_FIELD: 'N',
    DISABLE_PARTICIPANTS_FIELD: 'N',
    ENABLE_SEO_PAGES: 'N',
    ENABLE_GEOCODING: 'Y',
    DISABLE_TASKS: 'N',
    DISABLE_JOURNALS: 'N',
    DISABLE_ATTACHMENTS: 'N',
    DISABLE_COMMENTS: 'N',
    ENABLE_EMAIL_REMINDERS: 'Y',
    ENABLE_DAILY_AGENDA: 'N',
  }),
}));

// Mock matchMedia for jsdom (used by ThemeProvider)
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  }),
});
