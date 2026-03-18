import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { ViewSwitcher } from '../ViewSwitcher';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

describe('ViewSwitcher', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders nothing when no saved views', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({ data: [], error: null }),
    });

    const { container } = render(<ViewSwitcher onViewChange={vi.fn()} />);

    // Wait for fetch
    await waitFor(() => expect(mockFetch).toHaveBeenCalled());
    // Should render nothing
    expect(container.querySelector('select')).toBeNull();
  });

  it('lists saved views', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: [
          { id: 1, name: 'Team A', user_logins: ['alice', 'bob'] },
          { id: 2, name: 'Project X', user_logins: ['carol', 'dave'] },
        ],
        error: null,
      }),
    });

    render(<ViewSwitcher onViewChange={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getByText('Team A')).toBeInTheDocument();
      expect(screen.getByText('Project X')).toBeInTheDocument();
    });
  });
});
