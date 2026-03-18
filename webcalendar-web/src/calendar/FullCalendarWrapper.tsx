import { useCallback, useImperativeHandle, useMemo, useRef, useState, forwardRef } from 'react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import multiMonthPlugin from '@fullcalendar/multimonth';
import type { DatesSetArg, EventClickArg, DateSelectArg, EventInput, EventDropArg, AllowFunc } from '@fullcalendar/core';
import type { EventResizeDoneArg } from '@fullcalendar/interaction';
import { fetchCalendarEvents } from './useCalendarEvents';
import { useKeyboardShortcuts } from './useKeyboardShortcuts';
import { useCategories, getEventColor } from './useCategories';
import { useTranslation } from 'react-i18next';

export interface FullCalendarWrapperHandle {
  refetchEvents: () => void;
  gotoDate: (date: string) => void;
  changeView: (view: string) => void;
}

import type { LayerVisibility } from './LayerPanel';

interface EventDropInfo {
  eventId: number;
  newStart: Date;
  newEnd: Date | null;
  allDay: boolean;
  revert: () => void;
}

interface FullCalendarWrapperProps {
  initialView?: string;
  currentUserLogin?: string;
  onEventClick?: (eventId: number) => void;
  onTaskClick?: (taskId: number) => void;
  onJournalClick?: (journalId: number) => void;
  onDateSelect?: (start: Date, end: Date, allDay: boolean) => void;
  onEventDrop?: (info: EventDropInfo) => void;
  onEventResize?: (info: EventDropInfo) => void;
  activeLayers?: LayerVisibility[];
}

export const FullCalendarWrapper = forwardRef<FullCalendarWrapperHandle, FullCalendarWrapperProps>(
  function FullCalendarWrapper({ initialView = 'dayGridMonth', currentUserLogin, onEventClick, onTaskClick, onJournalClick, onDateSelect, onEventDrop, onEventResize, activeLayers }, ref) {
  const [events, setEvents] = useState<EventInput[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const calendarRef = useRef<FullCalendar>(null);
  const { categories } = useCategories();
  const { i18n: i18nInstance } = useTranslation();

  // fetchEvents is defined below as fetchEventsWrapped (with ref tracking)

  const handleEventClick = useCallback(
    (arg: EventClickArg) => {
      const id = arg.event.id;
      // Tasks have IDs like "task-42"
      if (id.startsWith('task-') && onTaskClick) {
        const taskId = parseInt(id.slice(5), 10);
        if (!isNaN(taskId)) onTaskClick(taskId);
        return;
      }
      // Journals have IDs like "journal-10"
      if (id.startsWith('journal-') && onJournalClick) {
        const journalId = parseInt(id.slice(8), 10);
        if (!isNaN(journalId)) onJournalClick(journalId);
        return;
      }
      const eventId = parseInt(id, 10);
      if (!isNaN(eventId) && onEventClick) {
        onEventClick(eventId);
      }
    },
    [onEventClick, onTaskClick, onJournalClick],
  );

  const handleDateSelect = useCallback(
    (arg: DateSelectArg) => {
      if (onDateSelect) {
        onDateSelect(arg.start, arg.end, arg.allDay);
      }
    },
    [onDateSelect],
  );

  const handleEventDrop = useCallback(
    (arg: EventDropArg) => {
      if (!onEventDrop) return;
      const id = parseInt(arg.event.id, 10);
      if (isNaN(id)) return;
      onEventDrop({
        eventId: id,
        newStart: arg.event.start!,
        newEnd: arg.event.end,
        allDay: arg.event.allDay,
        revert: arg.revert,
      });
    },
    [onEventDrop],
  );

  const handleEventResize = useCallback(
    (arg: EventResizeDoneArg) => {
      if (!onEventResize) return;
      const id = parseInt(arg.event.id, 10);
      if (isNaN(id)) return;
      onEventResize({
        eventId: id,
        newStart: arg.event.start!,
        newEnd: arg.event.end,
        allDay: arg.event.allDay,
        revert: arg.revert,
      });
    },
    [onEventResize],
  );

  // Only allow dragging/resizing events owned by the current user
  const eventAllow: AllowFunc = useCallback(
    (_dropInfo, draggedEvent) => {
      if (!currentUserLogin || !draggedEvent) return false;
      const createdBy = draggedEvent.extendedProps?.created_by as string | undefined;
      return createdBy === currentUserLogin;
    },
    [currentUserLogin],
  );

  const isEditable = !!currentUserLogin && !!onEventDrop;

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

  const hasVisibleLayers = (activeLayers ?? []).some((l) => l.visible);
  const layerColorMap = useMemo(() => {
    const map = new Map<string, string>();
    for (const l of activeLayers ?? []) {
      if (l.visible) map.set(l.source_user, l.color);
    }
    return map;
  }, [activeLayers]);

  const fetchEventsWrapped = useCallback(async (arg: DatesSetArg) => {
    lastDatesSetRef.current = arg;
    setIsLoading(true);
    try {
      const startDate = formatDateParam(arg.start);
      const endDate = formatDateParam(arg.end);
      const result = await fetchCalendarEvents(startDate, endDate, hasVisibleLayers);

      // Apply colors: tasks get distinct styling, events get category/layer colors
      const coloredEvents = result.map((event) => {
        // Pending approval events get muted dashed style
        const apiEvent = event.extendedProps?.apiEvent as { status?: string | null } | undefined;
        if (apiEvent?.status === 'needs_approval') {
          return {
            ...event,
            backgroundColor: '#d1d5db',
            borderColor: '#9ca3af',
            classNames: ['fc-event-pending'],
          };
        }
        // Rejected events are hidden from calendar
        if (apiEvent?.status === 'rejected') {
          return null;
        }
        // Journals get a distinct indigo color
        if (event.extendedProps?.isJournal) {
          return { ...event, backgroundColor: '#6366f1', borderColor: '#6366f1' };
        }
        // Tasks get a distinct color
        if (event.extendedProps?.isTask) {
          const isCompleted = (event.extendedProps.percent_complete as number) >= 100;
          return {
            ...event,
            backgroundColor: isCompleted ? '#9ca3af' : '#0d9488',
            borderColor: isCompleted ? '#9ca3af' : '#0d9488',
            borderStyle: 'dashed',
          };
        }
        const createdBy = event.extendedProps?.created_by as string | undefined;
        const layerColor = createdBy ? layerColorMap.get(createdBy) : undefined;
        if (layerColor) {
          return { ...event, backgroundColor: layerColor, borderColor: layerColor };
        }
        const catIds = (event.extendedProps?.categories as number[]) ?? [];
        const color = getEventColor(catIds, categories);
        return { ...event, backgroundColor: color, borderColor: color };
      });

      const filtered: EventInput[] = [];
      for (const e of coloredEvents) {
        if (e !== null) filtered.push(e);
      }
      setEvents(filtered);
    } finally {
      setIsLoading(false);
    }
  }, [categories, hasVisibleLayers, layerColorMap]);

  useImperativeHandle(ref, () => ({
    refetchEvents: () => {
      if (lastDatesSetRef.current) {
        void fetchEventsWrapped(lastDatesSetRef.current);
      }
    },
    gotoDate: (date: string) => {
      calendarRef.current?.getApi().gotoDate(date);
    },
    changeView: (view: string) => {
      calendarRef.current?.getApi().changeView(view);
    },
  }), [fetchEventsWrapped]);

  // Swipe gesture for mobile prev/next navigation
  const touchStartRef = useRef<{ x: number; y: number } | null>(null);

  const handleTouchStart = useCallback((e: React.TouchEvent) => {
    const touch = e.touches[0];
    touchStartRef.current = { x: touch.clientX, y: touch.clientY };
  }, []);

  const handleTouchEnd = useCallback((e: React.TouchEvent) => {
    if (!touchStartRef.current) return;
    const touch = e.changedTouches[0];
    const dx = touch.clientX - touchStartRef.current.x;
    const dy = touch.clientY - touchStartRef.current.y;
    touchStartRef.current = null;

    // Only trigger if horizontal swipe > 80px and more horizontal than vertical
    if (Math.abs(dx) > 80 && Math.abs(dx) > Math.abs(dy) * 2) {
      if (dx > 0) {
        calendarRef.current?.getApi().prev();
      } else {
        calendarRef.current?.getApi().next();
      }
    }
  }, []);

  return (
    <div
      className="relative"
      onTouchStart={handleTouchStart}
      onTouchEnd={handleTouchEnd}
    >
      {isLoading && (
        <div className="absolute right-2 top-2 z-10 rounded bg-primary px-2 py-1 text-xs text-primary-foreground">
          Loading...
        </div>
      )}
      <FullCalendar
        ref={calendarRef}
        plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, multiMonthPlugin]}
        initialView={initialView}
        headerToolbar={{
          left: 'prev,next today',
          center: 'title',
          right: 'multiMonthYear,dayGridMonth,timeGridWeek,timeGridDay,listWeek',
        }}
        events={events}
        datesSet={fetchEventsWrapped}
        eventClick={handleEventClick}
        selectable={true}
        selectLongPressDelay={300}
        select={handleDateSelect}
        editable={isEditable}
        eventDrop={handleEventDrop}
        eventResize={handleEventResize}
        eventAllow={eventAllow}
        dayMaxEvents={true}
        weekends={true}
        height="auto"
        locale={i18nInstance.language}
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
