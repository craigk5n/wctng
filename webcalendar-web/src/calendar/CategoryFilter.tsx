import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface Category {
  id: number;
  name: string;
  color: string | null;
  is_global: boolean;
  owner: string | null;
}

interface CategoryFilterProps {
  onChange: (activeCategoryIds: number[]) => void;
}

const STORAGE_KEY = 'wctng_category_filter';

export function CategoryFilter({ onChange }: CategoryFilterProps) {
  const [categories, setCategories] = useState<Category[]>([]);
  const [activeIds, setActiveIds] = useState<Set<number>>(new Set());
  const [loaded, setLoaded] = useState(false);

  // Load categories from API
  useEffect(() => {
    void (async () => {
      const { data } = await apiFetch<Category[]>('/categories');
      if (data) {
        setCategories(data);

        // Load persisted filter or default to all active
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
          try {
            const parsed = JSON.parse(stored) as number[];
            setActiveIds(new Set(parsed));
          } catch {
            setActiveIds(new Set(data.map((c) => c.id)));
          }
        } else {
          setActiveIds(new Set(data.map((c) => c.id)));
        }
        setLoaded(true);
      }
    })();
  }, []);

  // Notify parent when filter changes
  useEffect(() => {
    if (loaded) {
      onChange(Array.from(activeIds));
    }
  }, [activeIds, loaded, onChange]);

  // Persist to localStorage
  const persist = useCallback((ids: Set<number>) => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(ids)));
  }, []);

  const toggle = useCallback((id: number) => {
    setActiveIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      persist(next);
      return next;
    });
  }, [persist]);

  const selectAll = useCallback(() => {
    const all = new Set(categories.map((c) => c.id));
    setActiveIds(all);
    persist(all);
  }, [categories, persist]);

  const selectNone = useCallback(() => {
    const none = new Set<number>();
    setActiveIds(none);
    persist(none);
  }, [persist]);

  if (categories.length === 0) return null;

  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between">
        <span className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/60">Categories</span>
        <div className="flex gap-1">
          <button
            onClick={selectAll}
            className="rounded px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-accent"
          >
            All
          </button>
          <button
            onClick={selectNone}
            className="rounded px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-accent"
          >
            None
          </button>
        </div>
      </div>

      {categories.map((cat) => (
        <label key={cat.id} className="flex items-center gap-2 cursor-pointer rounded px-1 py-0.5 hover:bg-accent/30">
          <input
            type="checkbox"
            checked={activeIds.has(cat.id)}
            onChange={() => toggle(cat.id)}
            className="h-3.5 w-3.5 rounded border-input"
            aria-label={cat.name}
          />
          <span
            className="h-2.5 w-2.5 rounded-full flex-shrink-0"
            style={{ backgroundColor: cat.color ?? '#888' }}
            data-testid="category-color-dot"
          />
          <span className="truncate text-xs">{cat.name}</span>
        </label>
      ))}
    </div>
  );
}
