import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { setControlAuth, clearControlAuth } from '../control-auth';
import { TenantDetailPage } from '../TenantDetailPage';

const mockDetail = {
  slug: 'acme',
  name: 'Acme Corp',
  plan: 'pro',
  status: 'active',
  db_host: 'db-host-1',
  created_at: '2026-01-15T00:00:00+00:00',
  updated_at: '2026-03-01T00:00:00+00:00',
  user_count: 5,
  event_count: 42,
};

const mockStats = {
  slug: 'acme',
  user_count: 5,
  event_count: 42,
  task_count: 8,
  last_activity: '20260315',
};

describe('TenantDetailPage', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    setControlAuth('fake-token', { username: 'superadmin', role: 'super_admin' });
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    clearControlAuth();
  });

  function setup() {
    globalThis.fetch = vi.fn().mockImplementation((url: string) => {
      if (String(url).includes('/stats')) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: mockStats }), {
            status: 200, headers: { 'Content-Type': 'application/json' },
          }),
        );
      }
      return Promise.resolve(
        new Response(JSON.stringify({ data: mockDetail }), {
          status: 200, headers: { 'Content-Type': 'application/json' },
        }),
      );
    });

    return render(
      <MemoryRouter initialEntries={['/control/tenants/acme']}>
        <Routes>
          <Route path="/control/tenants/:slug" element={<TenantDetailPage />} />
          <Route path="/control" element={<div>Tenant List</div>} />
        </Routes>
      </MemoryRouter>,
    );
  }

  it('shows tenant details', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /acme corp/i })).toBeInTheDocument();
      expect(screen.getAllByText('acme').length).toBeGreaterThanOrEqual(1);
      expect(screen.getAllByText('pro').length).toBeGreaterThanOrEqual(1);
      expect(screen.getAllByText('active').length).toBeGreaterThanOrEqual(1);
    });
  });

  it('shows usage stats', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByText('5')).toBeInTheDocument(); // user count
      expect(screen.getByText('42')).toBeInTheDocument(); // event count
      expect(screen.getByText('8')).toBeInTheDocument(); // task count
    });
  });

  it('has suspend/activate action button', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /suspend tenant/i })).toBeInTheDocument();
    });
  });

  it('has delete action button', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /delete tenant/i })).toBeInTheDocument();
    });
  });

  it('shows edit form when Edit is clicked', async () => {
    const user = userEvent.setup();
    setup();

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /acme corp/i })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: /edit/i }));

    expect(screen.getByDisplayValue('Acme Corp')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
  });

  it('has back button', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /acme corp/i })).toBeInTheDocument();
    });

    expect(screen.getByText(/back/i)).toBeInTheDocument();
  });
});
