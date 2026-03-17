import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { AuthProvider } from '../../auth/AuthProvider';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { NotificationSettings } from '../NotificationSettings';

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={qc}>
          <AuthProvider>
            <ToastProvider>{children}</ToastProvider>
          </AuthProvider>
        </QueryClientProvider>
      </MemoryRouter>
    );
  };
}

describe('NotificationSettings', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders notifications heading', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<NotificationSettings />, { wrapper: createWrapper() });
    expect(screen.getByRole('heading', { name: /notifications/i })).toBeInTheDocument();
  });

  it('shows save button', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<NotificationSettings />, { wrapper: createWrapper() });
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument();
  });

  it('shows notification sections after loading', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<NotificationSettings />, { wrapper: createWrapper() });

    // With no logged-in user, loading completes immediately showing the sections
    await waitFor(() => {
      expect(screen.getByText(/event invitations/i)).toBeInTheDocument();
    });
    expect(screen.getByText(/event updates/i)).toBeInTheDocument();
    expect(screen.getByText(/event reminders/i)).toBeInTheDocument();
  });

  it('has reminder time selector', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<NotificationSettings />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByLabelText(/reminder time/i)).toBeInTheDocument();
    });
  });
});
