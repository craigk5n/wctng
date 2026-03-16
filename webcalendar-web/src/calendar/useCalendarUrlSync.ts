import { useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';

const VALID_VIEWS = ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek'] as const;
const DEFAULT_VIEW = 'dayGridMonth';

type CalendarView = (typeof VALID_VIEWS)[number];

interface CalendarParams {
  view: CalendarView;
  date: string | null;
}

export function parseCalendarParams(params: URLSearchParams): CalendarParams {
  const viewParam = params.get('view');
  const view = VALID_VIEWS.includes(viewParam as CalendarView)
    ? (viewParam as CalendarView)
    : DEFAULT_VIEW;
  const date = params.get('date');

  return { view, date };
}

export function buildCalendarUrl(view: string, date: string | null): string {
  const params = new URLSearchParams();
  params.set('view', view);
  if (date) {
    params.set('date', date);
  }
  return `?${params.toString()}`;
}

/**
 * Hook that syncs FullCalendar view and date with URL search params.
 * Enables browser back/forward navigation of calendar state.
 */
export function useCalendarUrlSync() {
  const [searchParams, setSearchParams] = useSearchParams();
  const { view, date } = parseCalendarParams(searchParams);

  const updateUrl = useCallback(
    (newView: string, newDate: string | null) => {
      const params = new URLSearchParams();
      params.set('view', newView);
      if (newDate) {
        params.set('date', newDate);
      }
      setSearchParams(params, { replace: true });
    },
    [setSearchParams],
  );

  return { view, date, updateUrl };
}
