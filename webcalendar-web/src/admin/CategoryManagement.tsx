import { useCallback, useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';
import { useAuth } from '../auth/auth-context';
import { EmojiPickerPopover } from './EmojiPickerPopover';

interface Category {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
  is_global: boolean;
  owner: string | null;
}

export function CategoryManagement() {
  const [categories, setCategories] = useState<Category[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [newName, setNewName] = useState('');
  const [newColor, setNewColor] = useState('#3788d8');
  const [newIcon, setNewIcon] = useState<string | null>(null);
  const [newIsGlobal, setNewIsGlobal] = useState(false);
  const [isCreating, setIsCreating] = useState(false);
  const { user } = useAuth();
  const isAdmin = user?.is_admin ?? false;
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editName, setEditName] = useState('');
  const [editColor, setEditColor] = useState('');
  const [editIcon, setEditIcon] = useState<string | null>(null);
  const [showMerge, setShowMerge] = useState(false);
  const [mergeSource, setMergeSource] = useState(0);
  const [mergeTarget, setMergeTarget] = useState(0);
  const { toast } = useToast();
  const queryClient = useQueryClient();

  /**
   * Invalidate the shared ['categories'] React Query cache so that
   * EventDialog / FullCalendarWrapper / EventTooltip see fresh data after
   * any create/update/delete/merge here. Without this, the edit dialog
   * keeps serving stale cached categories until staleTime (5 min) elapses.
   */
  const invalidateSharedCategories = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ['categories'] });
  }, [queryClient]);

  const fetchCategories = useCallback(async () => {
    setIsLoading(true);
    const { data } = await apiFetch<Category[]>('/categories');
    setCategories(data ?? []);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    void fetchCategories();
  }, [fetchCategories]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newName.trim()) return;
    setIsCreating(true);

    const { error } = await apiFetch('/categories', {
      method: 'POST',
      body: JSON.stringify({
        name: newName.trim(),
        color: newColor,
        icon: newIcon,
        is_global: isAdmin && newIsGlobal,
      }),
    });

    setIsCreating(false);

    if (!error) {
      toast({ title: `Category "${newName}" created`, variant: 'success' });
      setNewName('');
      setNewColor('#3788d8');
      setNewIcon(null);
      setNewIsGlobal(false);
      setShowCreateForm(false);
      void fetchCategories();
      invalidateSharedCategories();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  const handleDelete = async (cat: Category) => {
    const { error } = await apiFetch(`/categories/${cat.id}`, { method: 'DELETE' });

    if (!error) {
      toast({ title: `Category "${cat.name}" deleted`, variant: 'success' });
      void fetchCategories();
      invalidateSharedCategories();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  const handleMerge = async () => {
    if (mergeSource <= 0 || mergeTarget <= 0 || mergeSource === mergeTarget) return;

    const { data, error } = await apiFetch<{
      merged_events: number;
      source: string;
      target: string;
    }>('/admin/categories/merge', {
      method: 'POST',
      body: JSON.stringify({ source_id: mergeSource, target_id: mergeTarget }),
    });

    if (error) {
      toast({ title: error.message ?? 'Merge failed', variant: 'error' });
      return;
    }
    toast({
      title: `Merged "${data?.source}" into "${data?.target}" (${data?.merged_events} events)`,
      variant: 'success',
    });
    setShowMerge(false);
    setMergeSource(0);
    setMergeTarget(0);
    void fetchCategories();
    invalidateSharedCategories();
  };

  const handleToggleGlobal = async (cat: Category) => {
    const { error } = await apiFetch(`/categories/${cat.id}`, {
      method: 'PUT',
      body: JSON.stringify({ is_global: !cat.is_global }),
    });
    if (error) {
      toast({ title: error.message ?? 'Failed to update', variant: 'error' });
      return;
    }
    toast({
      title: cat.is_global ? 'Category is now personal' : 'Category is now global',
      variant: 'success',
    });
    void fetchCategories();
    invalidateSharedCategories();
  };

  const startEditing = (cat: Category) => {
    setEditingId(cat.id);
    setEditName(cat.name);
    setEditColor(cat.color ?? '#3788d8');
    setEditIcon(cat.icon ?? null);
  };

  const handleSaveEdit = async () => {
    if (editingId === null || !editName.trim()) return;

    const { error } = await apiFetch(`/categories/${editingId}`, {
      method: 'PUT',
      body: JSON.stringify({ name: editName.trim(), color: editColor, icon: editIcon }),
    });

    if (!error) {
      toast({ title: 'Category updated', variant: 'success' });
      setEditingId(null);
      void fetchCategories();
      invalidateSharedCategories();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Categories</h2>
        <div className="flex gap-2">
          {isAdmin && (
            <button
              onClick={() => setShowMerge(!showMerge)}
              className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent"
            >
              {showMerge ? 'Cancel Merge' : 'Merge'}
            </button>
          )}
          <button
            onClick={() => setShowCreateForm(!showCreateForm)}
            className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
          >
            {showCreateForm ? 'Cancel' : '+ New Category'}
          </button>
        </div>
      </div>

      {/* Merge Dialog */}
      {showMerge && (
        <div className="mt-4 space-y-3 rounded-lg border border-border p-4">
          <h3 className="text-sm font-semibold">Merge Categories</h3>
          <p className="text-xs text-muted-foreground">
            All events from the source category will be reassigned to the target. The source will be
            deleted.
          </p>
          <div className="flex items-end gap-3">
            <div className="flex-1 space-y-1">
              <label htmlFor="merge-source" className="text-xs font-medium">
                Source (will be deleted)
              </label>
              <select
                id="merge-source"
                value={mergeSource}
                onChange={(e) => setMergeSource(Number(e.target.value))}
                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              >
                <option value={0}>Select source...</option>
                {categories
                  .filter((c) => c.id !== mergeTarget)
                  .map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.is_global ? 'Global' : 'Personal'})
                    </option>
                  ))}
              </select>
            </div>
            <div className="px-2 text-muted-foreground">into</div>
            <div className="flex-1 space-y-1">
              <label htmlFor="merge-target" className="text-xs font-medium">
                Target (will be kept)
              </label>
              <select
                id="merge-target"
                value={mergeTarget}
                onChange={(e) => setMergeTarget(Number(e.target.value))}
                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              >
                <option value={0}>Select target...</option>
                {categories
                  .filter((c) => c.id !== mergeSource)
                  .map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.is_global ? 'Global' : 'Personal'})
                    </option>
                  ))}
              </select>
            </div>
            <button
              onClick={() => void handleMerge()}
              disabled={mergeSource <= 0 || mergeTarget <= 0 || mergeSource === mergeTarget}
              className="inline-flex h-9 items-center rounded-md bg-destructive px-4 text-sm font-medium text-destructive-foreground hover:bg-destructive/90 disabled:opacity-50"
            >
              Merge
            </button>
          </div>
        </div>
      )}

      {/* Create Form */}
      {showCreateForm && (
        <form
          onSubmit={handleCreate}
          className="mt-4 space-y-3 rounded-lg border border-border p-4"
        >
          <div className="flex items-end gap-3">
            <div className="flex-1 space-y-1">
              <label htmlFor="cat-name" className="text-sm font-medium">
                Name
              </label>
              <input
                id="cat-name"
                type="text"
                required
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                placeholder="Category name"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="cat-color" className="text-sm font-medium">
                Color
              </label>
              <input
                id="cat-color"
                type="color"
                value={newColor}
                onChange={(e) => setNewColor(e.target.value)}
                className="h-10 w-16 cursor-pointer rounded-md border border-input"
              />
            </div>
            <div className="space-y-1">
              <span id="cat-icon-label" className="block text-sm font-medium">
                Icon
              </span>
              <EmojiPickerPopover
                value={newIcon}
                onChange={setNewIcon}
                labelledBy="cat-icon-label"
              />
            </div>
          </div>
          {isAdmin && (
            <div className="flex items-center gap-2">
              <input
                id="cat-global"
                type="checkbox"
                checked={newIsGlobal}
                onChange={(e) => setNewIsGlobal(e.target.checked)}
                className="h-4 w-4"
              />
              <label htmlFor="cat-global" className="text-sm">
                Global (visible to all users)
              </label>
            </div>
          )}
          <button
            type="submit"
            disabled={isCreating}
            className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {isCreating ? 'Creating...' : 'Create'}
          </button>
        </form>
      )}

      {/* Category List */}
      <div className="mt-6 space-y-2">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading...</p>
        ) : categories.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No categories yet. Create one to get started.
          </p>
        ) : (
          categories.map((cat) => (
            <div
              key={cat.id}
              className="flex items-center justify-between rounded-lg border border-border px-4 py-3"
            >
              <div className="flex items-center gap-3">
                <div
                  data-testid={`color-swatch-${cat.id}`}
                  className="h-5 w-5 rounded-full border border-border"
                  style={{ backgroundColor: cat.color ?? '#ccc' }}
                />
                {editingId === cat.id ? (
                  <div className="flex items-center gap-2">
                    <input
                      type="text"
                      value={editName}
                      onChange={(e) => setEditName(e.target.value)}
                      className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                      autoFocus
                    />
                    <input
                      type="color"
                      value={editColor}
                      onChange={(e) => setEditColor(e.target.value)}
                      className="h-8 w-10 cursor-pointer rounded border border-input"
                    />
                    <EmojiPickerPopover value={editIcon} onChange={setEditIcon} />
                    <button
                      onClick={handleSaveEdit}
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
                    {cat.icon && (
                      <span className="mr-1.5 text-base" aria-hidden="true">
                        {cat.icon}
                      </span>
                    )}
                    <span className="font-medium">{cat.name}</span>
                    <span
                      className={`ml-2 rounded-full px-2 py-0.5 text-[10px] font-medium ${
                        cat.is_global
                          ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300'
                          : 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                      }`}
                    >
                      {cat.is_global ? 'Global' : 'Personal'}
                    </span>
                  </div>
                )}
              </div>

              {editingId !== cat.id && (
                <div className="flex gap-1">
                  {isAdmin && (
                    <button
                      onClick={() => void handleToggleGlobal(cat)}
                      className="rounded px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20"
                    >
                      {cat.is_global ? 'Make Personal' : 'Make Global'}
                    </button>
                  )}
                  <button
                    onClick={() => startEditing(cat)}
                    className="rounded px-2 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                  >
                    Edit
                  </button>
                  <button
                    onClick={() => handleDelete(cat)}
                    className="rounded px-2 py-1 text-xs text-destructive hover:bg-destructive/10"
                  >
                    Delete
                  </button>
                </div>
              )}
            </div>
          ))
        )}
      </div>
    </div>
  );
}
