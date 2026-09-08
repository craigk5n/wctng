import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { UNCATEGORIZED_ID } from './CategoryFilterPopover';

interface Category {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
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

        const allIds = [UNCATEGORIZED_ID, ...data.map((c) => c.id)];
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
          try {
            setActiveIds(new Set(JSON.parse(stored) as number[]));
          } catch {
            setActiveIds(new Set(allIds));
          }
        } else {
          setActiveIds(new Set(allIds));
        }
        setLoaded(true);
      }
    })();
  }, []);

  // Sync when the popover (or another component) changes localStorage
  useEffect(() => {
    const handler = () => {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        try {
          setActiveIds(new Set(JSON.parse(raw) as number[]));
        } catch {
          // ignore
        }
      }
    };
    window.addEventListener('category-filter-change', handler);
    return () => window.removeEventListener('category-filter-change', handler);
  }, []);

  // Notify parent when filter changes
  useEffect(() => {
    if (loaded) onChange(Array.from(activeIds));
    // onChange excluded to prevent infinite loops: parents pass an inline
    // callback, so including it would re-run this effect every render.
    // The disable has to sit on the dependency-array line — that is where
    // exhaustive-deps reports, so above the useEffect it had no effect.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeIds, loaded]);

  const persist = useCallback((ids: Set<number>) => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(ids)));
    window.dispatchEvent(new CustomEvent('category-filter-change'));
  }, []);

  const toggle = useCallback(
    (id: number) => {
      setActiveIds((prev) => {
        const next = new Set(prev);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        persist(next);
        return next;
      });
    },
    [persist],
  );

  const selectAll = useCallback(() => {
    const all = new Set([UNCATEGORIZED_ID, ...categories.map((c) => c.id)]);
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
    <div className="space-y-1 p-3">
      <div className="flex items-center justify-between">
        <span className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/60">
          Categories
        </span>
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

      <label className="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 hover:bg-accent/30">
        <input
          type="checkbox"
          checked={activeIds.has(UNCATEGORIZED_ID)}
          onChange={() => toggle(UNCATEGORIZED_ID)}
          className="h-3.5 w-3.5 rounded border-input"
          aria-label="Uncategorized"
        />
        <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full border border-dashed border-muted-foreground/40" />
        <span className="truncate text-xs italic text-muted-foreground">Uncategorized</span>
      </label>

      {categories.map((cat) => (
        <label
          key={cat.id}
          className="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 hover:bg-accent/30"
        >
          <input
            type="checkbox"
            checked={activeIds.has(cat.id)}
            onChange={() => toggle(cat.id)}
            className="h-3.5 w-3.5 rounded border-input"
            aria-label={cat.name}
          />
          <span
            className="h-2.5 w-2.5 flex-shrink-0 rounded-full"
            style={{ backgroundColor: cat.color ?? '#888' }}
            data-testid="category-color-dot"
          />
          {cat.icon && (
            <span className="text-xs leading-none" aria-hidden="true">
              {cat.icon}
            </span>
          )}
          <span className="truncate text-xs">{cat.name}</span>
        </label>
      ))}
    </div>
  );
}
