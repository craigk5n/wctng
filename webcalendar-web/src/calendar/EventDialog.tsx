import { useCallback, useEffect, useState } from 'react';
import { useCategories } from './useCategories';
import { apiFetch } from '../api/client';

interface GroupSuggestion {
  id: number;
  name: string;
  owner: string;
}

interface GroupDetail extends GroupSuggestion {
  members: string[];
}

export interface EventFormData {
  title: string;
  start_date: string; // YYYYMMDD
  start_time?: string; // HHMMSS
  duration: number;
  location: string;
  description: string;
  access: string;
  all_day: boolean;
  categories?: number[];
  participants?: string[];
}

interface EventDialogProps {
  open: boolean;
  onClose: () => void;
  onSave: (data: EventFormData) => Promise<boolean>;
  mode?: 'create' | 'edit';
  initialDate?: string; // YYYY-MM-DD
  initialTime?: string; // HH:MM
  initialAllDay?: boolean;
  initialValues?: Partial<EventFormData & { start_date_display: string; start_time_display: string }>;
}

function toYYYYMMDD(dateStr: string): string {
  return dateStr.replace(/-/g, '');
}

function toHHMMSS(timeStr: string): string {
  return timeStr.replace(/:/g, '') + '00';
}

export function EventDialog({
  open,
  onClose,
  onSave,
  mode = 'create',
  initialDate = '',
  initialTime = '',
  initialAllDay = false,
  initialValues,
}: EventDialogProps) {
  const [title, setTitle] = useState(initialValues?.title ?? '');
  const [date, setDate] = useState(initialValues?.start_date_display ?? initialDate);
  const [time, setTime] = useState(initialValues?.start_time_display ?? initialTime);
  const [duration, setDuration] = useState(initialValues?.duration ?? 60);
  const [location, setLocation] = useState(initialValues?.location ?? '');
  const [description, setDescription] = useState(initialValues?.description ?? '');
  const [access, setAccess] = useState(initialValues?.access ?? 'P');
  const [allDay, setAllDay] = useState(initialAllDay);
  const [selectedCategories, setSelectedCategories] = useState<number[]>(initialValues?.categories ?? []);
  const [participantLogins, setParticipantLogins] = useState<string[]>(initialValues?.participants ?? []);
  const [newParticipant, setNewParticipant] = useState('');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { categories: availableCategories } = useCategories();
  const [groups, setGroups] = useState<GroupSuggestion[]>([]);
  const [showGroupSuggestions, setShowGroupSuggestions] = useState(false);

  useEffect(() => {
    void (async () => {
      const { data } = await apiFetch<GroupSuggestion[]>('/groups');
      setGroups(data ?? []);
    })();
  }, []);

  const filteredGroups = newParticipant.trim().length > 0
    ? groups.filter((g) => g.name.toLowerCase().includes(newParticipant.trim().toLowerCase()))
    : [];

  const handleSelectGroup = useCallback(async (group: GroupSuggestion) => {
    const { data } = await apiFetch<GroupDetail>(`/groups/${group.id}`);
    if (data?.members) {
      setParticipantLogins((prev) => {
        const newLogins = data.members.filter((m) => !prev.includes(m));
        return [...prev, ...newLogins];
      });
    }
    setNewParticipant('');
    setShowGroupSuggestions(false);
  }, []);

  if (!open) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!title.trim()) {
      setError('Title is required');
      return;
    }

    if (!date) {
      setError('Date is required');
      return;
    }

    setIsSaving(true);
    try {
      const formData: EventFormData = {
        title: title.trim(),
        start_date: toYYYYMMDD(date),
        duration,
        location,
        description,
        access,
        all_day: allDay,
        categories: selectedCategories,
        participants: participantLogins,
      };

      if (!allDay && time) {
        formData.start_time = toHHMMSS(time);
      }

      const success = await onSave(formData);
      if (success) {
        onClose();
      }
    } catch {
      setError('Failed to save event');
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
    >
      <div
        className="w-full max-w-lg rounded-lg bg-card p-6 shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-lg font-semibold">
          {mode === 'edit' ? 'Edit Event' : 'New Event'}
        </h2>

        <form onSubmit={handleSubmit} className="mt-4 space-y-4">
          {error && (
            <div role="alert" className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
              {error}
            </div>
          )}

          <div className="space-y-2">
            <label htmlFor="event-title" className="text-sm font-medium">
              Title
            </label>
            <input
              id="event-title"
              type="text"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              required
              autoFocus
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              placeholder="Event title"
            />
          </div>

          <div className="flex items-center gap-2">
            <input
              id="event-allday"
              type="checkbox"
              checked={allDay}
              onChange={(e) => setAllDay(e.target.checked)}
              className="h-4 w-4 rounded border-input"
            />
            <label htmlFor="event-allday" className="text-sm font-medium">
              All day
            </label>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <label htmlFor="event-date" className="text-sm font-medium">
                Date
              </label>
              <input
                id="event-date"
                type="date"
                value={date}
                onChange={(e) => setDate(e.target.value)}
                required
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>

            {!allDay && (
              <div className="space-y-2">
                <label htmlFor="event-time" className="text-sm font-medium">
                  Start time
                </label>
                <input
                  id="event-time"
                  type="time"
                  value={time}
                  onChange={(e) => setTime(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
            )}
          </div>

          {!allDay && (
            <div className="space-y-2">
              <label htmlFor="event-duration" className="text-sm font-medium">
                Duration (minutes)
              </label>
              <input
                id="event-duration"
                type="number"
                value={duration}
                onChange={(e) => setDuration(parseInt(e.target.value, 10) || 0)}
                min={0}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
          )}

          <div className="space-y-2">
            <label htmlFor="event-location" className="text-sm font-medium">
              Location
            </label>
            <input
              id="event-location"
              type="text"
              value={location}
              onChange={(e) => setLocation(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              placeholder="Optional location"
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="event-description" className="text-sm font-medium">
              Description
            </label>
            <textarea
              id="event-description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={3}
              className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              placeholder="Optional description"
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="event-access" className="text-sm font-medium">
              Access level
            </label>
            <select
              id="event-access"
              value={access}
              onChange={(e) => setAccess(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            >
              <option value="P">Public</option>
              <option value="C">Confidential</option>
              <option value="R">Private</option>
            </select>
          </div>

          {availableCategories.length > 0 && (
            <div className="space-y-2">
              <span className="text-sm font-medium">Category</span>
              <div className="flex flex-wrap gap-2">
                {availableCategories.map((cat) => {
                  const isSelected = selectedCategories.includes(cat.id);
                  return (
                    <button
                      key={cat.id}
                      type="button"
                      onClick={() => {
                        setSelectedCategories((prev) =>
                          isSelected ? prev.filter((id) => id !== cat.id) : [...prev, cat.id],
                        );
                      }}
                      className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                        isSelected
                          ? 'border-transparent text-white'
                          : 'border-border text-muted-foreground hover:border-foreground/30'
                      }`}
                      style={isSelected ? { backgroundColor: cat.color ?? '#3788d8' } : undefined}
                    >
                      <span
                        className="h-2.5 w-2.5 rounded-full"
                        style={{ backgroundColor: cat.color ?? '#3788d8' }}
                      />
                      {cat.name}
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {/* Participants */}
          <div className="space-y-2">
            <span className="text-sm font-medium">Participants</span>
            <div className="relative flex gap-2">
              <input
                type="text"
                value={newParticipant}
                onChange={(e) => {
                  setNewParticipant(e.target.value);
                  setShowGroupSuggestions(e.target.value.trim().length > 0);
                }}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    const login = newParticipant.trim();
                    if (login && !participantLogins.includes(login)) {
                      setParticipantLogins((prev) => [...prev, login]);
                      setNewParticipant('');
                      setShowGroupSuggestions(false);
                    }
                  }
                  if (e.key === 'Escape') {
                    setShowGroupSuggestions(false);
                  }
                }}
                onFocus={() => {
                  if (newParticipant.trim().length > 0) setShowGroupSuggestions(true);
                }}
                placeholder="Type username and press Enter"
                className="flex h-9 flex-1 rounded-md border border-input bg-background px-3 py-1 text-sm"
              />
              <button
                type="button"
                onClick={() => {
                  const login = newParticipant.trim();
                  if (login && !participantLogins.includes(login)) {
                    setParticipantLogins((prev) => [...prev, login]);
                    setNewParticipant('');
                    setShowGroupSuggestions(false);
                  }
                }}
                className="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm hover:bg-accent"
              >
                Add
              </button>

              {/* Group suggestions dropdown */}
              {showGroupSuggestions && filteredGroups.length > 0 && (
                <div className="absolute left-0 top-10 z-10 w-full rounded-md border border-border bg-card shadow-lg">
                  {filteredGroups.map((group) => (
                    <button
                      key={group.id}
                      type="button"
                      onClick={() => void handleSelectGroup(group)}
                      className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                    >
                      <span>👥</span>
                      <span>{group.name}</span>
                      <span className="text-xs text-muted-foreground">(group)</span>
                    </button>
                  ))}
                </div>
              )}
            </div>
            {participantLogins.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {participantLogins.map((login) => (
                  <span
                    key={login}
                    className="inline-flex items-center gap-1 rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium"
                  >
                    {login}
                    <button
                      type="button"
                      onClick={() => setParticipantLogins((prev) => prev.filter((l) => l !== login))}
                      className="ml-0.5 text-muted-foreground hover:text-foreground"
                      aria-label={`Remove ${login}`}
                    >
                      ✕
                    </button>
                  </span>
                ))}
              </div>
            )}
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
            >
              {isSaving ? 'Saving...' : mode === 'edit' ? 'Save Changes' : 'Create Event'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
