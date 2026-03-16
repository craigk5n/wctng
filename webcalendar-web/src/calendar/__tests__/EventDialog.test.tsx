import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { EventDialog } from '../EventDialog';

const originalFetch = globalThis.fetch;

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

function renderEventDialog(props: Record<string, unknown> = {}) {
  globalThis.fetch = vi.fn().mockResolvedValue(
    new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
  );
  const defaultProps = {
    open: true,
    onClose: vi.fn(),
    onSave: vi.fn(),
    initialDate: '2026-03-15',
    initialTime: '10:00',
    initialAllDay: false,
  };
  return render(<EventDialog {...defaultProps} {...props} />, { wrapper: createWrapper() });
}

describe('EventDialog - Create', () => {
  afterEach(() => {
    globalThis.fetch = originalFetch;
  });


  it('renders all form fields', () => {
    renderEventDialog();

    expect(screen.getByLabelText(/title/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/date/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/start time/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/duration/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/location/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/description/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/all.day/i)).toBeInTheDocument();
  });

  it('pre-fills date and time from props', () => {
    renderEventDialog();

    expect(screen.getByLabelText(/date/i)).toHaveValue('2026-03-15');
    expect(screen.getByLabelText(/start time/i)).toHaveValue('10:00');
  });

  it('pre-fills as all-day when initialAllDay is true', () => {
    renderEventDialog({ initialAllDay: true, initialTime: '' });

    const allDayCheckbox = screen.getByLabelText(/all.day/i);
    expect(allDayCheckbox).toBeChecked();
    expect(screen.queryByLabelText(/start time/i)).not.toBeInTheDocument();
  });

  it('validates title is required', async () => {
    const user = userEvent.setup();
    const onSave = vi.fn();
    renderEventDialog({ onSave });

    // Clear title and submit
    await user.click(screen.getByRole('button', { name: /save|create/i }));

    // onSave should NOT have been called (title is empty)
    expect(onSave).not.toHaveBeenCalled();
  });

  it('calls onSave with event data on submit', async () => {
    const user = userEvent.setup();
    const onSave = vi.fn().mockResolvedValue(true);
    renderEventDialog({ onSave });

    await user.type(screen.getByLabelText(/title/i), 'New Meeting');
    await user.click(screen.getByRole('button', { name: /save|create/i }));

    expect(onSave).toHaveBeenCalledWith(
      expect.objectContaining({
        title: 'New Meeting',
        start_date: '20260315',
      }),
    );
  });

  it('cancel closes without calling onSave', async () => {
    const user = userEvent.setup();
    const onSave = vi.fn();
    const onClose = vi.fn();
    renderEventDialog({ onSave, onClose });

    await user.click(screen.getByRole('button', { name: /cancel/i }));

    expect(onSave).not.toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
  });

  it('toggling all-day hides time fields', async () => {
    const user = userEvent.setup();
    renderEventDialog();

    expect(screen.getByLabelText(/start time/i)).toBeInTheDocument();

    await user.click(screen.getByLabelText(/all.day/i));

    expect(screen.queryByLabelText(/start time/i)).not.toBeInTheDocument();
  });

  it('does not render when open is false', () => {
    renderEventDialog({ open: false });
    expect(screen.queryByLabelText(/title/i)).not.toBeInTheDocument();
  });

  it('shows dialog title for create mode', () => {
    renderEventDialog();
    expect(screen.getByRole('heading', { name: /new event/i })).toBeInTheDocument();
  });

  it('shows dialog title for edit mode', () => {
    renderEventDialog({ mode: 'edit' });
    expect(screen.getByText(/edit event/i)).toBeInTheDocument();
  });
});
