import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { AdminSettingsPage } from '../AdminSettingsPage';
import { ToastProvider } from '../../components/toast/ToastProvider';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function renderPage() {
  return render(
    <ToastProvider>
      <AdminSettingsPage />
    </ToastProvider>,
  );
}

describe('AdminSettingsPage', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders heading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: {
          ALLOW_HTML_DESCRIPTION: 'Y',
          DISABLE_LOCATION_FIELD: 'N',
          DISABLE_URL_FIELD: 'N',
          DISABLE_PRIORITY_FIELD: 'N',
          DISABLE_PARTICIPANTS_FIELD: 'N',
        },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /system settings/i })).toBeInTheDocument();
    });
  });

  it('shows feature toggles', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: {
          ALLOW_HTML_DESCRIPTION: 'Y',
          DISABLE_LOCATION_FIELD: 'N',
          DISABLE_URL_FIELD: 'N',
          DISABLE_PRIORITY_FIELD: 'N',
          DISABLE_PARTICIPANTS_FIELD: 'N',
        },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('Rich Text Descriptions')).toBeInTheDocument();
      expect(screen.getByText('Location Field')).toBeInTheDocument();
      expect(screen.getByText('Participants')).toBeInTheDocument();
    });
  });

  it('shows checkboxes for each feature', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: {
          ALLOW_HTML_DESCRIPTION: 'Y',
          DISABLE_LOCATION_FIELD: 'N',
          DISABLE_URL_FIELD: 'N',
          DISABLE_PRIORITY_FIELD: 'N',
          DISABLE_PARTICIPANTS_FIELD: 'N',
        },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      const checkboxes = screen.getAllByRole('checkbox');
      expect(checkboxes.length).toBeGreaterThanOrEqual(5);
    });
  });
});
