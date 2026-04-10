import { useCallback, useEffect, useRef, useState } from 'react';
import { apiFetch } from '../api/client';

interface Category {
  id: number;
  name: string;
  color: string | null;
}

interface CategoryFilterPopoverProps {
  onChange: (activeCategoryIds: number[]) => void;
}

const STORAGE_KEY = 'wctng_category_filter';
/** Sentinel ID representing uncategorized events in the filter set */
export const UNCATEGORIZED_ID = -1;

export function CategoryFilterPopover({ onChange }: CategoryFilterPopoverProps) {
  const [categories, setCategories] = useState<Category[]>([]);
  const [activeIds, setActiveIds] = useState<Set<number>>(new Set());
  const [loaded, setLoaded] = useState(false);
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  // Load categories
  useEffect(() => {
    void (async () => {
      const { data } = await apiFetch<Category[]>('/categories');
      if (data) {
        setCategories(data);
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
          try {
            setActiveIds(new Set(JSON.parse(stored) as number[]));
          } catch {
            setActiveIds(new Set([UNCATEGORIZED_ID, ...data.map((c) => c.id)]));
          }
        } else {
          setActiveIds(new Set([UNCATEGORIZED_ID, ...data.map((c) => c.id)]));
        }
        setLoaded(true);
      }
    })();
  }, []);

  // Notify parent
  // eslint-disable-next-line react-hooks/exhaustive-deps -- onChange is stable via useCallback, excluding to prevent infinite loops
  useEffect(() => {
    if (loaded) onChange(Array.from(activeIds));
  }, [activeIds, loaded]);

  // Sync when the sidebar panel (or another component) changes localStorage
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

  // Close on outside click
  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

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

  // +1 for the uncategorized sentinel
  const totalOptions = categories.length + 1;
  const isFiltering = activeIds.size < totalOptions;

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(!open)}
        aria-label="Filter by category"
        className={`inline-flex h-10 items-center gap-1.5 rounded-md border px-3 text-sm font-medium transition-colors ${
          isFiltering ? 'border-primary bg-primary/10 text-primary' : 'border-input hover:bg-accent'
        }`}
      >
        <FilterIcon />
        {isFiltering ? `${activeIds.size}/${totalOptions}` : 'Filter'}
      </button>

      {open && (
        <div className="absolute right-0 top-full z-50 mt-1 w-56 rounded-md border bg-card shadow-lg">
          <div className="flex items-center justify-between border-b px-3 py-2">
            <span className="text-xs font-medium text-muted-foreground">Categories</span>
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
          <div className="max-h-64 overflow-y-auto py-1">
            <label className="mb-1 flex cursor-pointer items-center gap-2 border-b border-border/50 px-3 py-1.5 hover:bg-accent/50">
              <input
                type="checkbox"
                checked={activeIds.has(UNCATEGORIZED_ID)}
                onChange={() => toggle(UNCATEGORIZED_ID)}
                className="h-3.5 w-3.5 rounded border-input"
                aria-label="Uncategorized"
              />
              <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full border border-dashed border-muted-foreground/40" />
              <span className="truncate text-sm italic text-muted-foreground">Uncategorized</span>
            </label>
            {categories.map((cat) => (
              <label
                key={cat.id}
                className="flex cursor-pointer items-center gap-2 px-3 py-1.5 hover:bg-accent/50"
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
                <span className="truncate text-sm">{cat.name}</span>
              </label>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function FilterIcon() {
  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
    </svg>
  );
}
