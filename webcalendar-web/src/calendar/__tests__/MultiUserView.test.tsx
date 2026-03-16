import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { EventDetailDialog } from '../EventDetailDialog';
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

describe('Multi-User Calendar View', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('shows event owner in detail dialog when event is from another user', () => {
    const event = {
      id: 1,
      title: 'Alice Meeting',
      description: '',
      start_date: '20260315',
      start_time: '100000',
      end_date: '20260315',
      end_time: '110000',
      duration: 60,
      location: '',
      access: 'P',
      type: 'E',
      created_by: 'alice',
      all_day: false,
    };

    render(
      <EventDetailDialog
        event={event}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        currentUserLogin="admin"
      />,
    );

    // Should show "Calendar:" label with the owner name
    expect(screen.getByText('Calendar:')).toBeInTheDocument();
    // The owner "alice" appears both in title and in Calendar field
    const calendarLabel = screen.getByText('Calendar:');
    const calendarValue = calendarLabel.parentElement?.querySelector('span:last-child');
    expect(calendarValue?.textContent).toBe('alice');
  });

  it('does not show owner label when event is own', () => {
    const event = {
      id: 1,
      title: 'My Meeting',
      description: '',
      start_date: '20260315',
      start_time: '100000',
      end_date: '20260315',
      end_time: '110000',
      duration: 60,
      location: '',
      access: 'P',
      type: 'E',
      created_by: 'admin',
      all_day: false,
    };

    render(
      <EventDetailDialog
        event={event}
        open={true}
        onClose={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
        currentUserLogin="admin"
      />,
    );

    // Should NOT show "Calendar:" label for own events
    expect(screen.queryByText(/^Calendar:$/)).not.toBeInTheDocument();
  });

  it('layer toggle calls onLayersChange immediately', async () => {
    const user = userEvent.setup();
    const onLayersChange = vi.fn();

    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [{ id: 1, source_user: 'alice', color: '#FF0000', show_duplicates: false }],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    render(<LayerPanel onLayersChange={onLayersChange} />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
    });

    // Toggle the layer off
    const checkbox = screen.getByRole('checkbox');
    await user.click(checkbox);

    // onLayersChange should have been called with visible=false
    const lastCall = onLayersChange.mock.calls[onLayersChange.mock.calls.length - 1][0];
    const aliceLayer = lastCall.find((l: { source_user: string }) => l.source_user === 'alice');
    expect(aliceLayer.visible).toBe(false);

    // Toggle back on
    await user.click(checkbox);
    const lastCall2 = onLayersChange.mock.calls[onLayersChange.mock.calls.length - 1][0];
    const aliceLayer2 = lastCall2.find((l: { source_user: string }) => l.source_user === 'alice');
    expect(aliceLayer2.visible).toBe(true);
  });
});
