import type { EventInput } from '@fullcalendar/core';
import { api } from '../api/client';
import { mapApiEventsToFullCalendar, type ApiEvent } from './eventMapper';

/**
 * Fetches calendar events from the API for a date range
 * and transforms them to FullCalendar format.
 *
 * This is a standalone async function (not a hook) so it can be
 * used by FullCalendarWrapper's datesSet callback and also tested independently.
 */
export async function fetchCalendarEvents(
  startDate: string,
  endDate: string,
): Promise<EventInput[]> {
  try {
    const result = await api.GET('/events' as never, {
      params: { query: { start: startDate, end: endDate } },
    } as never);

    const data = result.data as { data: ApiEvent[]; meta: unknown } | undefined;

    if (data?.data && Array.isArray(data.data)) {
      return mapApiEventsToFullCalendar(data.data);
    }

    return [];
  } catch {
    return [];
  }
}
