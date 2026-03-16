import type { ApiEvent } from './eventMapper';

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
}

function formatDate(dateStr: string): string {
  return `${dateStr.slice(0, 4)}-${dateStr.slice(4, 6)}-${dateStr.slice(6, 8)}`;
}

function formatTime(timeStr: string): string {
  return `${timeStr.slice(0, 2)}:${timeStr.slice(2, 4)}`;
}

export function EventDetailDialog({ event, open, onClose, onEdit, onDelete }: EventDetailDialogProps) {
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
        className="w-full max-w-md rounded-lg bg-card p-6 shadow-lg"
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

          {event.location && (
            <div className="flex gap-2">
              <span className="font-medium text-muted-foreground">Location:</span>
              <span>{event.location}</span>
            </div>
          )}

          {event.description && (
            <div>
              <span className="font-medium text-muted-foreground">Description:</span>
              <p className="mt-1 text-muted-foreground">{event.description}</p>
            </div>
          )}

          <div className="flex gap-2">
            <span className="font-medium text-muted-foreground">Access:</span>
            <span>{ACCESS_LABELS[event.access] ?? event.access}</span>
          </div>
        </div>

        {/* Actions */}
        <div className="mt-6 flex gap-2">
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
        </div>
      </div>
    </div>
  );
}
