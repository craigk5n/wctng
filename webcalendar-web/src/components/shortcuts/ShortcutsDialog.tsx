interface ShortcutsDialogProps {
  open: boolean;
  onClose: () => void;
}

const SHORTCUTS = [
  { category: 'Navigation', items: [
    { keys: '←', description: 'Previous period' },
    { keys: '→', description: 'Next period' },
    { keys: 'T', description: 'Go to today' },
  ]},
  { category: 'Views', items: [
    { keys: 'M', description: 'Month view' },
    { keys: 'W', description: 'Week view' },
    { keys: 'D', description: 'Day view' },
  ]},
  { category: 'Actions', items: [
    { keys: 'N', description: 'New event' },
    { keys: 'Esc', description: 'Close dialog' },
    { keys: '?', description: 'Show this help' },
  ]},
];

export function ShortcutsDialog({ open, onClose }: ShortcutsDialogProps) {
  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
    >
      <div
        className="w-full max-w-md rounded-lg bg-card p-6 shadow-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold">Keyboard Shortcuts</h2>
          <button
            onClick={onClose}
            aria-label="Close"
            className="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
          >
            ✕
          </button>
        </div>

        <div className="mt-4 space-y-4">
          {SHORTCUTS.map((section) => (
            <div key={section.category}>
              <h3 className="text-sm font-medium text-muted-foreground">{section.category}</h3>
              <div className="mt-1.5 space-y-1">
                {section.items.map((item) => (
                  <div key={item.keys} className="flex items-center justify-between py-1">
                    <span className="text-sm">{item.description}</span>
                    <kbd className="rounded border border-border bg-muted px-2 py-0.5 font-mono text-xs">
                      {item.keys}
                    </kbd>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
