import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface BackupInfo {
  filename: string;
  size_bytes: number;
  created_at: string;
  download_url?: string;
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function BackupPage() {
  const [backups, setBackups] = useState<BackupInfo[]>([]);
  const [creating, setCreating] = useState(false);
  const [restoreFile, setRestoreFile] = useState<File | null>(null);
  const [confirmText, setConfirmText] = useState('');
  const [restoring, setRestoring] = useState(false);
  const { toast } = useToast();

  const loadBackups = useCallback(async () => {
    const { data } = await apiFetch<BackupInfo[]>('/admin/backup');
    if (data) setBackups(data);
  }, []);

  useEffect(() => {
    void loadBackups();
  }, [loadBackups]);

  const handleCreate = useCallback(async () => {
    setCreating(true);
    const { data, error } = await apiFetch<BackupInfo>('/admin/backup', { method: 'POST' });
    setCreating(false);

    if (data && !error) {
      toast({ title: `Backup created: ${data.filename}`, variant: 'success' });
      void loadBackups();
    } else {
      toast({ title: 'Backup failed', variant: 'error' });
    }
  }, [toast, loadBackups]);

  const handleRestore = useCallback(async () => {
    if (!restoreFile || confirmText !== 'RESTORE') return;

    setRestoring(true);
    const formData = new FormData();
    formData.append('file', restoreFile);
    formData.append('confirm', 'RESTORE');

    try {
      const token = localStorage.getItem('jwt_token');
      const response = await fetch('/api/v2/admin/restore', {
        method: 'POST',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
        body: formData,
      });
      const result = await response.json();

      if (response.ok) {
        toast({ title: 'Database restored successfully', variant: 'success' });
        setRestoreFile(null);
        setConfirmText('');
      } else {
        toast({ title: result?.error?.message ?? 'Restore failed', variant: 'error' });
      }
    } catch {
      toast({ title: 'Restore failed', variant: 'error' });
    }

    setRestoring(false);
  }, [restoreFile, confirmText, toast]);

  return (
    <div>
      <h2 className="text-2xl font-bold">Database Backup & Restore</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Create database backups and restore from previous backups.
      </p>

      {/* Create backup */}
      <div className="mt-6">
        <h3 className="text-lg font-semibold">Create Backup</h3>
        <button
          onClick={() => void handleCreate()}
          disabled={creating}
          className="mt-2 inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
        >
          {creating ? 'Creating...' : 'Create Backup'}
        </button>
      </div>

      {/* Backup list */}
      <div className="mt-6">
        <h3 className="text-lg font-semibold">Existing Backups</h3>
        {backups.length === 0 ? (
          <p className="mt-2 text-sm text-muted-foreground">No backups found.</p>
        ) : (
          <div className="mt-2 space-y-2">
            {backups.map((b) => (
              <div key={b.filename} className="flex items-center justify-between rounded-md border p-3 text-sm">
                <div>
                  <span className="font-mono font-medium">{b.filename}</span>
                  <span className="ml-2 text-muted-foreground">{formatBytes(b.size_bytes)}</span>
                  <span className="ml-2 text-xs text-muted-foreground">
                    {new Date(b.created_at).toLocaleString()}
                  </span>
                </div>
                <a
                  href={`/api/v2/admin/backup/${b.filename}`}
                  className="text-sm text-blue-600 hover:underline dark:text-blue-400"
                  download
                >
                  Download
                </a>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Restore */}
      <div className="mt-8 rounded-lg border border-destructive/30 p-4">
        <h3 className="text-lg font-semibold text-destructive">Restore from Backup</h3>
        <p className="mt-1 text-sm text-muted-foreground">
          This will replace the entire database. This action cannot be undone.
        </p>

        <div className="mt-4 space-y-3 max-w-md">
          <div>
            <label htmlFor="restore-file" className="block text-sm font-medium">Backup File</label>
            <input
              id="restore-file"
              type="file"
              accept=".sql,.db"
              onChange={(e) => setRestoreFile(e.target.files?.[0] ?? null)}
              className="mt-1 block w-full text-sm"
            />
          </div>

          <div>
            <label htmlFor="restore-confirm" className="block text-sm font-medium">
              Type RESTORE to confirm
            </label>
            <input
              id="restore-confirm"
              type="text"
              value={confirmText}
              onChange={(e) => setConfirmText(e.target.value)}
              placeholder="RESTORE"
              className="mt-1 flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            />
          </div>

          <button
            onClick={() => void handleRestore()}
            disabled={restoring || !restoreFile || confirmText !== 'RESTORE'}
            className="inline-flex h-9 items-center rounded-md bg-destructive px-4 text-sm font-medium text-destructive-foreground hover:bg-destructive/90 disabled:opacity-50"
          >
            {restoring ? 'Restoring...' : 'Restore Database'}
          </button>
        </div>
      </div>
    </div>
  );
}
