import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { WorkingLocationWidget } from '../WorkingLocationWidget';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

vi.mock('../../auth/auth-context', () => ({
  useAuth: () => ({ user: { login: 'admin', is_admin: true } }),
}));

describe('WorkingLocationWidget', () => {
  beforeEach(() => {
    mockFetch.mockReset();
    mockFetch.mockResolvedValue({
      ok: true, status: 200,
      json: async () => ({ data: { location: 'office' }, error: null }),
    });
  });

  it('renders location toggle buttons with labels', async () => {
    render(<WorkingLocationWidget />);

    await waitFor(() => {
      expect(screen.getByRole('radio', { name: 'Office' })).toBeInTheDocument();
      expect(screen.getByRole('radio', { name: 'Remote' })).toBeInTheDocument();
      expect(screen.getByRole('radio', { name: 'Traveling' })).toBeInTheDocument();
    });
  });

  it('highlights active location', async () => {
    render(<WorkingLocationWidget />);

    await waitFor(() => {
      const officeBtn = screen.getByRole('radio', { name: 'Office' });
      expect(officeBtn).toHaveAttribute('aria-checked', 'true');
    });
  });

  it('shows tooltip on hover', async () => {
    render(<WorkingLocationWidget />);

    await waitFor(() => {
      expect(screen.getByText(/working location/i)).toBeInTheDocument();
    });
  });

  it('calls API on location change', async () => {
    const user = userEvent.setup();
    render(<WorkingLocationWidget />);

    await waitFor(() => expect(screen.getByRole('radio', { name: 'Remote' })).toBeInTheDocument());

    await user.click(screen.getByRole('radio', { name: 'Remote' }));

    const putCalls = mockFetch.mock.calls.filter(
      (c) => typeof c[0] === 'string' && c[0].includes('location') && c[1]?.method === 'PUT'
    );
    expect(putCalls.length).toBeGreaterThan(0);
  });
});
