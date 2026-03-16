import { useCallback, useMemo, useRef, useState } from 'react';
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

type DialogState =
  | { type: 'none' }
  | { type: 'detail'; event: ApiEvent }
  | { type: 'create'; initialDate: string; initialTime: string; initialAllDay: boolean }
  | { type: 'edit'; event: ApiEvent }
  | { type: 'confirmDelete'; event: ApiEvent };

export function CalendarPage() {
  const calendarRef = useRef<FullCalendarWrapperHandle>(null);
  const [dialog, setDialog] = useState<DialogState>({ type: 'none' });
  const [isDeleting, setIsDeleting] = useState(false);
  const [isResponding, setIsResponding] = useState(false);
  const [showShortcuts, setShowShortcuts] = useState(false);
  const { toast } = useToast();
  const { user } = useAuth();

  // --- Global keyboard shortcuts ---
  const shortcutHandlers = useMemo(() => ({
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
  }), []);

  useGlobalShortcuts(shortcutHandlers);

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
  const handleCreate = useCallback(async (data: EventFormData): Promise<boolean> => {
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

    const { data: created, error } = await apiFetch<{ id: number }>('/events', {
      method: 'POST',
      body: JSON.stringify(body),
    });

    if (!error && created) {
      // Add participants if specified
      if (data.participants && data.participants.length > 0) {
        await apiFetch(`/events/${created.id}/participants`, {
          method: 'POST',
          body: JSON.stringify({ participants: data.participants }),
        });
      }
      calendarRef.current?.refetchEvents();
      toast({ title: 'Event created', variant: 'success' });
      return true;
    }
    toast({ title: 'Failed to create event', variant: 'error' });
    return false;
  }, [toast]);

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

      const { error } = await apiFetch(`/events/${event.id}`, {
        method: 'PUT',
        body: JSON.stringify(body),
      });

      if (!error && data.participants) {
        // Replace participants by setting the full list
        // First get current, then add new / remove old
        const currentLogins = (event.participants ?? []).map((p) => p.login);
        const newLogins = data.participants;

        for (const login of newLogins) {
          if (!currentLogins.includes(login)) {
            await apiFetch(`/events/${event.id}/participants`, {
              method: 'POST',
              body: JSON.stringify({ participants: [login] }),
            });
          }
        }
        for (const login of currentLogins) {
          if (!newLogins.includes(login)) {
            await apiFetch(`/events/${event.id}/participants/${login}`, { method: 'DELETE' });
          }
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

  // --- Delete event ---
  const handleDelete = useCallback(async () => {
    if (dialog.type !== 'confirmDelete') return;
    const event = dialog.event;
    setIsDeleting(true);

    const { error } = await apiFetch(`/events/${event.id}`, { method: 'DELETE' });

    if (!error) {
      calendarRef.current?.refetchEvents();
      setDialog({ type: 'none' });
      toast({ title: 'Event deleted', variant: 'success' });
    } else {
      toast({ title: 'Failed to delete event', variant: 'error' });
    }
    setIsDeleting(false);
  }, [dialog, toast]);

  const closeDialog = useCallback(() => setDialog({ type: 'none' }), []);

  return (
    <div>
      {/* Toolbar */}
      <div className="mb-4 flex justify-end gap-2">
        <button
          onClick={() => setShowShortcuts(true)}
          className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-input text-sm text-muted-foreground hover:bg-accent"
          title="Keyboard shortcuts (?)"
        >
          ?
        </button>
        <button
          onClick={handleNewEvent}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          + New Event
        </button>
      </div>

      {/* Calendar */}
      <FullCalendarWrapper
        ref={calendarRef}
        onEventClick={handleEventClick}
        onDateSelect={handleDateSelect}
      />

      {/* Event Detail Dialog */}
      {dialog.type === 'detail' && (
        <EventDetailDialog
          event={dialog.event}
          open={true}
          onClose={closeDialog}
          onEdit={() => setDialog({ type: 'edit', event: dialog.event })}
          onDelete={() => setDialog({ type: 'confirmDelete', event: dialog.event })}
          currentUserLogin={user?.login}
          isResponding={isResponding}
          onAccept={async () => {
            setIsResponding(true);
            const { error } = await apiFetch(`/events/${dialog.event.id}/approve`, { method: 'POST' });
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
            const { error } = await apiFetch(`/events/${dialog.event.id}/reject`, { method: 'POST' });
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
        />
      )}

      {/* Edit Event Dialog */}
      {dialog.type === 'edit' && (
        <EventDialog
          open={true}
          onClose={closeDialog}
          onSave={handleUpdate}
          mode="edit"
          initialDate={apiEventToInitialValues(dialog.event).start_date_display}
          initialTime={apiEventToInitialValues(dialog.event).start_time_display}
          initialAllDay={dialog.event.all_day}
          initialValues={apiEventToInitialValues(dialog.event)}
        />
      )}

      {/* Confirm Delete Dialog */}
      {dialog.type === 'confirmDelete' && (
        <ConfirmDeleteDialog
          open={true}
          eventTitle={dialog.event.title}
          onConfirm={handleDelete}
          onCancel={closeDialog}
          isDeleting={isDeleting}
        />
      )}

      {/* Keyboard Shortcuts Help */}
      <ShortcutsDialog open={showShortcuts} onClose={() => setShowShortcuts(false)} />
    </div>
  );
}
