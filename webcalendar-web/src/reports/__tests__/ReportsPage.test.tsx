import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { AuthProvider } from '../../auth/AuthProvider';
import { ReportsPage } from '../ReportsPage';

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={qc}>
          <AuthProvider>{children}</AuthProvider>
        </QueryClientProvider>
      </MemoryRouter>
    );
  };
}

describe('ReportsPage', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders reports heading', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<ReportsPage />, { wrapper: createWrapper() });
    expect(screen.getByRole('heading', { name: /reports/i })).toBeInTheDocument();
  });

  it('has date range selectors', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<ReportsPage />, { wrapper: createWrapper() });
    expect(screen.getByLabelText(/from/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/to/i)).toBeInTheDocument();
  });

  it('shows report sections after loading', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<ReportsPage />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/activity/i)).toBeInTheDocument();
      expect(screen.getByText(/busy hours/i)).toBeInTheDocument();
      expect(screen.getByText(/categories/i)).toBeInTheDocument();
    });
  });

  it('shows "no data" for empty reports', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<ReportsPage />, { wrapper: createWrapper() });

    await waitFor(() => {
      const noDataElements = screen.getAllByText(/no data|no categorized/i);
      expect(noDataElements.length).toBeGreaterThanOrEqual(2);
    });
  });
});
