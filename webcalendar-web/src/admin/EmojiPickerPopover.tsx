import { lazy, Suspense, useState } from 'react';

// Lazy-load the picker — emoji data is ~100kb+ and should not land in the
// main bundle. It's only needed when the user clicks to pick an emoji.
const EmojiPickerInner = lazy(() =>
  import('./EmojiPickerInner').then((m) => ({ default: m.EmojiPickerInner })),
);

interface EmojiPickerPopoverProps {
  value: string | null;
  onChange: (value: string | null) => void;
  labelledBy?: string;
}

/**
 * Small emoji button that opens a popover-style picker below it.
 * Click the displayed emoji to change it, click "×" to clear it.
 */
export function EmojiPickerPopover({ value, onChange, labelledBy }: EmojiPickerPopoverProps) {
  const [open, setOpen] = useState(false);

  return (
    <div className="relative">
      <div className="flex items-center gap-1">
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          aria-labelledby={labelledBy}
          aria-label={labelledBy ? undefined : value ? 'Change emoji' : 'Pick an emoji'}
          aria-haspopup="dialog"
          aria-expanded={open}
          className="flex h-10 w-10 items-center justify-center rounded-md border border-input bg-background text-xl hover:bg-accent"
          title={value ? 'Change emoji' : 'Pick an emoji'}
        >
          {value ?? '😀'}
        </button>
        {value && (
          <button
            type="button"
            onClick={() => onChange(null)}
            className="text-xs text-muted-foreground hover:text-destructive"
            aria-label="Clear emoji"
            title="Clear emoji"
          >
            ×
          </button>
        )}
      </div>

      {open && (
        <div
          role="dialog"
          aria-label="Emoji picker"
          className="absolute left-0 top-12 z-50 rounded-md border border-border bg-background shadow-lg"
        >
          <Suspense fallback={<div className="p-4 text-sm text-muted-foreground">Loading picker…</div>}>
            <EmojiPickerInner
              onSelect={(emoji) => {
                onChange(emoji);
                setOpen(false);
              }}
            />
          </Suspense>
          <div className="border-t border-border p-2 text-right">
            <button
              type="button"
              onClick={() => setOpen(false)}
              className="text-xs text-muted-foreground hover:text-foreground"
            >
              Close
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
