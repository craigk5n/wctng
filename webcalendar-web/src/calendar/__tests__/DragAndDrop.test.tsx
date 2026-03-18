import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, waitFor } from '@testing-library/react';

// Track FullCalendar props
let lastFcProps: Record<string, unknown> | null = null;

vi.mock('@fullcalendar/react', async () => {
  const React = await import('react');
  const MockFC = React.forwardRef((props: Record<string, unknown>, _ref: unknown) => {
    lastFcProps = props;
    return React.createElement('div', { 'data-testid': 'fc' });
  });
  return { default: MockFC };
});
vi.mock('@fullcalendar/daygrid', () => ({ default: {} }));
vi.mock('@fullcalendar/timegrid', () => ({ default: {} }));
vi.mock('@fullcalendar/list', () => ({ default: {} }));
vi.mock('@fullcalendar/interaction', () => ({ default: {} }));
vi.mock('@fullcalendar/multimonth', () => ({ default: {} }));
vi.mock('../useCalendarEvents', () => ({
  fetchCalendarEvents: vi.fn().mockResolvedValue([]),
}));
vi.mock('../useKeyboardShortcuts', () => ({
  useKeyboardShortcuts: vi.fn(),
}));
vi.mock('../useCategories', () => ({
  useCategories: () => ({ categories: [] }),
  getEventColor: () => '#3788d8',
}));

import { FullCalendarWrapper } from '../FullCalendarWrapper';

describe('Drag and Drop', () => {
  beforeEach(() => {
    lastFcProps = null;
  });

  it('sets editable to true when user and onEventDrop provided', async () => {
    render(
      <FullCalendarWrapper
        currentUserLogin="admin"
        onEventDrop={vi.fn()}
      />,
    );

    await waitFor(() => {
      expect(lastFcProps).not.toBeNull();
    });
    expect(lastFcProps!.editable).toBe(true);
  });

  it('sets editable to false when no currentUserLogin', () => {
    render(<FullCalendarWrapper />);

    expect(lastFcProps).not.toBeNull();
    expect(lastFcProps!.editable).toBe(false);
  });

  it('passes eventDrop handler', () => {
    render(
      <FullCalendarWrapper currentUserLogin="admin" onEventDrop={vi.fn()} />,
    );

    expect(typeof lastFcProps!.eventDrop).toBe('function');
  });

  it('passes eventAllow function', () => {
    render(
      <FullCalendarWrapper currentUserLogin="admin" onEventDrop={vi.fn()} />,
    );

    expect(typeof lastFcProps!.eventAllow).toBe('function');
  });
});
