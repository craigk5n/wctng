import { useState } from 'react';
import type { ApiEvent } from './eventMapper';
import { ParticipantList } from './ParticipantList';
import { RichTextDisplay } from '../components/editor/RichTextDisplay';
import { AttachmentSection } from './AttachmentSection';
import { CommentSection } from './CommentSection';
import { rruleToHuman } from './RecurrenceEditor';

const ACCESS_LABELS: Record<string, string> = {
  P: 'Public',
  C: 'Confidential',
  R: 'Private',
};

interface EventDetailDialogProps {
  event: ApiEvent;
  open: boolean;
  onClose: () => void;
  onEdit: () => void;
  onDelete: () => void;
  onDuplicate?: () => void;
  onExportIcs?: () => void;
  publicPageUrl?: string;
  currentUserLogin?: string;
  onAccept?: () => void;
  onReject?: () => void;
  isResponding?: boolean;
}

function formatDate(dateStr: string): string {
  return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
}

function formatTime(timeStr: string): string {
  return `${timeStr.slice(0, 2)}:${timeStr.slice(2, 4)}`;
}

function formatLongDate(dateStr: string): string {
  const y = Number(dateStr.slice(0, 4));
  const m = Number(dateStr.slice(4, 6)) - 1;
  const d = Number(dateStr.slice(6, 8));
  const date = new Date(y, m, d);
  if (Number.isNaN(date.getTime())) return formatDate(dateStr);
  return date.toLocaleDateString(undefined, {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

export function EventDetailDialog({
  event,
  open,
  onClose,
  onEdit,
  onDelete,
  onDuplicate,
  onExportIcs,
  publicPageUrl,
  currentUserLogin,
  onAccept,
  onReject,
  isResponding,
}: EventDetailDialogProps) {
  const [menuOpen, setMenuOpen] = useState(false);

  if (!open) return null;

  const isAllDay = event.all_day || !event.start_time;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="event-detail-title"
    >
      <div
        className="fixed inset-x-0 bottom-0 max-h-[90vh] overflow-y-auto rounded-t-xl bg-card p-6 shadow-lg md:static md:inset-auto md:w-full md:max-w-md md:rounded-lg"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-start justify-between">
          <h2 id="event-detail-title" className="text-lg font-semibold">
            {event.title}
          </h2>
          <button
            onClick={onClose}
            aria-label="Close"
            autoFocus
            className="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
          >
            ✕
          </button>
        </div>

        {/* Details */}
        <div className="mt-4 space-y-3 text-sm">
          <div className="flex items-center gap-2">
            <span aria-hidden="true">📅</span>
            <span>
              {formatLongDate(event.start_date)}
              {' · '}
              {isAllDay ? (
                'All day'
              ) : (
                <>
                  {formatTime(event.start_time!)}
                  {event.end_time && ` – ${formatTime(event.end_time)}`}
                  {event.duration > 0 && ` (${event.duration} min)`}
                </>
              )}
            </span>
          </div>

          {event.rrule && (
            <div className="flex gap-2">
              <span className="font-medium text-muted-foreground">Repeats:</span>
              <span className="flex items-center gap-1">
                <span className="text-xs">🔁</span>
                {rruleToHuman(event.rrule)}
              </span>
            </div>
          )}

          {event.location && (
            <div className="flex gap-2">
              <span className="font-medium text-muted-foreground">Location:</span>
              <span className="flex items-center gap-1.5">
                {event.location}
                {event.latitude != null && event.longitude != null ? (
                  <a
                    href={`https://www.openstreetmap.org/?mlat=${event.latitude}&mlon=${event.longitude}#map=16/${event.latitude}/${event.longitude}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-0.5 text-xs text-blue-600 hover:underline dark:text-blue-400"
                    title="View on Map"
                    data-testid="map-link"
                  >
                    📍 Map
                  </a>
                ) : (
                  <a
                    href={`https://www.openstreetmap.org/search?query=${encodeURIComponent(event.location)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-0.5 text-xs text-blue-600 hover:underline dark:text-blue-400"
                    title="Search on Map"
                    data-testid="map-search-link"
                  >
                    📍 Map
                  </a>
                )}
              </span>
            </div>
          )}

          {event.description && (
            <div>
              <span className="font-medium text-muted-foreground">Description:</span>
              <div className="mt-1 break-words [overflow-wrap:anywhere]">
                <RichTextDisplay html={event.description} />
              </div>
            </div>
          )}

          {currentUserLogin && event.created_by !== currentUserLogin && (
            <div className="flex gap-2">
              <span className="font-medium text-muted-foreground">Calendar:</span>
              <span>{event.created_by}</span>
            </div>
          )}

          <div className="flex gap-2">
            <span className="font-medium text-muted-foreground">Access:</span>
            <span>{ACCESS_LABELS[event.access] ?? event.access}</span>
          </div>

          {event.participants && event.participants.length > 0 && (
            <div>
              <span className="font-medium text-muted-foreground">Participants:</span>
              <div className="mt-1">
                <ParticipantList
                  participants={event.participants}
                  currentUserLogin={currentUserLogin}
                  onRespond={
                    onAccept && onReject
                      ? (next) => {
                          if (next === 'A') onAccept();
                          else if (next === 'R') onReject();
                        }
                      : undefined
                  }
                  isResponding={isResponding}
                />
              </div>
            </div>
          )}

          {event.ext_participants && event.ext_participants.length > 0 && (
            <div>
              <span className="font-medium text-muted-foreground">External participants:</span>
              <ul className="mt-1 flex flex-wrap gap-1.5">
                {event.ext_participants.map((p) => (
                  <li
                    key={p.name}
                    className="inline-flex items-center gap-1 rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium"
                  >
                    <span>✉️ {p.name}</span>
                    {p.email && (
                      <a
                        href={`mailto:${p.email}`}
                        className="text-muted-foreground hover:underline"
                      >
                        &lt;{p.email}&gt;
                      </a>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>

        {/* Attachments */}
        <div className="mt-4">
          <AttachmentSection
            eventId={event.id}
            currentUserLogin={currentUserLogin}
            eventOwner={event.created_by}
          />
        </div>

        {/* Comments */}
        <CommentSection eventId={event.id} currentUserLogin={currentUserLogin} />

        {/* Actions */}
        <div className="mt-6 flex items-center gap-2">
          {(!currentUserLogin || event.created_by === currentUserLogin) && (
            <>
              <button
                onClick={onEdit}
                className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
              >
                Edit
              </button>
              <button
                onClick={onDelete}
                className="inline-flex h-9 items-center rounded-md border border-destructive/40 bg-transparent px-4 text-sm font-medium text-destructive hover:bg-destructive/10"
              >
                Delete
              </button>
            </>
          )}

          {/* Overflow menu */}
          <div className="relative ml-auto">
            <button
              onClick={() => setMenuOpen(!menuOpen)}
              aria-label="More actions"
              className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-input text-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground"
            >
              ⋮
            </button>

            {menuOpen && (
              <div className="absolute bottom-full right-0 mb-1 w-44 rounded-md border border-border bg-card py-1 shadow-lg">
                {onDuplicate && (
                  <button
                    onClick={() => {
                      setMenuOpen(false);
                      onDuplicate();
                    }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                  >
                    <span>📋</span> Duplicate
                  </button>
                )}
                {onExportIcs && (
                  <button
                    onClick={() => {
                      setMenuOpen(false);
                      onExportIcs();
                    }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                  >
                    <span>📥</span> Export as ICS
                  </button>
                )}
                {publicPageUrl && (
                  <a
                    href={publicPageUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    onClick={() => setMenuOpen(false)}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                  >
                    <span>🌐</span> View Public Page
                  </a>
                )}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
