import type { EventInput } from '@fullcalendar/core';
import { apiFetch } from '../api/client';
import { mapApiEventsToFullCalendar, type ApiEvent } from './eventMapper';
import { mapTasksToFullCalendar, type ApiTask } from './taskMapper';

/**
 * Fetches calendar events and tasks from the API for a date range
 * and transforms them to FullCalendar format.
 */
export async function fetchCalendarEvents(
  startDate: string,
  endDate: string,
  includeLayers = false,
): Promise<EventInput[]> {
  const layerParam = includeLayers ? '&layers=1' : '';

  // Fetch events and tasks in parallel
  const [eventsResult, tasksResult] = await Promise.all([
    apiFetch<ApiEvent[]>(`/events?start=${startDate}&end=${endDate}${layerParam}`),
    apiFetch<ApiTask[]>(`/tasks?start=${startDate}&end=${endDate}`),
  ]);

  const events = eventsResult.data && Array.isArray(eventsResult.data)
    ? mapApiEventsToFullCalendar(eventsResult.data)
    : [];

  const tasks = tasksResult.data && Array.isArray(tasksResult.data)
    ? mapTasksToFullCalendar(tasksResult.data)
    : [];

  return [...events, ...tasks];
}
