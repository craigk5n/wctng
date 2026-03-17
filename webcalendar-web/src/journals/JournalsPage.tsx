import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { RichTextEditor } from '../components/editor/RichTextEditor';

interface JournalEntry {
  id: number;
  title: string;
  text: string;
  date: string;
}

function formatDate(yyyymmdd: string): string {
  return `${yyyymmdd.slice(0, 4)}-${yyyymmdd.slice(4, 6)}-${yyyymmdd.slice(6, 8)}`;
}

export function JournalsPage() {
  const [entries, setEntries] = useState<JournalEntry[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [newTitle, setNewTitle] = useState('');
  const [newDate, setNewDate] = useState('');
  const [newText, setNewText] = useState('');
  const [isCreating, setIsCreating] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editTitle, setEditTitle] = useState('');
  const [editText, setEditText] = useState('');
  const { toast } = useToast();

  const fetchEntries = useCallback(async () => {
    setIsLoading(true);
    const { data } = await apiFetch<JournalEntry[]>('/journals?start=20200101&end=20301231');
    // Sort newest first
    const sorted = (data ?? []).sort((a, b) => b.date.localeCompare(a.date));
    setEntries(sorted);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    void fetchEntries();
  }, [fetchEntries]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTitle.trim()) return;
    setIsCreating(true);

    const body: Record<string, string> = { title: newTitle.trim(), text: newText };
    if (newDate) body.date = newDate.replace(/-/g, '');

    const { error } = await apiFetch('/journals', {
      method: 'POST',
      body: JSON.stringify(body),
    });

    setIsCreating(false);
    if (!error) {
      toast({ title: 'Journal entry created', variant: 'success' });
      setNewTitle('');
      setNewDate('');
      setNewText('');
      setShowCreate(false);
      void fetchEntries();
    } else {
      toast({ title: 'Failed to create entry', variant: 'error' });
    }
  };

  const handleDelete = async (entry: JournalEntry) => {
    const { error } = await apiFetch(`/journals/${entry.id}`, { method: 'DELETE' });
    if (!error) {
      toast({ title: 'Entry deleted', variant: 'success' });
      void fetchEntries();
    }
  };

  const startEdit = (entry: JournalEntry) => {
    setEditingId(entry.id);
    setEditTitle(entry.title);
    setEditText(entry.text);
  };

  const handleSaveEdit = async () => {
    if (editingId === null) return;
    const { error } = await apiFetch(`/journals/${editingId}`, {
      method: 'PUT',
      body: JSON.stringify({ title: editTitle, text: editText }),
    });
    if (!error) {
      toast({ title: 'Entry updated', variant: 'success' });
      setEditingId(null);
      void fetchEntries();
    }
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Journals</h2>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          {showCreate ? 'Cancel' : '+ New Entry'}
        </button>
      </div>

      {/* Create form */}
      {showCreate && (
        <form onSubmit={handleCreate} className="mt-4 space-y-3 rounded-lg border border-border p-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1">
              <label htmlFor="j-title" className="text-sm font-medium">Title</label>
              <input
                id="j-title"
                type="text"
                required
                value={newTitle}
                onChange={(e) => setNewTitle(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                placeholder="Entry title"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="j-date" className="text-sm font-medium">Date</label>
              <input
                id="j-date"
                type="date"
                value={newDate}
                onChange={(e) => setNewDate(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div className="space-y-1">
            <label className="text-sm font-medium">Content</label>
            <RichTextEditor
              content={newText}
              onChange={setNewText}
              placeholder="Write your thoughts..."
            />
          </div>
          <button
            type="submit"
            disabled={isCreating}
            className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {isCreating ? 'Creating...' : 'Create Entry'}
          </button>
        </form>
      )}

      {/* Entries list */}
      <div className="mt-6 space-y-3">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading...</p>
        ) : entries.length === 0 ? (
          <p className="text-sm text-muted-foreground">No journal entries yet. Create one to get started.</p>
        ) : (
          entries.map((entry) => (
            <div key={entry.id} className="rounded-lg border border-border p-4">
              {editingId === entry.id ? (
                <div className="space-y-2">
                  <input
                    type="text"
                    value={editTitle}
                    onChange={(e) => setEditTitle(e.target.value)}
                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                  />
                  <RichTextEditor
                    content={editText}
                    onChange={setEditText}
                  />
                  <div className="flex gap-2">
                    <button
                      onClick={handleSaveEdit}
                      className="rounded bg-primary px-3 py-1 text-xs text-primary-foreground"
                    >
                      Save
                    </button>
                    <button
                      onClick={() => setEditingId(null)}
                      className="rounded px-3 py-1 text-xs text-muted-foreground hover:bg-accent"
                    >
                      Cancel
                    </button>
                  </div>
                </div>
              ) : (
                <>
                  <div className="flex items-start justify-between">
                    <div>
                      <h3 className="font-medium">{entry.title}</h3>
                      <span className="text-xs text-muted-foreground">{formatDate(entry.date)}</span>
                    </div>
                    <div className="flex gap-1">
                      <button
                        onClick={() => startEdit(entry)}
                        className="rounded px-2 py-1 text-xs text-muted-foreground hover:bg-accent"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => handleDelete(entry)}
                        className="rounded px-2 py-1 text-xs text-destructive hover:bg-destructive/10"
                      >
                        Delete
                      </button>
                    </div>
                  </div>
                  {entry.text && (
                    <p className="mt-2 text-sm text-muted-foreground whitespace-pre-wrap">{entry.text}</p>
                  )}
                </>
              )}
            </div>
          ))
        )}
      </div>
    </div>
  );
}
