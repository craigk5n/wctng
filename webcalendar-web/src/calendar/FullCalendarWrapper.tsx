import {
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
  forwardRef,
} from 'react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import multiMonthPlugin from '@fullcalendar/multimonth';
import type {
  DatesSetArg,
  EventClickArg,
  DateSelectArg,
  EventInput,
  EventDropArg,
  AllowFunc,
} from '@fullcalendar/core';
import type { EventResizeDoneArg } from '@fullcalendar/interaction';
import * as Tooltip from '@radix-ui/react-tooltip';
import { fetchCalendarEvents } from './useCalendarEvents';
import { buildEventTooltip } from './eventTooltip';
import { EventTooltip } from './EventTooltip';
import { useKeyboardShortcuts } from './useKeyboardShortcuts';
import { useCategories, getEventColor, getEventIcon } from './useCategories';
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
  scrollTime?: string;
  currentUserLogin?: string;
  onEventClick?: (eventId: number) => void;
  onTaskClick?: (taskId: number) => void;
  onJournalClick?: (journalId: number) => void;
  onDateSelect?: (start: Date, end: Date, allDay: boolean) => void;
  onEventDrop?: (info: EventDropInfo) => void;
  onEventResize?: (info: EventDropInfo) => void;
  activeLayers?: LayerVisibility[];
  activeCategoryIds?: number[] | null;
}

export const FullCalendarWrapper = forwardRef<FullCalendarWrapperHandle, FullCalendarWrapperProps>(
  function FullCalendarWrapper(
    {
      initialView = 'dayGridMonth',
      scrollTime = '08:00:00',
      currentUserLogin,
      onEventClick,
      onTaskClick,
      onJournalClick,
      onDateSelect,
      onEventDrop,
      onEventResize,
      activeLayers,
      activeCategoryIds,
    },
    ref,
  ) {
    const [rawEvents, setRawEvents] = useState<EventInput[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [currentViewType, setCurrentViewType] = useState(initialView);
    const calendarRef = useRef<FullCalendar>(null);
    const { categories } = useCategories();
    const { i18n: i18nInstance } = useTranslation();

    // fetchEvents is defined below as fetchEventsWrapped (with ref tracking)

    const handleEventClick = useCallback(
      (arg: EventClickArg) => {
        const id = arg.event.id;
        // Subscription events are read-only — show source info via alert
        if (id.startsWith('sub-')) {
          const source = (arg.event.extendedProps?.source as string) ?? 'Subscription';
          const desc = (arg.event.extendedProps?.description as string) ?? '';
          const loc = (arg.event.extendedProps?.location as string) ?? '';
          const parts = [`Source: ${source}`];
          if (loc) parts.push(`Location: ${loc}`);
          if (desc) parts.push(`\n${desc}`);
          window.alert(`${arg.event.title}\n\n${parts.join('\n')}`);
          return;
        }
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

    const fetchEventsWrapped = useCallback(
      async (arg: DatesSetArg) => {
        lastDatesSetRef.current = arg;
        if (arg.view?.type && arg.view.type !== currentViewType) {
          setCurrentViewType(arg.view.type);
        }
        setIsLoading(true);
        try {
          const startDate = formatDateParam(arg.start);
          const endDate = formatDateParam(arg.end);
          const result = await fetchCalendarEvents(startDate, endDate, hasVisibleLayers);

          // Apply colors: tasks get distinct styling, events get category/layer colors
          const coloredEvents = result.map((event) => {
            // Pending approval events get muted dashed style
            const apiEvent = event.extendedProps?.apiEvent as
              | { status?: string | null }
              | undefined;
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
            // Emoji is computed at render time in eventContent below so
            // that categories loading AFTER the initial event fetch still
            // take effect without requiring a refetch.
            return {
              ...event,
              backgroundColor: color,
              borderColor: color,
            };
          });

          const filtered: EventInput[] = coloredEvents.filter((e) => e !== null) as EventInput[];
          setRawEvents(filtered);
        } finally {
          setIsLoading(false);
        }
      },
      [categories, hasVisibleLayers, layerColorMap, currentViewType],
    );

    // Bounded height for timeGrid views so the all-day row stays pinned
    // and the time body scrolls internally (initial scroll = scrollTime).
    const isTimeGrid = currentViewType === 'timeGridWeek' || currentViewType === 'timeGridDay';
    const calendarHeight = isTimeGrid ? 'calc(100vh - 180px)' : 'auto';

    // FullCalendar only honors scrollTime on initial view mount. Since prefs
    // (STARTVIEW + WORK_DAY_START) load async, force a scroll whenever we
    // enter a time-grid view or the preferred scrollTime changes.
    useEffect(() => {
      if (!isTimeGrid) return;
      const api = calendarRef.current?.getApi();
      if (!api) return;
      // Defer one frame so FC has laid out the time body at the new height.
      const id = window.requestAnimationFrame(() => api.scrollToTime(scrollTime));
      return () => window.cancelAnimationFrame(id);
    }, [isTimeGrid, scrollTime, currentViewType]);

    // Derive filtered events reactively when raw events or category filter changes
    const events = useMemo(() => {
      if (activeCategoryIds === null || activeCategoryIds === undefined) {
        return rawEvents;
      }
      const activeSet = new Set(activeCategoryIds);
      const showUncategorized = activeSet.has(-1);
      return rawEvents.filter((e) => {
        const catIds = (e.extendedProps?.categories as number[]) ?? [];
        if (catIds.length === 0) return showUncategorized;
        return catIds.some((id) => activeSet.has(id));
      });
      // eslint-disable-next-line react-hooks/exhaustive-deps -- serialize to avoid ref loops
    }, [rawEvents, JSON.stringify(activeCategoryIds)]);

    useImperativeHandle(
      ref,
      () => ({
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
      }),
      [fetchEventsWrapped],
    );

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
      <Tooltip.Provider delayDuration={200} skipDelayDuration={500}>
        <div className="relative" onTouchStart={handleTouchStart} onTouchEnd={handleTouchEnd}>
          {isLoading && (
            <div className="absolute right-2 top-2 z-10 rounded bg-primary px-2 py-1 text-xs text-primary-foreground">
              Loading...
            </div>
          )}
          <FullCalendar
            ref={calendarRef}
            plugins={[
              dayGridPlugin,
              timeGridPlugin,
              listPlugin,
              interactionPlugin,
              multiMonthPlugin,
            ]}
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
            snapDuration="00:15:00"
            eventMinHeight={15}
            dayMaxEvents={true}
            lazyFetching={true}
            weekends={true}
            height={calendarHeight}
            stickyHeaderDates={true}
            scrollTime={scrollTime}
            scrollTimeReset={false}
            locale={i18nInstance.language}
            nowIndicator={true}
            eventContent={(arg) => {
              const catIds = (arg.event.extendedProps?.categories as number[] | undefined) ?? [];
              // Compute the emoji at render time so categories loading AFTER
              // the initial event fetch still display without a refetch.
              const icon = getEventIcon(catIds, categories);
              return (
                <EventTooltip
                  event={{
                    title: arg.event.title,
                    start: arg.event.start,
                    end: arg.event.end,
                    allDay: arg.event.allDay,
                    location:
                      (arg.event.extendedProps?.location as string | undefined) ?? undefined,
                    description:
                      (arg.event.extendedProps?.description as string | undefined) ?? undefined,
                    categoryIds: catIds,
                  }}
                  categories={categories}
                >
                  <div className="fc-event-main-frame">
                    {arg.timeText && <div className="fc-event-time">{arg.timeText}</div>}
                    <div className="fc-event-title-container">
                      <div className="fc-event-title fc-sticky">
                        {icon && <span aria-hidden="true">{icon} </span>}
                        {arg.event.title || <>&nbsp;</>}
                      </div>
                    </div>
                  </div>
                </EventTooltip>
              );
            }}
            eventDidMount={(info) => {
              // Keep an aria-label for screen readers (Radix handles hover + focus
              // visual tooltip, but the SR-friendly aria-label still helps).
              const tip = buildEventTooltip({
                title: info.event.title,
                start: info.event.start,
                end: info.event.end,
                allDay: info.event.allDay,
                location: (info.event.extendedProps?.location as string | undefined) ?? undefined,
                description:
                  (info.event.extendedProps?.description as string | undefined) ?? undefined,
              });
              if (tip) {
                info.el.setAttribute('aria-label', tip);
              }
            }}
          />
        </div>
      </Tooltip.Provider>
    );
  },
);

function formatDateParam(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}${m}${d}`;
}
