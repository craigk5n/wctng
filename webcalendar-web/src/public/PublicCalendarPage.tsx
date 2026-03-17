import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import type { DatesSetArg, EventClickArg, EventInput } from '@fullcalendar/core';
import { getApiBaseUrl } from '../api/client';
import { mapApiEventsToFullCalendar, type ApiEvent } from '../calendar/eventMapper';

interface PublicCalendar {
  username: string;
  display_name: string;
}

/**
 * Fetches from the public API (no auth required).
 */
async function publicFetch<T>(path: string): Promise<T | null> {
  const baseUrl = getApiBaseUrl();
  try {
    const res = await fetch(`${baseUrl}${path}`);
    if (!res.ok) return null;
    const body = await res.json() as { data: T };
    return body.data;
  } catch {
    return null;
  }
}

export function PublicCalendarPage() {
  const { username } = useParams<{ username: string }>();
  const [calendar, setCalendar] = useState<PublicCalendar | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [events, setEvents] = useState<EventInput[]>([]);
  const [selectedEvent, setSelectedEvent] = useState<ApiEvent | null>(null);

  // Load public calendar info
  useEffect(() => {
    if (!username) return;
    setLoading(true);

    publicFetch<PublicCalendar[]>('/public/calendars')
      .then((calendars) => {
        const found = calendars?.find((c) => c.username === username);
        if (found) {
          setCalendar(found);
          setNotFound(false);
        } else {
          setNotFound(true);
        }
      })
      .finally(() => setLoading(false));
  }, [username]);

  // Fetch events when date range changes
  const handleDatesSet = useCallback(
    async (arg: DatesSetArg) => {
      if (!username) return;
      const start = formatDate(arg.start);
      const end = formatDate(arg.end);
      const data = await publicFetch<ApiEvent[]>(
        `/public/calendars/${username}/events?start=${start}&end=${end}`,
      );
      if (data) {
        setEvents(mapApiEventsToFullCalendar(data));
      }
    },
    [username],
  );

  const handleEventClick = useCallback((arg: EventClickArg) => {
    const apiEvent = arg.event.extendedProps?.apiEvent as ApiEvent | undefined;
    if (apiEvent) {
      setSelectedEvent(apiEvent);
    }
  }, []);

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background text-muted-foreground">
        Loading...
      </div>
    );
  }

  if (notFound || !calendar) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center bg-background">
        <h1 className="text-4xl font-bold text-foreground">404</h1>
        <p className="mt-2 text-muted-foreground">Public calendar not found</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background">
      <header className="border-b bg-card px-4 py-3">
        <div className="mx-auto max-w-6xl">
          <h1 className="text-xl font-semibold text-foreground">
            {calendar.display_name}&apos;s Calendar
          </h1>
        </div>
      </header>

      <main className="mx-auto max-w-6xl p-4">
        <FullCalendar
          plugins={[dayGridPlugin, timeGridPlugin, listPlugin]}
          initialView="dayGridMonth"
          headerToolbar={{
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
          }}
          events={events}
          datesSet={handleDatesSet}
          eventClick={handleEventClick}
          editable={false}
          selectable={false}
          dayMaxEvents={true}
          weekends={true}
          height="auto"
          nowIndicator={true}
        />

        {/* Event detail popup */}
        {selectedEvent && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            onClick={() => setSelectedEvent(null)}
            role="dialog"
            aria-modal="true"
          >
            <div
              className="mx-4 w-full max-w-md rounded-lg bg-card p-6 shadow-xl"
              onClick={(e) => e.stopPropagation()}
            >
              <h2 className="text-lg font-semibold text-foreground">
                {selectedEvent.title}
              </h2>

              <div className="mt-3 space-y-2 text-sm text-muted-foreground">
                <p>
                  <span className="font-medium text-foreground">When: </span>
                  {formatEventTime(selectedEvent)}
                </p>
                {selectedEvent.location && (
                  <p>
                    <span className="font-medium text-foreground">Where: </span>
                    {selectedEvent.location}
                  </p>
                )}
                {selectedEvent.description && (
                  <p>
                    <span className="font-medium text-foreground">Details: </span>
                    {selectedEvent.description}
                  </p>
                )}
              </div>

              <button
                className="mt-4 w-full rounded bg-primary px-4 py-2 text-sm text-primary-foreground hover:bg-primary/90"
                onClick={() => setSelectedEvent(null)}
              >
                Close
              </button>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}

function formatDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}${m}${d}`;
}

function formatEventTime(event: ApiEvent): string {
  const startDate = `${event.start_date.slice(0, 4)}-${event.start_date.slice(4, 6)}-${event.start_date.slice(6, 8)}`;

  if (event.all_day) {
    return new Date(startDate).toLocaleDateString();
  }

  const startTime = event.start_time
    ? `${event.start_time.slice(0, 2)}:${event.start_time.slice(2, 4)}`
    : '';
  const endTime = event.end_time
    ? `${event.end_time.slice(0, 2)}:${event.end_time.slice(2, 4)}`
    : '';

  const dateStr = new Date(startDate).toLocaleDateString();
  return endTime ? `${dateStr} ${startTime} - ${endTime}` : `${dateStr} ${startTime}`;
}
