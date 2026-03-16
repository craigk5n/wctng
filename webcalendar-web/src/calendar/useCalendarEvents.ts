import type { EventInput } from '@fullcalendar/core';
import { apiFetch } from '../api/client';
import { mapApiEventsToFullCalendar, type ApiEvent } from './eventMapper';

/**
 * Fetches calendar events from the API for a date range
 * and transforms them to FullCalendar format.
 */
export async function fetchCalendarEvents(
  startDate: string,
  endDate: string,
  includeLayers = false,
): Promise<EventInput[]> {
  const layerParam = includeLayers ? '&layers=1' : '';
  const { data } = await apiFetch<ApiEvent[]>(`/events?start=${startDate}&end=${endDate}${layerParam}`);

  if (data && Array.isArray(data)) {
    return mapApiEventsToFullCalendar(data);
  }

  return [];
}
