import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { WebhookManagement } from '../WebhookManagement';

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <ToastProvider>{children}</ToastProvider>
      </QueryClientProvider>
    );
  };
}

describe('WebhookManagement', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders heading', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<WebhookManagement />, { wrapper: createWrapper() });
    expect(screen.getByText(/webhooks/i)).toBeInTheDocument();
  });

  it('shows webhooks list', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({
        data: [
          { id: 1, url: 'https://example.com/hook', events: '*', enabled: true },
          { id: 2, url: 'https://other.com/hook', events: 'event.created', enabled: false },
        ],
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<WebhookManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('https://example.com/hook')).toBeInTheDocument();
      expect(screen.getByText('https://other.com/hook')).toBeInTheDocument();
    });
  });

  it('has create button', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<WebhookManagement />, { wrapper: createWrapper() });
    expect(screen.getByRole('button', { name: /new webhook/i })).toBeInTheDocument();
  });

  it('shows create form when button clicked', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<WebhookManagement />, { wrapper: createWrapper() });
    await user.click(screen.getByRole('button', { name: /new webhook/i }));

    expect(screen.getByLabelText(/url/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/events/i)).toBeInTheDocument();
  });

  it('shows empty state', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );

    render(<WebhookManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/no webhooks/i)).toBeInTheDocument();
    });
  });
});
