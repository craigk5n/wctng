import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import type { DatesSetArg, EventInput } from '@fullcalendar/core';
import { getApiBaseUrl } from '../api/client';
import { mapApiEventsToFullCalendar, type ApiEvent } from '../calendar/eventMapper';

export function EmbedCalendarPage() {
  const { token } = useParams<{ token: string }>();
  const [events, setEvents] = useState<EventInput[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [validated, setValidated] = useState(false);

  // Validate token on mount by fetching today's date range
  useEffect(() => {
    if (!token) return;
    const baseUrl = getApiBaseUrl();
    const now = new Date();
    const start = formatDate(new Date(now.getFullYear(), now.getMonth(), 1));
    const end = formatDate(new Date(now.getFullYear(), now.getMonth() + 1, 0));

    fetch(`${baseUrl}/public/shared/${token}/events?start=${start}&end=${end}`)
      .then(async (res) => {
        const body = await res.json();
        if (!res.ok) {
          setError(body?.error?.message ?? 'Failed to load');
          return;
        }
        if (body.data && Array.isArray(body.data)) {
          setEvents(mapApiEventsToFullCalendar(body.data as ApiEvent[]));
        }
        setValidated(true);
      })
      .catch(() => {
        setError('Failed to load calendar');
      });
  }, [token]);

  const handleDatesSet = useCallback(
    async (arg: DatesSetArg) => {
      if (!token || !validated) return;
      const start = formatDate(arg.start);
      const end = formatDate(arg.end);
      const baseUrl = getApiBaseUrl();

      try {
        const res = await fetch(`${baseUrl}/public/shared/${token}/events?start=${start}&end=${end}`);
        const body = await res.json();

        if (!res.ok) {
          setError(body?.error?.message ?? 'Failed to load');
          return;
        }

        if (body.data && Array.isArray(body.data)) {
          setEvents(mapApiEventsToFullCalendar(body.data as ApiEvent[]));
        }
      } catch {
        setError('Failed to load calendar');
      }
    },
    [token, validated],
  );

  if (error) {
    return (
      <div style={{ padding: '2rem', textAlign: 'center', color: '#666', fontFamily: 'sans-serif' }}>
        {error}
      </div>
    );
  }

  if (!validated) {
    return (
      <div style={{ padding: '2rem', textAlign: 'center', color: '#666', fontFamily: 'sans-serif' }}>
        Loading...
      </div>
    );
  }

  return (
    <div style={{ padding: '0.5rem' }}>
      <FullCalendar
        plugins={[dayGridPlugin, timeGridPlugin, listPlugin]}
        initialView="dayGridMonth"
        headerToolbar={{
          left: 'prev,next',
          center: 'title',
          right: 'dayGridMonth,timeGridWeek',
        }}
        events={events}
        datesSet={handleDatesSet}
        editable={false}
        selectable={false}
        dayMaxEvents={true}
        weekends={true}
        height="auto"
      />
    </div>
  );
}

function formatDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}${m}${d}`;
}
