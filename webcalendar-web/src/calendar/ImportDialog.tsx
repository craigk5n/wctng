import { useCallback, useRef, useState } from 'react';
import { TOKEN_STORAGE_KEY } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface ImportDialogProps {
  open: boolean;
  onClose: () => void;
  onImported: () => void;
}

export function ImportDialog({ open, onClose, onImported }: ImportDialogProps) {
  const [file, setFile] = useState<File | null>(null);
  const [isImporting, setIsImporting] = useState(false);
  const [result, setResult] = useState<{ imported: number; skipped: number } | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const { toast } = useToast();

  const handleImport = useCallback(async () => {
    if (!file) return;
    setIsImporting(true);

    const baseUrl = import.meta.env.VITE_API_URL ?? '/api/v2';
    const token = localStorage.getItem(TOKEN_STORAGE_KEY);

    const formData = new FormData();
    formData.append('file', file);

    try {
      const res = await fetch(`${baseUrl}/import`, {
        method: 'POST',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
        body: formData,
      });

      const body = await res.json();

      if (res.ok && body?.data) {
        setResult({ imported: body.data.imported, skipped: body.data.skipped });
        toast({
          title: `Imported ${body.data.imported} events (${body.data.skipped} skipped)`,
          variant: 'success',
        });
        onImported();
      } else {
        toast({ title: body?.error?.message ?? 'Import failed', variant: 'error' });
      }
    } catch {
      toast({ title: 'Import failed', variant: 'error' });
    } finally {
      setIsImporting(false);
    }
  }, [file, toast, onImported]);

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
    >
      <div className="w-full max-w-md rounded-lg bg-card p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-semibold">Import Calendar</h2>

        {result ? (
          <div className="mt-4 space-y-3">
            <div className="rounded-md bg-green-50 p-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-300">
              Imported {result.imported} events, {result.skipped} skipped.
            </div>
            <button
              onClick={() => { setResult(null); setFile(null); onClose(); }}
              className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
            >
              Done
            </button>
          </div>
        ) : (
          <div className="mt-4 space-y-4">
            <div
              className={`flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 transition-colors cursor-pointer ${
                isDragging ? 'border-primary bg-primary/5' : 'border-border'
              }`}
              role="button"
              tabIndex={0}
              aria-label="Drop ICS file here or click to browse"
              onDragOver={(e) => { e.preventDefault(); setIsDragging(true); }}
              onDragLeave={() => setIsDragging(false)}
              onDrop={(e) => {
                e.preventDefault();
                setIsDragging(false);
                const dropped = e.dataTransfer.files[0];
                if (dropped) setFile(dropped);
              }}
              onClick={() => inputRef.current?.click()}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inputRef.current?.click(); } }}
            >
              <input
                ref={inputRef}
                type="file"
                accept=".ics,text/calendar"
                className="hidden"
                onChange={(e) => { if (e.target.files?.[0]) setFile(e.target.files[0]); }}
              />
              {file ? (
                <div className="text-center">
                  <p className="font-medium">{file.name}</p>
                  <p className="text-xs text-muted-foreground">{(file.size / 1024).toFixed(1)} KB</p>
                </div>
              ) : (
                <div className="text-center">
                  <p className="text-sm text-muted-foreground">Drop an ICS file here or click to select</p>
                </div>
              )}
            </div>

            <div className="flex justify-end gap-2">
              <button
                onClick={onClose}
                className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent"
              >
                Cancel
              </button>
              <button
                onClick={handleImport}
                disabled={!file || isImporting}
                className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
              >
                {isImporting ? 'Importing...' : 'Import'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
