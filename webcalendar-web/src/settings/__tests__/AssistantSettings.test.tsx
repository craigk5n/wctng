import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { AssistantSettings } from '../AssistantSettings';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

vi.mock('../../auth/auth-context', () => ({
  useAuth: () => ({ user: { login: 'alice', is_admin: false } }),
}));

describe('AssistantSettings', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders heading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: { assistants: [], bosses: [] }, error: null }),
    });

    render(<AssistantSettings />);
    expect(screen.getByRole('heading', { name: /assistant management/i })).toBeInTheDocument();
  });

  it('lists current assistants', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: {
          assistants: [{ login: 'bob' }, { login: 'carol' }],
          bosses: [{ login: 'dave' }],
        },
        error: null,
      }),
    });

    render(<AssistantSettings />);

    await waitFor(() => {
      expect(screen.getByText('bob')).toBeInTheDocument();
      expect(screen.getByText('carol')).toBeInTheDocument();
    });
  });

  it('shows bosses section', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: {
          assistants: [],
          bosses: [{ login: 'dave' }],
        },
        error: null,
      }),
    });

    render(<AssistantSettings />);

    await waitFor(() => {
      expect(screen.getByText('dave')).toBeInTheDocument();
      expect(screen.getByText(/you assist/i)).toBeInTheDocument();
    });
  });

  it('has add assistant input', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: { assistants: [], bosses: [] }, error: null }),
    });

    render(<AssistantSettings />);

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/username/i)).toBeInTheDocument();
    });
  });
});
