import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

// We test that the FullCalendarWrapper accepts 'multiMonthYear' as initialView
// and that the view switcher toolbar includes the year option.

vi.mock('@fullcalendar/react', () => ({
  default: ({ initialView, headerToolbar }: { initialView: string; headerToolbar: { right: string } }) => (
    <div data-testid="fullcalendar" data-view={initialView}>
      <div data-testid="toolbar-right">{headerToolbar.right}</div>
    </div>
  ),
}));
vi.mock('@fullcalendar/daygrid', () => ({ default: {} }));
vi.mock('@fullcalendar/timegrid', () => ({ default: {} }));
vi.mock('@fullcalendar/list', () => ({ default: {} }));
vi.mock('@fullcalendar/interaction', () => ({ default: {} }));
vi.mock('@fullcalendar/multimonth', () => ({ default: {} }));

// Mock the hooks used by FullCalendarWrapper
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

describe('Year View', () => {
  it('accepts multiMonthYear as initial view', () => {
    render(<FullCalendarWrapper initialView="multiMonthYear" />);

    const fc = screen.getByTestId('fullcalendar');
    expect(fc.getAttribute('data-view')).toBe('multiMonthYear');
  });

  it('includes multiMonthYear in toolbar buttons', () => {
    render(<FullCalendarWrapper />);

    const toolbar = screen.getByTestId('toolbar-right');
    expect(toolbar.textContent).toContain('multiMonthYear');
  });
});
