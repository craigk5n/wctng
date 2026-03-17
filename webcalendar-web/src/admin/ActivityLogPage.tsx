import { useCallback, useEffect, useState } from 'react';

interface LogEntry {
  id: number;
  entry_id: number;
  user: string;
  user_cal: string | null;
  action: string;
  action_code: string;
  timestamp: string;
  text: string;
}

const ACTION_COLORS: Record<string, string> = {
  create: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
  update: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
  approve: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
  reject: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
  notification: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
  reminder: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
};

export function ActivityLogPage() {
  const [entries, setEntries] = useState<LogEntry[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [userFilter, setUserFilter] = useState('');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [selectedEntry, setSelectedEntry] = useState<LogEntry | null>(null);

  const fetchLogs = useCallback(async () => {
    setLoading(true);
    const params = new URLSearchParams({ page: String(page), limit: '50' });
    if (userFilter) params.set('user', userFilter);
    if (startDate) params.set('start', startDate.replace(/-/g, ''));
    if (endDate) params.set('end', endDate.replace(/-/g, ''));

    const res = await fetch(`${getBaseUrl()}/admin/activity-log?${params.toString()}`, {
      headers: getHeaders(),
    });

    if (res.ok) {
      const body = await res.json();
      setEntries(body.data ?? []);
      setTotal(body.meta?.total ?? 0);
    }
    setLoading(false);
  }, [page, userFilter, startDate, endDate]);

  useEffect(() => {
    void fetchLogs();
  }, [fetchLogs]);

  const totalPages = Math.ceil(total / 50);

  return (
    <div>
      <h2 className="text-2xl font-bold">Activity Log</h2>

      {/* Filters */}
      <div className="mt-4 flex flex-wrap gap-3">
        <input
          type="text"
          placeholder="Filter by user..."
          value={userFilter}
          onChange={(e) => { setUserFilter(e.target.value); setPage(1); }}
          className="flex h-9 w-40 rounded-md border border-input bg-background px-3 text-sm"
        />
        <input
          type="date"
          value={startDate}
          onChange={(e) => { setStartDate(e.target.value); setPage(1); }}
          className="flex h-9 rounded-md border border-input bg-background px-3 text-sm"
        />
        <input
          type="date"
          value={endDate}
          onChange={(e) => { setEndDate(e.target.value); setPage(1); }}
          className="flex h-9 rounded-md border border-input bg-background px-3 text-sm"
        />
      </div>

      {/* Table */}
      <div className="mt-4 overflow-x-auto">
        {loading && <p className="text-sm text-muted-foreground">Loading...</p>}

        {!loading && entries.length === 0 && (
          <p className="text-sm text-muted-foreground">No activity log entries found.</p>
        )}

        {!loading && entries.length > 0 && (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                <th className="pb-2 pr-4">Timestamp</th>
                <th className="pb-2 pr-4">User</th>
                <th className="pb-2 pr-4">Action</th>
                <th className="pb-2">Details</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => (
                <tr
                  key={entry.id}
                  className="border-b border-border/50 hover:bg-accent/30 cursor-pointer"
                  onClick={() => setSelectedEntry(selectedEntry?.id === entry.id ? null : entry)}
                >
                  <td className="py-2 pr-4 text-xs text-muted-foreground whitespace-nowrap">
                    {new Date(entry.timestamp).toLocaleString()}
                  </td>
                  <td className="py-2 pr-4 font-medium">{entry.user}</td>
                  <td className="py-2 pr-4">
                    <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${ACTION_COLORS[entry.action] ?? 'bg-gray-100 text-gray-800'}`}>
                      {entry.action}
                    </span>
                  </td>
                  <td className="py-2 text-muted-foreground truncate max-w-xs">
                    {entry.text || `Event #${entry.entry_id}`}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {/* Detail panel */}
        {selectedEntry && (
          <div className="mt-3 rounded-lg border bg-muted/30 p-4 text-sm">
            <div className="grid grid-cols-2 gap-2">
              <div><span className="font-medium">ID:</span> {selectedEntry.id}</div>
              <div><span className="font-medium">Event ID:</span> {selectedEntry.entry_id}</div>
              <div><span className="font-medium">User:</span> {selectedEntry.user}</div>
              <div><span className="font-medium">Action:</span> {selectedEntry.action}</div>
              <div><span className="font-medium">Time:</span> {new Date(selectedEntry.timestamp).toLocaleString()}</div>
              {selectedEntry.user_cal && (
                <div><span className="font-medium">Calendar:</span> {selectedEntry.user_cal}</div>
              )}
            </div>
            {selectedEntry.text && (
              <div className="mt-2">
                <span className="font-medium">Text:</span>
                <p className="mt-1 whitespace-pre-wrap text-muted-foreground">{selectedEntry.text}</p>
              </div>
            )}
          </div>
        )}

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="mt-4 flex items-center gap-2">
            <button
              disabled={page <= 1}
              onClick={() => setPage(page - 1)}
              className="rounded border px-3 py-1 text-xs disabled:opacity-50"
            >
              Previous
            </button>
            <span className="text-xs text-muted-foreground">
              Page {page} of {totalPages} ({total} entries)
            </span>
            <button
              disabled={page >= totalPages}
              onClick={() => setPage(page + 1)}
              className="rounded border px-3 py-1 text-xs disabled:opacity-50"
            >
              Next
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

function getBaseUrl(): string {
  return import.meta.env.VITE_API_URL ?? '/api/v2';
}

function getHeaders(): Record<string, string> {
  const token = localStorage.getItem('wctng_token');
  const h: Record<string, string> = { 'Content-Type': 'application/json' };
  if (token) h['Authorization'] = `Bearer ${token}`;
  return h;
}
