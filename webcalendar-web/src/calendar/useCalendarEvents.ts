import type { EventInput } from '@fullcalendar/core';
import { apiFetch } from '../api/client';
import { mapApiEventsToFullCalendar, type ApiEvent } from './eventMapper';
import { mapTasksToFullCalendar, type ApiTask } from './taskMapper';
import { mapJournalsToFullCalendar, type ApiJournal } from './journalMapper';

/**
 * Fetches calendar events, tasks, and journals from the API for a date range
 * and transforms them to FullCalendar format.
 */
export async function fetchCalendarEvents(
  startDate: string,
  endDate: string,
  includeLayers = false,
): Promise<EventInput[]> {
  const layerParam = includeLayers ? '&layers=1' : '';

  // Fetch events, tasks, and journals in parallel
  const [eventsResult, tasksResult, journalsResult] = await Promise.all([
    apiFetch<ApiEvent[]>(`/events?start=${startDate}&end=${endDate}${layerParam}`),
    apiFetch<ApiTask[]>(`/tasks?start=${startDate}&end=${endDate}`),
    apiFetch<ApiJournal[]>(`/journals?start=${startDate}&end=${endDate}`),
  ]);

  const events = eventsResult.data && Array.isArray(eventsResult.data)
    ? mapApiEventsToFullCalendar(eventsResult.data)
    : [];

  const tasks = tasksResult.data && Array.isArray(tasksResult.data)
    ? mapTasksToFullCalendar(tasksResult.data)
    : [];

  const journals = journalsResult.data && Array.isArray(journalsResult.data)
    ? mapJournalsToFullCalendar(journalsResult.data)
    : [];

  return [...events, ...tasks, ...journals];
}
