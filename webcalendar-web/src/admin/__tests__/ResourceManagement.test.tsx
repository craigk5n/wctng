import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { ResourceManagement } from '../ResourceManagement';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

describe('ResourceManagement', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders heading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<ResourceManagement />);

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /rooms.*resources/i })).toBeInTheDocument();
    });
  });

  it('lists resources', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: [
          { login: 'room-a', name: 'Conference Room A', admin: 'admin', is_public: true, url: null },
          { login: 'projector-1', name: 'Projector #1', admin: 'admin', is_public: false, url: null },
        ],
        error: null,
      }),
    });

    render(<ResourceManagement />);

    await waitFor(() => {
      expect(screen.getByText('Conference Room A')).toBeInTheDocument();
      expect(screen.getByText('Projector #1')).toBeInTheDocument();
    });
  });

  it('shows empty state', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<ResourceManagement />);

    await waitFor(() => {
      expect(screen.getByText(/no resources/i)).toBeInTheDocument();
    });
  });

  it('has add resource button', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<ResourceManagement />);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /add resource/i })).toBeInTheDocument();
    });
  });
});
