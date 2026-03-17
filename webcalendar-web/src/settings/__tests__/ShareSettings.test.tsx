import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ShareSettings } from '../ShareSettings';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

Object.assign(navigator, {
  clipboard: { writeText: vi.fn().mockResolvedValue(undefined) },
});

describe('ShareSettings', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders share settings heading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<ShareSettings />);

    expect(screen.getByRole('heading', { name: /share calendar/i })).toBeInTheDocument();
  });

  it('lists existing share tokens', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { id: 1, token: 'abc-123-xyz', owner_login: 'alice', expires_at: null, created_at: '2026-03-17' },
          { id: 2, token: 'def-456-uvw', owner_login: 'alice', expires_at: '2026-12-31', created_at: '2026-03-17' },
        ],
        error: null,
      }),
    });

    render(<ShareSettings />);

    await waitFor(() => {
      // Tokens appear in code elements (and may appear in embed snippets too)
      expect(screen.getAllByText(/abc-123-xyz/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/def-456-uvw/).length).toBeGreaterThan(0);
    });
  });

  it('shows embed snippet option', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { id: 1, token: 'embed-test-token', owner_login: 'alice', expires_at: null, created_at: '2026-03-17' },
        ],
        error: null,
      }),
    });

    render(<ShareSettings />);

    await waitFor(() => {
      expect(screen.getAllByText(/embed-test-token/).length).toBeGreaterThan(0);
    });

    // Embed Code summary should be present
    expect(screen.getByText(/embed code/i)).toBeInTheDocument();
  });

  it('creates a new share token', async () => {
    const user = userEvent.setup();

    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: [], error: null }),
    });

    render(<ShareSettings />);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /create share link/i })).toBeInTheDocument();
    });

    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 201,
      json: async () => ({
        data: { id: 1, token: 'brand-new-token', owner_login: 'alice', expires_at: null, created_at: '2026-03-17' },
        error: null,
      }),
    });

    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { id: 1, token: 'brand-new-token', owner_login: 'alice', expires_at: null, created_at: '2026-03-17' },
        ],
        error: null,
      }),
    });

    await user.click(screen.getByRole('button', { name: /create share link/i }));

    await waitFor(() => {
      // Token appears in code element and embed snippet - just check it exists
      expect(screen.getAllByText(/brand-new-token/).length).toBeGreaterThan(0);
    });
  });
});
