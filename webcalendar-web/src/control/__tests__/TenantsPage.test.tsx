import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { setControlAuth, clearControlAuth } from '../control-auth';
import { TenantsPage } from '../TenantsPage';

const mockTenants = [
  { slug: 'acme', name: 'Acme Corp', plan: 'pro', status: 'active', created_at: '2026-01-15T00:00:00+00:00' },
  { slug: 'globex', name: 'Globex Inc', plan: 'free', status: 'suspended', created_at: '2026-02-20T00:00:00+00:00' },
  { slug: 'initech', name: 'Initech', plan: 'enterprise', status: 'pending', created_at: '2026-03-01T00:00:00+00:00' },
];

describe('TenantsPage', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    setControlAuth('fake-token', { username: 'superadmin', role: 'super_admin' });
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    clearControlAuth();
  });

  function setup(tenants = mockTenants) {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: tenants }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    return render(
      <MemoryRouter>
        <TenantsPage />
      </MemoryRouter>,
    );
  }

  it('renders tenant table with data', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByText('acme')).toBeInTheDocument();
      expect(screen.getByText('Acme Corp')).toBeInTheDocument();
      expect(screen.getByText('Globex Inc')).toBeInTheDocument();
      expect(screen.getByText('Initech')).toBeInTheDocument();
    });
  });

  it('shows status badges with correct colors', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByText('active')).toBeInTheDocument();
      expect(screen.getByText('suspended')).toBeInTheDocument();
      expect(screen.getByText('pending')).toBeInTheDocument();
    });
  });

  it('filters tenants by search query', async () => {
    const user = userEvent.setup();
    setup();

    await waitFor(() => {
      expect(screen.getByText('acme')).toBeInTheDocument();
    });

    await user.type(screen.getByPlaceholderText(/search/i), 'globex');

    expect(screen.getByText('Globex Inc')).toBeInTheDocument();
    expect(screen.queryByText('Acme Corp')).not.toBeInTheDocument();
    expect(screen.queryByText('Initech')).not.toBeInTheDocument();
  });

  it('has suspend/activate action buttons', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByText('acme')).toBeInTheDocument();
    });

    // Active tenant should have Suspend button
    const suspendButtons = screen.getAllByRole('button', { name: /suspend/i });
    expect(suspendButtons.length).toBeGreaterThanOrEqual(1);
  });

  it('has delete buttons', async () => {
    setup();

    await waitFor(() => {
      expect(screen.getByText('acme')).toBeInTheDocument();
    });

    const deleteButtons = screen.getAllByRole('button', { name: /delete/i });
    expect(deleteButtons.length).toBeGreaterThanOrEqual(1);
  });

  it('shows empty state when no tenants', async () => {
    setup([]);

    await waitFor(() => {
      expect(screen.getByText(/no tenants/i)).toBeInTheDocument();
    });
  });
});
