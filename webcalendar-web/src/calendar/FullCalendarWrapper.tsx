import { useCallback, useImperativeHandle, useMemo, useRef, useState, forwardRef } from 'react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import type { DatesSetArg, EventClickArg, DateSelectArg, EventInput } from '@fullcalendar/core';
import { fetchCalendarEvents } from './useCalendarEvents';
import { useKeyboardShortcuts } from './useKeyboardShortcuts';
import { useCategories, getEventColor } from './useCategories';

export interface FullCalendarWrapperHandle {
  refetchEvents: () => void;
}

interface FullCalendarWrapperProps {
  initialView?: string;
  onEventClick?: (eventId: number) => void;
  onDateSelect?: (start: Date, end: Date, allDay: boolean) => void;
}

export const FullCalendarWrapper = forwardRef<FullCalendarWrapperHandle, FullCalendarWrapperProps>(
  function FullCalendarWrapper({ initialView = 'dayGridMonth', onEventClick, onDateSelect }, ref) {
  const [events, setEvents] = useState<EventInput[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const calendarRef = useRef<FullCalendar>(null);
  const { categories } = useCategories();

  // fetchEvents is defined below as fetchEventsWrapped (with ref tracking)

  const handleEventClick = useCallback(
    (arg: EventClickArg) => {
      const eventId = parseInt(arg.event.id, 10);
      if (!isNaN(eventId) && onEventClick) {
        onEventClick(eventId);
      }
    },
    [onEventClick],
  );

  const handleDateSelect = useCallback(
    (arg: DateSelectArg) => {
      if (onDateSelect) {
        onDateSelect(arg.start, arg.end, arg.allDay);
      }
    },
    [onDateSelect],
  );

  const keyboardHandlers = useMemo(
    () => ({
      onPrev: () => calendarRef.current?.getApi().prev(),
      onNext: () => calendarRef.current?.getApi().next(),
      onToday: () => calendarRef.current?.getApi().today(),
      onViewChange: (view: string) => calendarRef.current?.getApi().changeView(view),
    }),
    [],
  );

  useKeyboardShortcuts(keyboardHandlers);

  const lastDatesSetRef = useRef<DatesSetArg | null>(null);

  const fetchEventsWrapped = useCallback(async (arg: DatesSetArg) => {
    lastDatesSetRef.current = arg;
    setIsLoading(true);
    try {
      const startDate = formatDateParam(arg.start);
      const endDate = formatDateParam(arg.end);
      const result = await fetchCalendarEvents(startDate, endDate);

      // Apply category colors
      const coloredEvents = result.map((event) => {
        const catIds = (event.extendedProps?.categories as number[]) ?? [];
        const color = getEventColor(catIds, categories);
        return { ...event, backgroundColor: color, borderColor: color };
      });

      setEvents(coloredEvents);
    } finally {
      setIsLoading(false);
    }
  }, [categories]);

  useImperativeHandle(ref, () => ({
    refetchEvents: () => {
      if (lastDatesSetRef.current) {
        void fetchEventsWrapped(lastDatesSetRef.current);
      }
    },
  }), [fetchEventsWrapped]);

  return (
    <div className="relative">
      {isLoading && (
        <div className="absolute right-2 top-2 z-10 rounded bg-primary px-2 py-1 text-xs text-primary-foreground">
          Loading...
        </div>
      )}
      <FullCalendar
        ref={calendarRef}
        plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
        initialView={initialView}
        headerToolbar={{
          left: 'prev,next today',
          center: 'title',
          right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
        }}
        events={events}
        datesSet={fetchEventsWrapped}
        eventClick={handleEventClick}
        selectable={true}
        select={handleDateSelect}
        editable={false}
        dayMaxEvents={true}
        weekends={true}
        height="auto"
        nowIndicator={true}
      />
    </div>
  );
  },
);

function formatDateParam(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}${m}${d}`;
}
