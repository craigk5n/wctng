import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { FullCalendarWrapper, type FullCalendarWrapperHandle } from './FullCalendarWrapper';
import { EventDetailDialog } from './EventDetailDialog';
import { EventDialog, type EventFormData } from './EventDialog';
import { ConfirmDeleteDialog } from './ConfirmDeleteDialog';
import { apiEventToInitialValues } from './eventDialogHelpers';
import type { ApiEvent } from './eventMapper';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { useAuth } from '../auth/auth-context';
import { ShortcutsDialog } from '../components/shortcuts/ShortcutsDialog';
import { useGlobalShortcuts } from '../components/shortcuts/useGlobalShortcuts';
import { ExportButton } from './ExportButton';
import { ImportDialog } from './ImportDialog';
import { PrintButton } from './PrintButton';
import { exportEventAsIcs } from './exportEventIcs';
import { LayerPanel, type LayerVisibility } from './LayerPanel';
import { PollDialog } from './PollDialog';
import { ViewSwitcher } from './ViewSwitcher';
import { QuickAddInput } from './QuickAddInput';
import { useMercure, type MercureMessage } from '../hooks/useMercure';
import { CategoryFilterPopover } from './CategoryFilterPopover';
import { CategoryFilter } from './CategoryFilter';
import { RecurringScopeDialog, type RecurringScope } from './RecurringScopeDialog';
import { useFeatureFlags } from '../hooks/useFeatureFlags';

type DialogState =
  | { type: 'none' }
  | { type: 'detail'; event: ApiEvent }
  | {
      type: 'create';
      initialDate: string;
      initialTime: string;
      initialAllDay: boolean;
      initialValues?: Record<string, unknown>;
    }
  | { type: 'edit'; event: ApiEvent; editScope?: string; editFromDate?: string }
  | { type: 'confirmDelete'; event: ApiEvent }
  | { type: 'recurringScope'; event: ApiEvent; mode: 'edit' | 'delete' };

export function CalendarPage() {
  const calendarRef = useRef<FullCalendarWrapperHandle>(null);
  const [dialog, setDialog] = useState<DialogState>({ type: 'none' });
  const [isDeleting, setIsDeleting] = useState(false);
  const [isResponding, setIsResponding] = useState(false);
  const [showShortcuts, setShowShortcuts] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const [showPoll, setShowPoll] = useState(false);
  const [showLayers, setShowLayers] = useState(
    () => localStorage.getItem('wctng_layers_visible') !== 'false',
  );
  const [showCategories, setShowCategories] = useState(
    () => localStorage.getItem('wctng_categories_visible') !== 'false',
  );
  const [defaultView, setDefaultView] = useState('dayGridMonth');
  const [scrollTime, setScrollTime] = useState('08:00:00');
  const [publicCalendarEnabled, setPublicCalendarEnabled] = useState(false);
  const [activeLayers, setActiveLayers] = useState<LayerVisibility[]>([]);
  const [activeCategoryIds, setActiveCategoryIds] = useState<number[] | null>(null);
  const { toast } = useToast();
  const { user } = useAuth();
  const features = useFeatureFlags();
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();

  // Load saved default view preference
  useEffect(() => {
    if (!user?.login) return;
    void (async () => {
      const { data } = await apiFetch<Array<{ key: string; value: string }>>(
        `/users/${user.login}/preferences`,
      );
      if (data) {
        const viewPref = data.find((p) => p.key === 'STARTVIEW');
        if (viewPref) setDefaultView(viewPref.value);
        const publicPref = data.find((p) => p.key === 'public_calendar_enabled');
        setPublicCalendarEnabled(publicPref?.value === 'Y');
        const startPref = data.find((p) => p.key === 'WORK_DAY_START');
        if (startPref?.value) {
          // Accept "HH:MM" or "H" and normalize to "HH:MM:SS"
          const v = startPref.value.trim();
          const m = /^(\d{1,2})(?::(\d{2}))?(?::\d{2})?$/.exec(v);
          if (m) {
            const hh = String(Math.min(23, parseInt(m[1], 10))).padStart(2, '0');
            const mm = (m[2] ?? '00').padStart(2, '0');
            setScrollTime(`${hh}:${mm}:00`);
          }
        }
      }
    })();
  }, [user?.login]);

  // Handle URL search params (from search bar navigation)
  useEffect(() => {
    const viewParam = searchParams.get('view');
    const dateParam = searchParams.get('date');

    if (!viewParam && !dateParam) return;

    // Delay to let FullCalendar mount
    const timer = setTimeout(() => {
      if (viewParam) {
        calendarRef.current?.changeView(viewParam);
      }
      if (dateParam) {
        calendarRef.current?.gotoDate(dateParam);
      }
      // Clear params so they don't re-trigger
      setSearchParams({}, { replace: true });
    }, 200);

    return () => clearTimeout(timer);
  }, [searchParams, setSearchParams]);

  // --- Global keyboard shortcuts ---
  const shortcutHandlers = useMemo(
    () => ({
      onHelp: () => setShowShortcuts(true),
      onNewEvent: () => {
        const now = new Date();
        setDialog({
          type: 'create',
          initialDate: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`,
          initialTime: '09:00',
          initialAllDay: false,
        });
      },
    }),
    [],
  );

  useGlobalShortcuts(shortcutHandlers);

  // --- Mercure real-time subscription ---
  const mercureHubUrl =
    (import.meta.env.VITE_MERCURE_URL as string | undefined) ??
    'http://localhost:47181/.well-known/mercure';
  const mercureTopics = useMemo(() => ['/calendars/events'], []);

  const handleMercureMessage = useCallback(
    (msg: MercureMessage) => {
      if (msg.type === 'event.created' || msg.type === 'event.updated') {
        calendarRef.current?.refetchEvents();
        const eventData = msg.event as Record<string, unknown> | undefined;
        const createdBy = eventData?.created_by as string | undefined;
        if (createdBy && createdBy !== user?.login) {
          toast({ title: `Calendar updated by ${createdBy}` });
        }
      } else if (msg.type === 'event.deleted') {
        calendarRef.current?.refetchEvents();
      } else if (msg.type === 'participant.changed') {
        calendarRef.current?.refetchEvents();
      }
    },
    [toast, user?.login],
  );

  useMercure({
    hubUrl: mercureHubUrl,
    topics: mercureTopics,
    onMessage: handleMercureMessage,
  });

  // --- Task click: navigate to tasks page ---
  const handleTaskClick = useCallback(
    (_taskId: number) => {
      navigate('/tasks');
    },
    [navigate],
  );

  // --- Journal click: navigate to journals page ---
  const handleJournalClick = useCallback(
    (_journalId: number) => {
      navigate('/journals');
    },
    [navigate],
  );

  // --- Event click: fetch full event and show detail ---
  const handleEventClick = useCallback(async (eventId: number) => {
    const { data } = await apiFetch<ApiEvent>(`/events/${eventId}`);
    if (data) {
      setDialog({ type: 'detail', event: data });
    }
  }, []);

  // --- Date select: open create dialog pre-filled ---
  const handleDateSelect = useCallback((start: Date, _end: Date, allDay: boolean) => {
    const yyyy = String(start.getFullYear());
    const mm = String(start.getMonth() + 1).padStart(2, '0');
    const dd = String(start.getDate()).padStart(2, '0');
    const hh = String(start.getHours()).padStart(2, '0');
    const min = String(start.getMinutes()).padStart(2, '0');

    setDialog({
      type: 'create',
      initialDate: `${yyyy}-${mm}-${dd}`,
      initialTime: allDay ? '' : `${hh}:${min}`,
      initialAllDay: allDay,
    });
  }, []);

  // --- New Event button ---
  const handleNewEvent = useCallback(() => {
    const now = new Date();
    const yyyy = String(now.getFullYear());
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');

    setDialog({
      type: 'create',
      initialDate: `${yyyy}-${mm}-${dd}`,
      initialTime: '09:00',
      initialAllDay: false,
    });
  }, []);

  // --- Create event ---
  const handleCreate = useCallback(
    async (data: EventFormData): Promise<boolean> => {
      const body: Record<string, unknown> = {
        title: data.title,
        start_date: data.start_date,
        duration: data.duration,
        location: data.location,
        description: data.description,
        access: data.access,
        categories: data.categories ?? [],
      };
      if (!data.all_day && data.start_time) {
        body.start_time = data.start_time;
      }
      if (data.rrule) {
        body.rrule = data.rrule;
      }

      const { data: created, error } = await apiFetch<{ id: number }>('/events', {
        method: 'POST',
        body: JSON.stringify(body),
      });

      if (!error && created) {
        // Add participants if specified
        if (data.participants && data.participants.length > 0) {
          const { error: partErr } = await apiFetch(`/events/${created.id}/participants`, {
            method: 'POST',
            body: JSON.stringify({ participants: data.participants }),
          });
          if (partErr) {
            toast({ title: 'Event created but failed to add participants', variant: 'error' });
          }
        }
        // Save custom field values (including event color)
        const customFields = { ...(data.custom_fields ?? {}) };
        if (data.color) customFields['_event_color'] = data.color;
        if (Object.keys(customFields).length > 0) {
          const { error: cfErr } = await apiFetch(`/events/${created.id}/custom-fields`, {
            method: 'PUT',
            body: JSON.stringify(customFields),
          });
          if (cfErr) {
            toast({ title: 'Event created but failed to save custom fields', variant: 'error' });
          }
        }
        calendarRef.current?.refetchEvents();
        toast({ title: 'Event created', variant: 'success' });
        return true;
      }
      toast({ title: 'Failed to create event', variant: 'error' });
      return false;
    },
    [toast],
  );

  // --- Update event ---
  const handleUpdate = useCallback(
    async (data: EventFormData): Promise<boolean> => {
      if (dialog.type !== 'edit') return false;
      const event = dialog.event;

      const body: Record<string, unknown> = {
        title: data.title,
        start_date: data.start_date,
        duration: data.duration,
        location: data.location,
        description: data.description,
        access: data.access,
        categories: data.categories ?? [],
      };
      if (!data.all_day && data.start_time) {
        body.start_time = data.start_time;
      }

      // Build URL with scope parameters for recurring series split
      let updateUrl = `/events/${event.id}`;
      if (dialog.editScope === 'future' && dialog.editFromDate) {
        updateUrl += `?scope=future&from_date=${dialog.editFromDate}`;
      }

      const { error } = await apiFetch(updateUrl, {
        method: 'PUT',
        body: JSON.stringify(body),
      });

      if (!error && data.participants) {
        // Replace participants by setting the full list
        // First get current, then add new / remove old
        const currentLogins = (event.participants ?? []).map((p) => p.login);
        const newLogins = data.participants;

        let participantFailed = false;
        for (const login of newLogins) {
          if (!currentLogins.includes(login)) {
            const { error: addErr } = await apiFetch(`/events/${event.id}/participants`, {
              method: 'POST',
              body: JSON.stringify({ participants: [login] }),
            });
            if (addErr) participantFailed = true;
          }
        }
        for (const login of currentLogins) {
          if (!newLogins.includes(login)) {
            const { error: rmErr } = await apiFetch(`/events/${event.id}/participants/${login}`, {
              method: 'DELETE',
            });
            if (rmErr) participantFailed = true;
          }
        }
        if (participantFailed) {
          toast({ title: 'Event updated but some participant changes failed', variant: 'error' });
        }
      }

      if (!error) {
        calendarRef.current?.refetchEvents();
        toast({ title: 'Event updated', variant: 'success' });
        return true;
      }
      toast({ title: 'Failed to update event', variant: 'error' });
      return false;
    },
    [dialog, toast],
  );

  // --- Soft-delete event (cancel or decline) with undo ---
  const undoTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const handleDelete = useCallback(async () => {
    if (dialog.type !== 'confirmDelete') return;
    const event = dialog.event;
    setIsDeleting(true);

    const { data, error } = await apiFetch<{
      action: 'cancelled' | 'declined';
      previous_status: string | null;
    }>(`/events/${event.id}`, { method: 'DELETE' });

    setIsDeleting(false);

    if (error) {
      toast({ title: 'Failed to remove event', variant: 'error' });
      return;
    }

    calendarRef.current?.refetchEvents();
    setDialog({ type: 'none' });

    const isCancelled = data?.action === 'cancelled';
    const label = isCancelled ? 'Event cancelled' : 'Event declined';

    // Show undo toast
    toast({
      title: label,
      variant: 'success',
      action: {
        label: 'Undo',
        onClick: async () => {
          if (undoTimerRef.current) {
            clearTimeout(undoTimerRef.current);
            undoTimerRef.current = null;
          }
          const { error: restoreErr } = await apiFetch(`/events/${event.id}/restore`, {
            method: 'POST',
            body: JSON.stringify({ previous_status: data?.previous_status }),
          });
          if (restoreErr) {
            toast({ title: 'Failed to undo', variant: 'error' });
          } else {
            toast({ title: 'Event restored', variant: 'success' });
            calendarRef.current?.refetchEvents();
          }
        },
      },
      duration: 5000,
    });
  }, [dialog, toast]);

  const closeDialog = useCallback(() => setDialog({ type: 'none' }), []);

  // Handle recurring event scope selection
  const handleRecurringScopeSelect = useCallback(
    async (scope: RecurringScope, date?: string) => {
      if (dialog.type !== 'recurringScope') return;
      const event = dialog.event;

      if (dialog.mode === 'edit') {
        if (scope === 'all') {
          setDialog({ type: 'edit', event });
        } else if (scope === 'future' && date) {
          setDialog({ type: 'edit', event, editScope: 'future', editFromDate: date });
        }
        return;
      }

      // Delete mode
      if (scope === 'all') {
        setDialog({ type: 'confirmDelete', event });
        return;
      }

      // occurrence or future — call API directly with scope
      const url = `/events/${event.id}?scope=${scope}${date ? `&date=${date}` : ''}`;
      const { error } = await apiFetch(url, { method: 'DELETE' });
      if (error) {
        toast({ title: error.message ?? 'Failed to cancel occurrence', variant: 'error' });
        return;
      }
      calendarRef.current?.refetchEvents();
      setDialog({ type: 'none' });
      toast({
        title: scope === 'occurrence' ? 'Occurrence cancelled' : 'Future occurrences cancelled',
        variant: 'success',
      });
    },
    [dialog, toast],
  );

  // Drag-and-drop rescheduling
  const handleEventDrop = useCallback(
    async (info: {
      eventId: number;
      newStart: Date;
      newEnd: Date | null;
      allDay: boolean;
      revert: () => void;
    }) => {
      const startDate = formatDateYmd(info.newStart);
      const startTime = info.allDay ? undefined : formatTimeHms(info.newStart);
      const duration = info.newEnd
        ? Math.round((info.newEnd.getTime() - info.newStart.getTime()) / 60000)
        : undefined;

      const body: Record<string, unknown> = { start_date: startDate };
      if (startTime) body.start_time = startTime;
      if (duration !== undefined) body.duration = duration;

      const { error } = await apiFetch(`/events/${info.eventId}`, {
        method: 'PUT',
        body: JSON.stringify(body),
      });

      if (error) {
        info.revert();
        toast({ title: 'Failed to reschedule', variant: 'error' });
      } else {
        toast({ title: 'Event rescheduled', variant: 'success' });
      }
    },
    [toast],
  );

  const handleCategoryFilterChange = useCallback((ids: number[]) => {
    setActiveCategoryIds((prev) => {
      // Only update if actually changed (avoid re-render loops)
      if (prev !== null && prev.length === ids.length && prev.every((id, i) => id === ids[i])) {
        return prev;
      }
      return ids;
    });
  }, []);

  const handleLayersChange = useCallback((layers: LayerVisibility[]) => {
    setActiveLayers((prev) => {
      // Only trigger refetch if visibility actually changed
      const prevVisible = prev
        .filter((l) => l.visible)
        .map((l) => l.id)
        .sort()
        .join(',');
      const newVisible = layers
        .filter((l) => l.visible)
        .map((l) => l.id)
        .sort()
        .join(',');
      if (prevVisible !== newVisible) {
        // Defer refetch to after state update
        setTimeout(() => calendarRef.current?.refetchEvents(), 0);
      }
      return layers;
    });
  }, []);

  return (
    <div>
      {/* Toolbar */}
      <div className="mb-4 flex justify-end gap-2">
        <QuickAddInput
          onParsed={(parsed) => {
            setDialog({
              type: 'create',
              initialDate: parsed.start_date_display ?? '',
              initialTime: parsed.start_time_display ?? '',
              initialAllDay: false,
              initialValues: parsed as Record<string, unknown>,
            });
          }}
        />
        <ViewSwitcher
          onViewChange={() => {
            calendarRef.current?.refetchEvents();
          }}
        />
        <CategoryFilterPopover onChange={handleCategoryFilterChange} />
        <PrintButton />
        <ExportButton />
        <button
          onClick={() => setShowImport(true)}
          className="inline-flex h-10 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent"
        >
          Import
        </button>
        <button
          onClick={() => setShowShortcuts(true)}
          className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-input text-sm text-muted-foreground hover:bg-accent"
          aria-label="Keyboard shortcuts"
        >
          ?
        </button>
        <button
          onClick={() => setShowPoll(true)}
          className="inline-flex h-10 items-center rounded-md border border-primary px-3 text-sm font-medium text-primary hover:bg-primary/10"
        >
          Schedule Meeting
        </button>
        <button
          onClick={handleNewEvent}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          + New Event
        </button>
      </div>

      {/* Calendar with Layer Panel */}
      <div className="flex gap-4">
        <div className="flex-1">
          <FullCalendarWrapper
            ref={calendarRef}
            initialView={defaultView}
            scrollTime={scrollTime}
            onEventClick={handleEventClick}
            onTaskClick={handleTaskClick}
            onJournalClick={handleJournalClick}
            onDateSelect={handleDateSelect}
            currentUserLogin={user?.login}
            onEventDrop={handleEventDrop}
            onEventResize={handleEventDrop}
            activeLayers={activeLayers}
            activeCategoryIds={activeCategoryIds}
          />
        </div>
        <div className="hidden flex-shrink-0 md:block">
          <button
            onClick={() => {
              setShowLayers((prev) => {
                localStorage.setItem('wctng_layers_visible', String(!prev));
                return !prev;
              });
            }}
            className="mb-1 flex w-full items-center justify-between rounded-lg border border-border bg-card px-3 py-2 text-xs font-medium text-muted-foreground hover:bg-accent"
            aria-expanded={showLayers}
          >
            <span>Layers</span>
            <span aria-hidden="true">{showLayers ? '▼' : '▶'}</span>
          </button>
          {showLayers && (
            <div className="w-56 rounded-lg border border-border bg-card">
              <LayerPanel onLayersChange={handleLayersChange} />
            </div>
          )}

          <button
            onClick={() => {
              setShowCategories((prev) => {
                localStorage.setItem('wctng_categories_visible', String(!prev));
                return !prev;
              });
            }}
            className="mb-1 flex w-full items-center justify-between rounded-lg border border-border bg-card px-3 py-2 text-xs font-medium text-muted-foreground hover:bg-accent"
            aria-expanded={showCategories}
          >
            <span>Categories</span>
            <span aria-hidden="true">{showCategories ? '▼' : '▶'}</span>
          </button>
          {showCategories && (
            <div className="w-56 rounded-lg border border-border bg-card">
              <CategoryFilter onChange={handleCategoryFilterChange} />
            </div>
          )}
        </div>
      </div>

      {/* Event Detail Dialog */}
      {dialog.type === 'detail' && (
        <EventDetailDialog
          event={dialog.event}
          open={true}
          onClose={closeDialog}
          onEdit={() => {
            const e = dialog.event;
            const isRecurring = e.type === 'M' || !!e.rrule;
            if (isRecurring) {
              setDialog({ type: 'recurringScope', event: e, mode: 'edit' });
            } else {
              setDialog({ type: 'edit', event: e });
            }
          }}
          onDelete={() => {
            const e = dialog.event;
            const isRecurring = e.type === 'M' || !!e.rrule;
            if (isRecurring) {
              setDialog({ type: 'recurringScope', event: e, mode: 'delete' });
            } else {
              setDialog({ type: 'confirmDelete', event: e });
            }
          }}
          onDuplicate={() => {
            const e = dialog.event;
            const now = new Date();
            const yyyy = String(now.getFullYear());
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            setDialog({
              type: 'create',
              initialDate: `${yyyy}-${mm}-${dd}`,
              initialTime: e.start_time
                ? `${e.start_time.slice(0, 2)}:${e.start_time.slice(2, 4)}`
                : '',
              initialAllDay: e.all_day,
              initialValues: {
                title: `${e.title} (copy)`,
                description: e.description,
                location: e.location,
                access: e.access,
                duration: e.duration,
                categories: e.categories,
                participants: (e.participants ?? []).map((p) => p.login),
              },
            });
          }}
          onExportIcs={() => exportEventAsIcs(dialog.event)}
          publicPageUrl={
            dialog.event.access === 'P' &&
            features.ENABLE_SEO_PAGES === 'Y' &&
            publicCalendarEnabled
              ? `/public/${dialog.event.created_by}/event/${dialog.event.id}`
              : undefined
          }
          currentUserLogin={user?.login}
          isResponding={isResponding}
          onAccept={async () => {
            setIsResponding(true);
            const { error } = await apiFetch(`/events/${dialog.event.id}/approve`, {
              method: 'POST',
            });
            if (!error) {
              toast({ title: 'Event accepted', variant: 'success' });
              // Refresh event detail
              const { data } = await apiFetch<ApiEvent>(`/events/${dialog.event.id}`);
              if (data) setDialog({ type: 'detail', event: data });
            } else {
              toast({ title: 'Failed to respond', variant: 'error' });
            }
            setIsResponding(false);
          }}
          onReject={async () => {
            setIsResponding(true);
            const { error } = await apiFetch(`/events/${dialog.event.id}/reject`, {
              method: 'POST',
            });
            if (!error) {
              toast({ title: 'Event declined', variant: 'success' });
              const { data } = await apiFetch<ApiEvent>(`/events/${dialog.event.id}`);
              if (data) setDialog({ type: 'detail', event: data });
            } else {
              toast({ title: 'Failed to respond', variant: 'error' });
            }
            setIsResponding(false);
          }}
        />
      )}

      {/* Create Event Dialog */}
      {dialog.type === 'create' && (
        <EventDialog
          open={true}
          onClose={closeDialog}
          onSave={handleCreate}
          mode="create"
          initialDate={dialog.initialDate}
          initialTime={dialog.initialTime}
          initialAllDay={dialog.initialAllDay}
          initialValues={dialog.initialValues}
        />
      )}

      {/* Edit Event Dialog */}
      {dialog.type === 'edit' && (
        <EventDialog
          open={true}
          onClose={closeDialog}
          onSave={handleUpdate}
          mode="edit"
          editEventId={dialog.event.id}
          initialDate={apiEventToInitialValues(dialog.event).start_date_display}
          initialTime={apiEventToInitialValues(dialog.event).start_time_display}
          initialAllDay={dialog.event.all_day}
          initialValues={apiEventToInitialValues(dialog.event)}
        />
      )}

      {/* Recurring Event Scope Picker */}
      {dialog.type === 'recurringScope' && (
        <RecurringScopeDialog
          open={true}
          mode={dialog.mode}
          eventTitle={dialog.event.title}
          onSelect={handleRecurringScopeSelect}
          onCancel={() => setDialog({ type: 'detail', event: dialog.event })}
        />
      )}

      {/* Confirm Delete Dialog */}
      {dialog.type === 'confirmDelete' && (
        <ConfirmDeleteDialog
          open={true}
          eventTitle={dialog.event.title}
          isRecurring={dialog.event.type === 'M' || !!dialog.event.rrule}
          isOrganizer={dialog.event.created_by === user?.login || user?.is_admin === true}
          onConfirm={handleDelete}
          onCancel={closeDialog}
          isDeleting={isDeleting}
        />
      )}

      {/* Keyboard Shortcuts Help */}
      <ShortcutsDialog open={showShortcuts} onClose={() => setShowShortcuts(false)} />
      <ImportDialog
        open={showImport}
        onClose={() => setShowImport(false)}
        onImported={() => calendarRef.current?.refetchEvents()}
      />

      <PollDialog
        open={showPoll}
        onClose={() => setShowPoll(false)}
        onCreated={() => toast({ title: 'Poll created', variant: 'success' })}
      />
    </div>
  );
}

function formatDateYmd(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}${m}${d}`;
}

function formatTimeHms(date: Date): string {
  const h = String(date.getHours()).padStart(2, '0');
  const m = String(date.getMinutes()).padStart(2, '0');
  return `${h}${m}00`;
}
