import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SubscriptionSettings } from '../SubscriptionSettings';
import { ToastProvider } from '../../components/toast/ToastProvider';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function renderPage() {
  return render(<ToastProvider><SubscriptionSettings /></ToastProvider>);
}

describe('SubscriptionSettings', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders heading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /calendar subscriptions/i })).toBeInTheDocument();
    });
  });

  it('lists existing subscriptions', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: [
          { id: 1, user_login: 'admin', url: 'https://example.com/holidays.ics', name: 'US Holidays', color: '#ff0000', refresh_interval: 3600, last_fetched: null, etag: null },
        ],
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('US Holidays')).toBeInTheDocument();
    });
  });

  it('shows quick-add holiday options', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText(/popular calendars/i)).toBeInTheDocument();
    });
  });

  it('has add subscription form with URL and name fields', async () => {
    const user = userEvent.setup();

    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add subscription/i })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: /add subscription/i }));

    expect(screen.getByPlaceholderText(/https:\/\//i)).toBeInTheDocument();
    expect(screen.getByPlaceholderText(/display name/i)).toBeInTheDocument();
  });
});
