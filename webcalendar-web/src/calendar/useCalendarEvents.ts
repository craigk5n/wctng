import type { EventInput } from '@fullcalendar/core';
import { TOKEN_STORAGE_KEY } from '../api/client';
import { mapApiEventsToFullCalendar, type ApiEvent } from './eventMapper';

/**
 * Fetches calendar events from the API for a date range
 * and transforms them to FullCalendar format.
 */
export async function fetchCalendarEvents(
  startDate: string,
  endDate: string,
): Promise<EventInput[]> {
  try {
    const baseUrl = import.meta.env.VITE_API_URL ?? '/api/v2';
    const token = localStorage.getItem(TOKEN_STORAGE_KEY);
    const headers: Record<string, string> = {};
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const res = await fetch(`${baseUrl}/events?start=${startDate}&end=${endDate}`, { headers });

    if (!res.ok) return [];

    const body = await res.json();
    const events = body?.data as ApiEvent[] | undefined;

    if (events && Array.isArray(events)) {
      return mapApiEventsToFullCalendar(events);
    }

    return [];
  } catch {
    return [];
  }
}
