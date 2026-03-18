import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { ApiTokenSettings } from '../ApiTokenSettings';
import { ToastProvider } from '../../components/toast/ToastProvider';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

vi.mock('../../auth/auth-context', () => ({
  useAuth: () => ({ user: { login: 'admin', is_admin: true } }),
}));

function renderPage() {
  return render(<ToastProvider><ApiTokenSettings /></ToastProvider>);
}

describe('ApiTokenSettings', () => {
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
      expect(screen.getByRole('heading', { name: /api tokens/i })).toBeInTheDocument();
    });
  });

  it('shows generate button', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /generate/i })).toBeInTheDocument();
    });
  });

  it('shows MCP connection instructions', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('MCP Connection Instructions')).toBeInTheDocument();
    });
  });

  it('shows existing token info', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: [{ key: 'api_token', value: 'abc***' }],
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText(/active/i)).toBeInTheDocument();
    });
  });
});
