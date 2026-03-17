import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LanguageSelector } from '../LanguageSelector';

// Mock i18n
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    i18n: {
      language: 'en',
      changeLanguage: vi.fn(),
    },
  }),
}));

vi.mock('../../../i18n', () => ({
  changeLocale: vi.fn(),
  getLocale: () => 'en',
}));

describe('LanguageSelector', () => {
  it('renders a language selector', () => {
    render(<LanguageSelector />);
    expect(screen.getByTitle(/language/i)).toBeInTheDocument();
  });

  it('shows current language code', () => {
    render(<LanguageSelector />);
    expect(screen.getByText('EN')).toBeInTheDocument();
  });

  it('shows dropdown with language options on click', async () => {
    const user = userEvent.setup();
    render(<LanguageSelector />);

    await user.click(screen.getByTitle(/language/i));

    expect(screen.getByText('English')).toBeInTheDocument();
    expect(screen.getByText('Français')).toBeInTheDocument();
    expect(screen.getByText('Deutsch')).toBeInTheDocument();
    expect(screen.getByText('Español')).toBeInTheDocument();
  });

  it('includes RTL languages', async () => {
    const user = userEvent.setup();
    render(<LanguageSelector />);

    await user.click(screen.getByTitle(/language/i));

    expect(screen.getByText('العربية')).toBeInTheDocument();
    expect(screen.getByText('עברית')).toBeInTheDocument();
  });
});
