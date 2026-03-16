import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiFetch } from '../../api/client';

interface SearchResult {
  id: number;
  title: string;
  start_date: string;
  type: string;
  all_day: boolean;
  description?: string;
}

const TYPE_ICONS: Record<string, string> = {
  E: '📅',
  M: '🔁',
  T: '✅',
  J: '📓',
  N: '🔁',
  O: '🔁',
};

function formatDate(yyyymmdd: string): string {
  return `${yyyymmdd.slice(0, 4)}-${yyyymmdd.slice(4, 6)}-${yyyymmdd.slice(6, 8)}`;
}

export function SearchBar() {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResult[]>([]);
  const [isOpen, setIsOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [selectedIndex, setSelectedIndex] = useState(-1);
  const inputRef = useRef<HTMLInputElement>(null);
  const debounceRef = useRef<ReturnType<typeof setTimeout>>();
  const navigate = useNavigate();

  const doSearch = useCallback(async (q: string) => {
    if (q.length < 2) {
      setResults([]);
      setIsOpen(false);
      return;
    }

    setIsLoading(true);
    const { data } = await apiFetch<SearchResult[]>(`/search?q=${encodeURIComponent(q)}`);
    setResults(data ?? []);
    setIsOpen(true);
    setSelectedIndex(-1);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);

    if (query.length < 2) {
      setResults([]);
      setIsOpen(false);
      return;
    }

    debounceRef.current = setTimeout(() => {
      void doSearch(query);
    }, 300);

    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [query, doSearch]);

  const handleSelect = useCallback(
    (result: SearchResult) => {
      setIsOpen(false);
      setQuery('');
      // Navigate to calendar on the event's date
      const date = formatDate(result.start_date);
      navigate(`/?view=timeGridDay&date=${date}`);
    },
    [navigate],
  );

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (!isOpen) return;

      if (e.key === 'Escape') {
        setIsOpen(false);
        return;
      }

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setSelectedIndex((i) => Math.min(i + 1, results.length - 1));
        return;
      }

      if (e.key === 'ArrowUp') {
        e.preventDefault();
        setSelectedIndex((i) => Math.max(i - 1, 0));
        return;
      }

      if (e.key === 'Enter' && selectedIndex >= 0 && selectedIndex < results.length) {
        e.preventDefault();
        handleSelect(results[selectedIndex]);
      }
    },
    [isOpen, results, selectedIndex, handleSelect],
  );

  return (
    <div className="relative">
      <input
        ref={inputRef}
        type="text"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        onKeyDown={handleKeyDown}
        onFocus={() => { if (results.length > 0) setIsOpen(true); }}
        placeholder="Search events..."
        className="h-9 w-48 rounded-md border border-input bg-background px-3 text-sm placeholder:text-muted-foreground focus:w-64 focus:outline-none focus:ring-2 focus:ring-ring transition-all md:w-56 md:focus:w-72"
      />

      {isLoading && (
        <div className="absolute right-2 top-2 text-xs text-muted-foreground">...</div>
      )}

      {isOpen && (
        <div className="absolute left-0 top-10 z-50 w-80 rounded-md border border-border bg-card shadow-lg">
          {results.length === 0 ? (
            <div className="px-4 py-3 text-sm text-muted-foreground">No results found</div>
          ) : (
            <ul className="max-h-64 overflow-y-auto py-1">
              {results.map((result, index) => (
                <li key={result.id}>
                  <button
                    type="button"
                    onClick={() => handleSelect(result)}
                    className={`flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-accent ${
                      index === selectedIndex ? 'bg-accent' : ''
                    }`}
                  >
                    <span className="mt-0.5 text-base">{TYPE_ICONS[result.type] ?? '📅'}</span>
                    <div className="min-w-0 flex-1">
                      <div className="font-medium">{result.title}</div>
                      <div className="text-xs text-muted-foreground">
                        {formatDate(result.start_date)}
                      </div>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
