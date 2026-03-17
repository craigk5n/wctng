export interface ConflictInfo {
  id: number;
  title: string;
  start: string;
  end: string;
}

interface ConflictWarningProps {
  conflicts: ConflictInfo[];
  mode: 'warn' | 'block';
  onDismiss: () => void;
}

function formatTime(isoStr: string): string {
  const d = new Date(isoStr);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

export function ConflictWarning({ conflicts, mode, onDismiss }: ConflictWarningProps) {
  if (conflicts.length === 0) return null;

  const isBlock = mode === 'block';

  return (
    <div
      className={`rounded-md border p-3 text-sm ${
        isBlock
          ? 'border-destructive bg-destructive/10 text-destructive'
          : 'border-yellow-500 bg-yellow-50 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-200'
      }`}
      role="alert"
    >
      <p className="font-medium">
        {isBlock
          ? 'This event cannot be saved — it conflicts with:'
          : `${conflicts.length} scheduling conflict${conflicts.length > 1 ? 's' : ''} detected:`}
      </p>
      <ul className="mt-1.5 space-y-1">
        {conflicts.map((c) => (
          <li key={c.id} className="flex items-center gap-1.5 text-xs">
            <span className="font-medium">{c.title}</span>
            <span className="text-opacity-70">
              {formatTime(c.start)} – {formatTime(c.end)}
            </span>
          </li>
        ))}
      </ul>
      {!isBlock && (
        <button
          type="button"
          onClick={onDismiss}
          className="mt-2 rounded border border-yellow-600 px-3 py-1 text-xs font-medium hover:bg-yellow-100 dark:hover:bg-yellow-800/30"
        >
          Save Anyway
        </button>
      )}
    </div>
  );
}
