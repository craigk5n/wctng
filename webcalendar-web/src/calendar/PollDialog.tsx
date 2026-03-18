import { useState } from 'react';
import { apiFetch } from '../api/client';

interface TimeOption {
  date: string;
  startTime: string;
  endTime: string;
}

interface PollDialogProps {
  open: boolean;
  onClose: () => void;
  onCreated?: () => void;
}

export function PollDialog({ open, onClose, onCreated }: PollDialogProps) {
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [options, setOptions] = useState<TimeOption[]>([
    { date: '', startTime: '', endTime: '' },
    { date: '', startTime: '', endTime: '' },
  ]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  if (!open) return null;

  const addOption = () => {
    if (options.length >= 10) return;
    setOptions([...options, { date: '', startTime: '', endTime: '' }]);
  };

  const removeOption = (index: number) => {
    if (options.length <= 2) return;
    setOptions(options.filter((_, i) => i !== index));
  };

  const updateOption = (index: number, field: keyof TimeOption, value: string) => {
    const updated = [...options];
    updated[index] = { ...updated[index], [field]: value };
    setOptions(updated);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!title.trim()) {
      setError('Title is required');
      return;
    }

    const validOptions = options.filter((o) => o.date && o.startTime && o.endTime);
    if (validOptions.length < 2) {
      setError('At least 2 complete time options required');
      return;
    }

    setSaving(true);
    const { error: apiError } = await apiFetch('/polls', {
      method: 'POST',
      body: JSON.stringify({
        title: title.trim(),
        description: description.trim(),
        options: validOptions.map((o) => ({
          start: `${o.date} ${o.startTime}:00`,
          end: `${o.date} ${o.endTime}:00`,
        })),
      }),
    });

    setSaving(false);
    if (apiError) {
      setError(apiError.message || 'Failed to create poll');
    } else {
      onCreated?.();
      onClose();
      setTitle('');
      setDescription('');
      setOptions([
        { date: '', startTime: '', endTime: '' },
        { date: '', startTime: '', endTime: '' },
      ]);
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
        className="fixed inset-0 overflow-y-auto bg-card p-6 shadow-lg md:static md:inset-auto md:w-full md:max-w-lg md:max-h-[85vh] md:overflow-y-auto md:rounded-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-lg font-semibold">Schedule Meeting</h2>

        <form onSubmit={handleSubmit} className="mt-4 space-y-4">
          {error && (
            <div role="alert" className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
              {error}
            </div>
          )}

          <div className="space-y-2">
            <label htmlFor="poll-title" className="text-sm font-medium">Title</label>
            <input
              id="poll-title"
              type="text"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              required
              autoFocus
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              placeholder="Meeting title"
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="poll-desc" className="text-sm font-medium">Description</label>
            <textarea
              id="poll-desc"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={2}
              className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              placeholder="Optional description"
            />
          </div>

          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium">Proposed Times ({options.length}/10)</span>
              <button
                type="button"
                onClick={addOption}
                disabled={options.length >= 10}
                className="rounded bg-secondary px-3 py-1 text-xs font-medium hover:bg-secondary/80 disabled:opacity-50"
              >
                Add Time
              </button>
            </div>

            {options.map((opt, i) => (
              <div key={i} className="flex items-end gap-2 rounded border bg-muted/30 p-2">
                <div className="flex-1 space-y-1">
                  <label className="text-xs text-muted-foreground">Date</label>
                  <input
                    type="date"
                    value={opt.date}
                    onChange={(e) => updateOption(i, 'date', e.target.value)}
                    className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                  />
                </div>
                <div className="w-24 space-y-1">
                  <label className="text-xs text-muted-foreground">Start</label>
                  <input
                    type="time"
                    value={opt.startTime}
                    onChange={(e) => updateOption(i, 'startTime', e.target.value)}
                    className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                  />
                </div>
                <div className="w-24 space-y-1">
                  <label className="text-xs text-muted-foreground">End</label>
                  <input
                    type="time"
                    value={opt.endTime}
                    onChange={(e) => updateOption(i, 'endTime', e.target.value)}
                    className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                  />
                </div>
                {options.length > 2 && (
                  <button
                    type="button"
                    onClick={() => removeOption(i)}
                    className="h-8 rounded px-2 text-xs text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                  >
                    ✕
                  </button>
                )}
              </div>
            ))}
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
              disabled={saving}
              className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
            >
              {saving ? 'Creating...' : 'Create Poll'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
