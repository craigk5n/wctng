import type { EventInput } from '@fullcalendar/core';
import { apiFetch } from '../api/client';
import { mapApiEventsToFullCalendar, type ApiEvent } from './eventMapper';
import { mapTasksToFullCalendar, type ApiTask } from './taskMapper';
import { mapJournalsToFullCalendar, type ApiJournal } from './journalMapper';
import { mapSubscriptionEventsToFullCalendar, type SubscriptionEvent } from './subscriptionMapper';

interface SubscriptionInfo {
  id: number;
  name: string;
  color: string;
}

interface SubscriptionEventsResponse {
  subscription_id: number;
  events: SubscriptionEvent[];
  cached: boolean;
}

/**
 * Fetches calendar events, tasks, journals, and subscription events
 * from the API for a date range and transforms them to FullCalendar format.
 */
export async function fetchCalendarEvents(
  startDate: string,
  endDate: string,
  includeLayers = false,
): Promise<EventInput[]> {
  const layerParam = includeLayers ? '&layers=1' : '';

  // Fetch events, tasks, journals, and subscriptions list in parallel
  const [eventsResult, tasksResult, journalsResult, subsResult] = await Promise.all([
    apiFetch<ApiEvent[]>(`/events?start=${startDate}&end=${endDate}${layerParam}`),
    apiFetch<ApiTask[]>(`/tasks?start=${startDate}&end=${endDate}`),
    apiFetch<ApiJournal[]>(`/journals?start=${startDate}&end=${endDate}`),
    apiFetch<SubscriptionInfo[]>('/calendars/subscriptions'),
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

  // Fetch subscription events (each subscription separately)
  let subscriptionEvents: EventInput[] = [];
  const subs = subsResult.data ?? [];
  if (subs.length > 0) {
    const subEventPromises = subs.map((sub) =>
      apiFetch<SubscriptionEventsResponse>(`/calendars/subscriptions/${sub.id}/events`),
    );
    const subResults = await Promise.all(subEventPromises);
    for (const subResult of subResults) {
      if (subResult.data?.events) {
        subscriptionEvents = [
          ...subscriptionEvents,
          ...mapSubscriptionEventsToFullCalendar(subResult.data.events),
        ];
      }
    }
  }

  return [...events, ...tasks, ...journals, ...subscriptionEvents];
}
