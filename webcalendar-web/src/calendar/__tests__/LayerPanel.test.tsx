import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { LayerPanel } from '../LayerPanel';

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

const mockLayers = [
  { id: 1, source_user: 'alice', color: '#FF0000', show_duplicates: false },
  { id: 2, source_user: 'bob', color: '#00FF00', show_duplicates: false },
];

describe('LayerPanel', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders layer section heading', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<LayerPanel onLayersChange={() => {}} />, { wrapper: createWrapper() });
    expect(screen.getByText(/layers/i)).toBeInTheDocument();
  });

  it('displays active layers with color and user name', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: mockLayers }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<LayerPanel onLayersChange={() => {}} />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
      expect(screen.getByText('bob')).toBeInTheDocument();
    });
  });

  it('has visibility toggles per layer', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: mockLayers }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<LayerPanel onLayersChange={() => {}} />, { wrapper: createWrapper() });

    await waitFor(() => {
      const checkboxes = screen.getAllByRole('checkbox');
      expect(checkboxes.length).toBeGreaterThanOrEqual(2);
      // Layers should be visible (checked) by default
      expect(checkboxes[0]).toBeChecked();
    });
  });

  it('calls onLayersChange when toggle changes', async () => {
    const user = userEvent.setup();
    const onLayersChange = vi.fn();

    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: mockLayers }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<LayerPanel onLayersChange={onLayersChange} />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
    });

    const checkboxes = screen.getAllByRole('checkbox');
    await user.click(checkboxes[0]);

    // Should have been called with updated visibility
    expect(onLayersChange).toHaveBeenCalled();
  });

  it('has add layer form with user input and color picker', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<LayerPanel onLayersChange={() => {}} />, { wrapper: createWrapper() });

    expect(screen.getByPlaceholderText(/username/i)).toBeInTheDocument();
  });

  it('has remove button for each layer', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: mockLayers }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<LayerPanel onLayersChange={() => {}} />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
    });

    const removeButtons = screen.getAllByRole('button', { name: /remove|delete|✕|×/i });
    expect(removeButtons.length).toBeGreaterThanOrEqual(2);
  });
});
