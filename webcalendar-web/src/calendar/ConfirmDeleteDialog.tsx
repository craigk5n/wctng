interface ConfirmDeleteDialogProps {
  open: boolean;
  eventTitle: string;
  isRecurring?: boolean;
  isOrganizer?: boolean;
  onConfirm: () => void;
  onDeleteOccurrence?: () => void;
  onCancel: () => void;
  isDeleting: boolean;
}

export function ConfirmDeleteDialog({
  open,
  eventTitle,
  isRecurring = false,
  isOrganizer = true,
  onConfirm,
  onDeleteOccurrence,
  onCancel,
  isDeleting,
}: ConfirmDeleteDialogProps) {
  if (!open) return null;

  const heading = isOrganizer ? 'Cancel Event' : 'Decline Event';
  const description = isOrganizer
    ? `Cancel "${eventTitle}"? Participants will be notified. You can undo this.`
    : `Decline "${eventTitle}"? It will be removed from your calendar. You can undo this.`;
  const confirmLabel = isOrganizer
    ? isRecurring
      ? 'Cancel All Occurrences'
      : 'Cancel Event'
    : 'Decline';

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      onClick={onCancel}
      role="dialog"
      aria-modal="true"
    >
      <div
        className="w-full max-w-sm rounded-lg bg-card p-6 shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-lg font-semibold">{heading}</h2>
        <p className="mt-2 text-sm text-muted-foreground">
          {description}
          {isRecurring && isOrganizer && ' This is a recurring event.'}
        </p>

        <div className="mt-6 flex flex-col gap-2">
          {isRecurring && isOrganizer && onDeleteOccurrence && (
            <button
              type="button"
              onClick={onDeleteOccurrence}
              disabled={isDeleting}
              className="inline-flex h-10 w-full items-center justify-center rounded-md border border-destructive px-4 text-sm font-medium text-destructive hover:bg-destructive/10 disabled:opacity-50"
            >
              Cancel This Occurrence
            </button>
          )}
          <button
            type="button"
            onClick={onConfirm}
            disabled={isDeleting}
            className="inline-flex h-10 w-full items-center justify-center rounded-md bg-destructive px-4 text-sm font-medium text-destructive-foreground hover:bg-destructive/90 disabled:opacity-50"
          >
            {isDeleting ? (isOrganizer ? 'Canceling...' : 'Declining...') : confirmLabel}
          </button>
          <button
            type="button"
            onClick={onCancel}
            disabled={isDeleting}
            className="inline-flex h-10 w-full items-center justify-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent disabled:opacity-50"
          >
            Keep
          </button>
        </div>
      </div>
    </div>
  );
}
