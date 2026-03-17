import { describe, it, expect, beforeEach, vi } from 'vitest';
import i18n from 'i18next';

// Use actual react-i18next for this test (not the global mock)
vi.unmock('react-i18next');
vi.unmock('../i18n');
const { initReactI18next } = await import('react-i18next');

// Initialize i18n with inline resources for testing
beforeEach(async () => {
  await i18n
    .use(initReactI18next)
    .init({
      lng: 'en',
      fallbackLng: 'en',
      resources: {
        en: {
          translation: {
            'nav.calendar': 'Calendar',
            'event.title': 'Title',
            'common.save': 'Save',
          },
        },
        fr: {
          translation: {
            'nav.calendar': 'Calendrier',
            'event.title': 'Titre',
            'common.save': 'Enregistrer',
          },
        },
        de: {
          translation: {
            'nav.calendar': 'Kalender',
            'event.title': 'Titel',
            'common.save': 'Speichern',
          },
        },
      },
    });
});

describe('i18n', () => {
  it('defaults to English', () => {
    expect(i18n.t('nav.calendar')).toBe('Calendar');
    expect(i18n.t('common.save')).toBe('Save');
  });

  it('switches to French', async () => {
    await i18n.changeLanguage('fr');
    expect(i18n.t('nav.calendar')).toBe('Calendrier');
    expect(i18n.t('common.save')).toBe('Enregistrer');
  });

  it('switches to German', async () => {
    await i18n.changeLanguage('de');
    expect(i18n.t('nav.calendar')).toBe('Kalender');
    expect(i18n.t('event.title')).toBe('Titel');
  });

  it('falls back to English for missing keys', async () => {
    await i18n.changeLanguage('fr');
    // Key that doesn't exist in fr should fall back to en
    expect(i18n.t('nonexistent.key')).toBe('nonexistent.key');
  });
});
