import { useState } from 'react';

export type RecurringScope = 'all' | 'occurrence' | 'future';

interface RecurringScopeDialogProps {
  open: boolean;
  mode: 'edit' | 'delete';
  eventTitle: string;
  onSelect: (scope: RecurringScope, date?: string) => void;
  onCancel: () => void;
}

/**
 * Dialog shown when editing or deleting a recurring event.
 * Lets the user choose scope: entire series, single occurrence, or from a date.
 */
export function RecurringScopeDialog({
  open,
  mode,
  eventTitle,
  onSelect,
  onCancel,
}: RecurringScopeDialogProps) {
  const [selectedDate, setSelectedDate] = useState('');

  if (!open) return null;

  const isEdit = mode === 'edit';

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
        <h2 className="text-lg font-semibold">
          {isEdit ? 'Edit Recurring Event' : 'Cancel Recurring Event'}
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          &ldquo;{eventTitle}&rdquo; is a recurring event. What would you like to {isEdit ? 'edit' : 'cancel'}?
        </p>

        <div className="mt-5 flex flex-col gap-2">
          <button
            type="button"
            onClick={() => onSelect('all')}
            className="inline-flex h-10 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
          >
            {isEdit ? 'Edit entire series' : 'Cancel entire series'}
          </button>

          <div className="rounded-md border border-border p-3">
            <label className="block text-xs font-medium text-muted-foreground">
              {isEdit ? 'Or pick a date to split the series:' : 'Or pick a specific date:'}
            </label>
            <input
              type="date"
              value={selectedDate}
              onChange={(e) => setSelectedDate(e.target.value)}
              className="mt-1.5 flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            />
            <div className="mt-2 flex gap-2">
              {!isEdit && (
                <button
                  type="button"
                  disabled={!selectedDate}
                  onClick={() => {
                    if (selectedDate) onSelect('occurrence', selectedDate.replace(/-/g, ''));
                  }}
                  className="inline-flex h-9 flex-1 items-center justify-center rounded-md border border-destructive px-3 text-sm font-medium text-destructive hover:bg-destructive/10 disabled:opacity-50"
                >
                  Cancel this date
                </button>
              )}
              <button
                type="button"
                disabled={!selectedDate}
                onClick={() => {
                  if (selectedDate) onSelect('future', selectedDate.replace(/-/g, ''));
                }}
                className={`inline-flex h-9 flex-1 items-center justify-center rounded-md border px-3 text-sm font-medium disabled:opacity-50 ${
                  isEdit
                    ? 'border-primary text-primary hover:bg-primary/10'
                    : 'border-destructive text-destructive hover:bg-destructive/10'
                }`}
              >
                {isEdit ? 'Edit from this date' : 'Cancel from this date'}
              </button>
            </div>
          </div>

          <button
            type="button"
            onClick={onCancel}
            className="inline-flex h-10 w-full items-center justify-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent"
          >
            Go back
          </button>
        </div>
      </div>
    </div>
  );
}
