import { useCallback, useState } from 'react';
import { TOKEN_STORAGE_KEY } from '../api/client';

export function ExportButton() {
  const [isExporting, setIsExporting] = useState(false);

  const handleExport = useCallback(async () => {
    setIsExporting(true);

    // Export 1 year back to 1 year forward
    const now = new Date();
    const start = new Date(now.getFullYear() - 1, 0, 1);
    const end = new Date(now.getFullYear() + 1, 11, 31);
    const startStr = formatYYYYMMDD(start);
    const endStr = formatYYYYMMDD(end);

    const baseUrl = import.meta.env.VITE_API_URL ?? '/api/v2';
    const token = localStorage.getItem(TOKEN_STORAGE_KEY);
    const headers: Record<string, string> = {};
    if (token) headers['Authorization'] = `Bearer ${token}`;

    try {
      const res = await fetch(`${baseUrl}/export?format=ics&start=${startStr}&end=${endStr}`, { headers });
      if (res.ok) {
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `webcalendar-${startStr}-to-${endStr}.ics`;
        a.click();
        URL.revokeObjectURL(url);
      }
    } finally {
      setIsExporting(false);
    }
  }, []);

  return (
    <button
      onClick={handleExport}
      disabled={isExporting}
      className="inline-flex h-10 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent disabled:opacity-50"
      title="Export calendar as ICS"
    >
      {isExporting ? '...' : 'Export'}
    </button>
  );
}

function formatYYYYMMDD(d: Date): string {
  return `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
}
