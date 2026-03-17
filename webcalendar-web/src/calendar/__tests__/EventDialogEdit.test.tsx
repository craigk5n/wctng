import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { EventDialog } from '../EventDialog';
import { apiEventToInitialValues } from '../eventDialogHelpers';

const originalFetch = globalThis.fetch;

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

function mockFetchCategories() {
  globalThis.fetch = vi.fn().mockResolvedValue(
    new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
  );
}
import type { ApiEvent } from '../eventMapper';

const existingEvent: ApiEvent = {
  id: 42,
  title: 'Existing Meeting',
  description: 'Weekly sync',
  start_date: '20260415',
  start_time: '140000',
  end_date: '20260415',
  end_time: '150000',
  duration: 60,
  location: 'Room B',
  access: 'C',
  type: 'E',
  created_by: 'admin',
  all_day: false,
};

const allDayExisting: ApiEvent = {
  ...existingEvent,
  id: 43,
  title: 'All Day Existing',
  start_time: null,
  end_time: null,
  duration: 0,
  all_day: true,
};

describe('apiEventToInitialValues', () => {
  it('converts API event to dialog initial values', () => {
    const vals = apiEventToInitialValues(existingEvent);

    expect(vals.title).toBe('Existing Meeting');
    expect(vals.description).toBe('Weekly sync');
    expect(vals.location).toBe('Room B');
    expect(vals.access).toBe('C');
    expect(vals.duration).toBe(60);
    expect(vals.start_date_display).toBe('2026-04-15');
    expect(vals.start_time_display).toBe('14:00');
  });

  it('handles all-day event', () => {
    const vals = apiEventToInitialValues(allDayExisting);

    expect(vals.start_time_display).toBe('');
    expect(vals.start_date_display).toBe('2026-04-15');
  });
});

describe('EventDialog - Edit', () => {
  afterEach(() => { globalThis.fetch = originalFetch; });

  it('pre-fills all fields from existing event', () => {
    mockFetchCategories();
    const vals = apiEventToInitialValues(existingEvent);

    render(
      <EventDialog
        open={true}
        onClose={() => {}}
        onSave={vi.fn().mockResolvedValue(true)}
        mode="edit"
        initialDate={vals.start_date_display}
        initialTime={vals.start_time_display}
        initialAllDay={existingEvent.all_day}
        initialValues={vals}
      />,
      { wrapper: createWrapper() },
    );

    expect(screen.getByLabelText(/title/i)).toHaveValue('Existing Meeting');
    expect(screen.getByLabelText(/date/i)).toHaveValue('2026-04-15');
    expect(screen.getByLabelText(/start time/i)).toHaveValue('14:00');
    expect(screen.getByLabelText(/location/i)).toHaveValue('Room B');
    // Description is now in a TipTap rich text editor (contenteditable)
    expect(screen.getByText('Weekly sync')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /edit event/i })).toBeInTheDocument();
  });

  it('shows Save Changes button in edit mode', () => {
    mockFetchCategories();
    const vals = apiEventToInitialValues(existingEvent);

    render(
      <EventDialog
        open={true}
        onClose={() => {}}
        onSave={vi.fn().mockResolvedValue(true)}
        mode="edit"
        initialValues={vals}
      />,
      { wrapper: createWrapper() },
    );

    expect(screen.getByRole('button', { name: /save changes/i })).toBeInTheDocument();
  });

  it('calls onSave with updated data', async () => {
    mockFetchCategories();
    const user = userEvent.setup();
    const onSave = vi.fn().mockResolvedValue(true);
    const vals = apiEventToInitialValues(existingEvent);

    render(
      <EventDialog
        open={true}
        onClose={() => {}}
        onSave={onSave}
        mode="edit"
        initialDate={vals.start_date_display}
        initialTime={vals.start_time_display}
        initialAllDay={false}
        initialValues={vals}
      />,
      { wrapper: createWrapper() },
    );

    const titleInput = screen.getByLabelText(/title/i);
    await user.clear(titleInput);
    await user.type(titleInput, 'Updated Title');
    await user.click(screen.getByRole('button', { name: /save changes/i }));

    expect(onSave).toHaveBeenCalledWith(
      expect.objectContaining({
        title: 'Updated Title',
        start_date: '20260415',
      }),
    );
  });
});
