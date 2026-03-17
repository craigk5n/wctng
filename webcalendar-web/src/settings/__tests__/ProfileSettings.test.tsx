import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { ProfileSettings } from '../ProfileSettings';
import { ToastProvider } from '../../components/toast/ToastProvider';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

vi.mock('../../auth/auth-context', () => ({
  useAuth: () => ({ user: { login: 'alice', is_admin: false } }),
}));

function renderPage() {
  return render(
    <ToastProvider>
      <ProfileSettings />
    </ToastProvider>,
  );
}

describe('ProfileSettings', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders profile heading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: { login: 'alice', firstname: 'Alice', lastname: 'Smith', email: 'alice@example.com', is_admin: false },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /profile/i })).toBeInTheDocument();
    });
  });

  it('loads and displays user info', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: { login: 'alice', firstname: 'Alice', lastname: 'Smith', email: 'alice@example.com', is_admin: false },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByDisplayValue('Alice')).toBeInTheDocument();
      expect(screen.getByDisplayValue('Smith')).toBeInTheDocument();
      expect(screen.getByDisplayValue('alice@example.com')).toBeInTheDocument();
    });
  });

  it('shows password change fields', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: { login: 'alice', firstname: 'Alice', lastname: 'Smith', email: 'alice@example.com', is_admin: false },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByLabelText(/current password/i)).toBeInTheDocument();
      expect(screen.getByLabelText(/^new password$/i)).toBeInTheDocument();
    });
  });

  it('has save button', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: { login: 'alice', firstname: 'Alice', lastname: 'Smith', email: 'alice@example.com', is_admin: false },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /save profile/i })).toBeInTheDocument();
    });
  });
});
