import { useState } from 'react';
import type { ApiEvent } from './eventMapper';
import { ParticipantList } from './ParticipantList';
import { ParticipantResponse } from './ParticipantResponse';
import { RichTextDisplay } from '../components/editor/RichTextDisplay';
import { AttachmentSection } from './AttachmentSection';
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

export function EventDetailDialog({
  event, open, onClose, onEdit, onDelete, onDuplicate, onExportIcs,
  currentUserLogin, onAccept, onReject, isResponding,
}: EventDetailDialogProps) {
  const [menuOpen, setMenuOpen] = useState(false);

  if (!open) return null;

  const isAllDay = event.all_day || !event.start_time;

  // Check if current user is a participant
  const currentUserParticipant = currentUserLogin
    ? event.participants?.find((p) => p.login === currentUserLogin)
    : undefined;

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
            className="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
          >
            ✕
          </button>
        </div>

        {/* Details */}
        <div className="mt-4 space-y-3 text-sm">
          <div className="flex gap-2">
            <span className="font-medium text-muted-foreground">Date:</span>
            <span>{formatDate(event.start_date)}</span>
          </div>

          <div className="flex gap-2">
            <span className="font-medium text-muted-foreground">Time:</span>
            <span>
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
              <span>{event.location}</span>
            </div>
          )}

          {event.description && (
            <div>
              <span className="font-medium text-muted-foreground">Description:</span>
              <div className="mt-1">
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
                <ParticipantList participants={event.participants} />
              </div>
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

        {/* Participant response (Accept/Reject) */}
        {currentUserParticipant && onAccept && onReject && (
          <div className="mt-4 rounded-md border border-border p-3">
            <span className="text-sm font-medium text-muted-foreground">Your response:</span>
            <div className="mt-1.5">
              <ParticipantResponse
                status={currentUserParticipant.status}
                onAccept={onAccept}
                onReject={onReject}
                isLoading={isResponding}
              />
            </div>
          </div>
        )}

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
                className="inline-flex h-9 items-center rounded-md bg-destructive px-4 text-sm font-medium text-destructive-foreground hover:bg-destructive/90"
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
                    onClick={() => { setMenuOpen(false); onDuplicate(); }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                  >
                    <span>📋</span> Duplicate
                  </button>
                )}
                {onExportIcs && (
                  <button
                    onClick={() => { setMenuOpen(false); onExportIcs(); }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                  >
                    <span>📥</span> Export as ICS
                  </button>
                )}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
