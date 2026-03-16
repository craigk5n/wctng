import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { FullCalendarWrapper } from '../FullCalendarWrapper';

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  };
}

// Mock FullCalendar since it requires DOM APIs not available in jsdom
vi.mock('@fullcalendar/react', () => ({
  default: function MockFullCalendar(props: Record<string, unknown>) {
    return (
      <div data-testid="fullcalendar" data-plugins={String(props.plugins)} data-initial-view={String(props.initialView)}>
        FullCalendar Mock
      </div>
    );
  },
}));

describe('FullCalendarWrapper', () => {
  it('renders FullCalendar component', () => {
    render(<FullCalendarWrapper />, { wrapper: createWrapper() });

    expect(screen.getByTestId('fullcalendar')).toBeInTheDocument();
  });

  it('renders with a container element', () => {
    const { container } = render(<FullCalendarWrapper />, { wrapper: createWrapper() });

    expect(container.firstChild).toBeTruthy();
  });

  it('passes initial view to FullCalendar', () => {
    render(<FullCalendarWrapper initialView="timeGridWeek" />, { wrapper: createWrapper() });

    const fc = screen.getByTestId('fullcalendar');
    expect(fc.getAttribute('data-initial-view')).toBe('timeGridWeek');
  });
});
