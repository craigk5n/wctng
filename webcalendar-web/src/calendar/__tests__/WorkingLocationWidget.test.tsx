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

  it('renders location toggle buttons', async () => {
    render(<WorkingLocationWidget />);

    await waitFor(() => {
      expect(screen.getByTitle('Office')).toBeInTheDocument();
      expect(screen.getByTitle('Remote')).toBeInTheDocument();
      expect(screen.getByTitle('Traveling')).toBeInTheDocument();
    });
  });

  it('highlights active location', async () => {
    render(<WorkingLocationWidget />);

    await waitFor(() => {
      const officeBtn = screen.getByTitle('Office');
      expect(officeBtn.className).toContain('text-primary');
    });
  });

  it('calls API on location change', async () => {
    const user = userEvent.setup();
    render(<WorkingLocationWidget />);

    await waitFor(() => expect(screen.getByTitle('Remote')).toBeInTheDocument());

    await user.click(screen.getByTitle('Remote'));

    // Should have made PUT call
    const putCalls = mockFetch.mock.calls.filter(
      (c) => typeof c[0] === 'string' && c[0].includes('location') && c[1]?.method === 'PUT'
    );
    expect(putCalls.length).toBeGreaterThan(0);
  });
});
