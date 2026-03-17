import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { RichTextEditor } from '../components/editor/RichTextEditor';
import { useFeatureFlags } from '../hooks/useFeatureFlags';

interface Task {
  id: number;
  title: string;
  due_date: string | null;
  percent_complete: number;
  status: string;
  priority: number;
  description?: string;
}

type Filter = 'all' | 'pending' | 'completed';

function formatDate(yyyymmdd: string | null): string {
  if (!yyyymmdd) return '—';
  return `${yyyymmdd.slice(0, 4)}-${yyyymmdd.slice(4, 6)}-${yyyymmdd.slice(6, 8)}`;
}

export function TasksPage() {
  const [tasks, setTasks] = useState<Task[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [filter, setFilter] = useState<Filter>('all');
  const [showCreate, setShowCreate] = useState(false);
  const [newTitle, setNewTitle] = useState('');
  const [newDescription, setNewDescription] = useState('');
  const [newDueDate, setNewDueDate] = useState('');
  const [isCreating, setIsCreating] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editTitle, setEditTitle] = useState('');
  const { toast } = useToast();
  const flags = useFeatureFlags();

  const fetchTasks = useCallback(async () => {
    setIsLoading(true);
    const { data } = await apiFetch<Task[]>('/tasks?start=20200101&end=20301231');
    setTasks(data ?? []);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    void fetchTasks();
  }, [fetchTasks]);

  const filteredTasks = tasks.filter((t) => {
    if (filter === 'pending') return t.status === 'pending';
    if (filter === 'completed') return t.status === 'completed';
    return true;
  });

  const handleToggleComplete = async (task: Task) => {
    const newPercent = task.percent_complete >= 100 ? 0 : 100;
    const { error } = await apiFetch(`/tasks/${task.id}`, {
      method: 'PUT',
      body: JSON.stringify({ percent_complete: newPercent }),
    });
    if (!error) {
      void fetchTasks();
    }
  };

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTitle.trim()) return;
    setIsCreating(true);

    const body: Record<string, unknown> = { title: newTitle.trim() };
    if (newDescription.trim()) {
      body.description = newDescription.trim();
    }
    if (newDueDate) {
      body.due_date = newDueDate.replace(/-/g, '');
    }

    const { error } = await apiFetch('/tasks', {
      method: 'POST',
      body: JSON.stringify(body),
    });

    setIsCreating(false);
    if (!error) {
      toast({ title: 'Task created', variant: 'success' });
      setNewTitle('');
      setNewDescription('');
      setNewDueDate('');
      setShowCreate(false);
      void fetchTasks();
    } else {
      toast({ title: 'Failed to create task', variant: 'error' });
    }
  };

  const startEditing = (task: Task) => {
    setEditingId(task.id);
    setEditTitle(task.title);
  };

  const handleSaveEdit = async () => {
    if (editingId === null || !editTitle.trim()) return;
    const { error } = await apiFetch(`/tasks/${editingId}`, {
      method: 'PUT',
      body: JSON.stringify({ title: editTitle.trim() }),
    });
    if (!error) {
      toast({ title: 'Task updated', variant: 'success' });
      setEditingId(null);
      void fetchTasks();
    } else {
      toast({ title: 'Failed to update task', variant: 'error' });
    }
  };

  const handleDelete = async (task: Task) => {
    const { error } = await apiFetch(`/tasks/${task.id}`, { method: 'DELETE' });
    if (!error) {
      toast({ title: 'Task deleted', variant: 'success' });
      void fetchTasks();
    }
  };

  const filterBtnClass = (f: Filter) =>
    `rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
      filter === f ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent'
    }`;

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Tasks</h2>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          {showCreate ? 'Cancel' : '+ New Task'}
        </button>
      </div>

      {/* Create form */}
      {showCreate && (
        <form onSubmit={handleCreate} className="mt-4 space-y-3 rounded-lg border border-border p-4">
          <div className="flex items-end gap-3">
            <div className="flex-1 space-y-1">
              <label htmlFor="task-title" className="text-sm font-medium">Title</label>
              <input
                id="task-title"
                type="text"
                required
                value={newTitle}
                onChange={(e) => setNewTitle(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                placeholder="Task title"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="task-due" className="text-sm font-medium">Due Date</label>
              <input
                id="task-due"
                type="date"
                value={newDueDate}
                onChange={(e) => setNewDueDate(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div className="space-y-1">
            <label className="text-sm font-medium">Description</label>
            {flags.ALLOW_HTML_DESCRIPTION === 'Y' ? (
              <RichTextEditor
                content={newDescription}
                onChange={setNewDescription}
                placeholder="Optional description"
              />
            ) : (
              <textarea
                value={newDescription}
                onChange={(e) => setNewDescription(e.target.value)}
                rows={3}
                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                placeholder="Optional description"
              />
            )}
          </div>
          <button
            type="submit"
            disabled={isCreating}
            className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {isCreating ? 'Creating...' : 'Create'}
          </button>
        </form>
      )}

      {/* Filters */}
      <div className="mt-4 flex gap-1">
        <button className={filterBtnClass('all')} onClick={() => setFilter('all')}>All</button>
        <button className={filterBtnClass('pending')} onClick={() => setFilter('pending')}>Pending</button>
        <button className={filterBtnClass('completed')} onClick={() => setFilter('completed')}>Completed</button>
      </div>

      {/* Task list */}
      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading tasks...</p>
        ) : filteredTasks.length === 0 ? (
          <p className="text-sm text-muted-foreground">No tasks found.</p>
        ) : (
          <div className="space-y-1">
            {filteredTasks.map((task) => (
              <div
                key={task.id}
                className={`flex items-center justify-between rounded-lg border border-border px-4 py-3 ${
                  task.status === 'completed' ? 'opacity-60' : ''
                }`}
              >
                <div className="flex items-center gap-3">
                  <input
                    type="checkbox"
                    checked={task.percent_complete >= 100}
                    onChange={() => handleToggleComplete(task)}
                    className="h-4 w-4 rounded border-input"
                    aria-label={`Mark "${task.title}" ${task.percent_complete >= 100 ? 'incomplete' : 'complete'}`}
                  />
                  {editingId === task.id ? (
                    <div className="flex items-center gap-2">
                      <input
                        type="text"
                        value={editTitle}
                        onChange={(e) => setEditTitle(e.target.value)}
                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        autoFocus
                        aria-label="Edit task title"
                        onKeyDown={(e) => { if (e.key === 'Enter') void handleSaveEdit(); }}
                      />
                      <button
                        onClick={() => void handleSaveEdit()}
                        className="rounded bg-primary px-2 py-1 text-xs text-primary-foreground hover:bg-primary/90"
                      >
                        Save
                      </button>
                      <button
                        onClick={() => setEditingId(null)}
                        className="rounded px-2 py-1 text-xs text-muted-foreground hover:bg-accent"
                      >
                        Cancel
                      </button>
                    </div>
                  ) : (
                    <div>
                      <span className={`font-medium ${task.status === 'completed' ? 'line-through' : ''}`}>
                        {task.title}
                      </span>
                      <div className="text-xs text-muted-foreground">
                        Due: {formatDate(task.due_date)}
                        {task.percent_complete > 0 && task.percent_complete < 100 && (
                          <span className="ml-2">{task.percent_complete}%</span>
                        )}
                      </div>
                    </div>
                  )}
                </div>
                {editingId !== task.id && (
                  <div className="flex gap-1">
                    <button
                      onClick={() => startEditing(task)}
                      className="rounded px-2 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                    >
                      Edit
                    </button>
                    <button
                      onClick={() => handleDelete(task)}
                      className="rounded px-2 py-1 text-xs text-destructive hover:bg-destructive/10"
                    >
                      Delete
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
