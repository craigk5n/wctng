import { useCallback, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface PurgeResponse {
  count: number;
  dry_run: boolean;
  before_date: string;
  user_login: string | null;
}

interface PurgeRequest {
  before_date: string;
  user_login?: string;
  include_repeating: boolean;
  dry_run: boolean;
  confirm_count?: number;
}

type Phase = 'idle' | 'previewed' | 'running' | 'done';

function defaultBeforeDate(): string {
  // Default: one year ago, rounded to the 1st of the month.
  const d = new Date();
  d.setFullYear(d.getFullYear() - 1);
  d.setDate(1);
  return d.toISOString().slice(0, 10);
}

export function PurgePage() {
  const { toast } = useToast();
  const [beforeDate, setBeforeDate] = useState<string>(defaultBeforeDate);
  const [userLogin, setUserLogin] = useState<string>('');
  const [includeRepeating, setIncludeRepeating] = useState<boolean>(false);
  const [phase, setPhase] = useState<Phase>('idle');
  const [previewCount, setPreviewCount] = useState<number | null>(null);
  const [confirmText, setConfirmText] = useState<string>('');
  const [lastResult, setLastResult] = useState<PurgeResponse | null>(null);
  const [busy, setBusy] = useState(false);

  const reset = useCallback(() => {
    setPhase('idle');
    setPreviewCount(null);
    setConfirmText('');
    setLastResult(null);
  }, []);

  const buildBody = useCallback(
    (dryRun: boolean, confirmCount?: number): PurgeRequest => {
      const body: PurgeRequest = {
        before_date: beforeDate,
        include_repeating: includeRepeating,
        dry_run: dryRun,
      };
      const trimmed = userLogin.trim();
      if (trimmed !== '') {
        body.user_login = trimmed;
      }
      if (confirmCount !== undefined) {
        body.confirm_count = confirmCount;
      }
      return body;
    },
    [beforeDate, userLogin, includeRepeating],
  );

  const handlePreview = useCallback(async () => {
    if (beforeDate === '') {
      toast({ title: 'Please pick a cutoff date', variant: 'error' });
      return;
    }
    setBusy(true);
    const { data, error } = await apiFetch<PurgeResponse>('/admin/events/purge', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(buildBody(true)),
    });
    setBusy(false);

    if (error || !data) {
      toast({ title: error?.message ?? 'Preview failed', variant: 'error' });
      return;
    }
    setPreviewCount(data.count);
    setPhase('previewed');
    setLastResult(null);
    setConfirmText('');
  }, [beforeDate, buildBody, toast]);

  const handleRun = useCallback(async () => {
    if (previewCount === null) return;
    if (confirmText !== 'DELETE') return;

    setBusy(true);
    setPhase('running');
    const { data, error } = await apiFetch<PurgeResponse>('/admin/events/purge', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(buildBody(false, previewCount)),
    });
    setBusy(false);

    if (error || !data) {
      toast({ title: error?.message ?? 'Purge failed', variant: 'error' });
      setPhase('previewed');
      return;
    }
    setLastResult(data);
    setPhase('done');
    toast({ title: `Purged ${data.count} event${data.count === 1 ? '' : 's'}`, variant: 'success' });
  }, [previewCount, confirmText, buildBody, toast]);

  const filterChanged = phase === 'previewed' || phase === 'done';

  return (
    <div>
      <h2 className="text-2xl font-bold">Data Management — Event Purge</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Permanently delete events older than a cutoff date. Active recurring series are truncated
        (history preserved) when “Include recurring” is enabled.
      </p>

      <div className="mt-8 rounded-lg border border-destructive/30 p-4">
        <h3 className="text-lg font-semibold text-destructive">Purge Events</h3>
        <p className="mt-1 text-sm text-muted-foreground">
          This action cannot be undone. Always preview first to verify the count.
        </p>

        <div className="mt-4 max-w-md space-y-3">
          <div>
            <label htmlFor="purge-before-date" className="block text-sm font-medium">
              Delete events before
            </label>
            <input
              id="purge-before-date"
              type="date"
              value={beforeDate}
              onChange={(e) => {
                setBeforeDate(e.target.value);
                if (filterChanged) reset();
              }}
              className="mt-1 flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            />
          </div>

          <div>
            <label htmlFor="purge-user-login" className="block text-sm font-medium">
              Limit to user login (optional)
            </label>
            <input
              id="purge-user-login"
              type="text"
              value={userLogin}
              onChange={(e) => {
                setUserLogin(e.target.value);
                if (filterChanged) reset();
              }}
              placeholder="leave blank for all users"
              className="mt-1 flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            />
          </div>

          <div className="flex items-center gap-2">
            <input
              id="purge-include-repeating"
              type="checkbox"
              checked={includeRepeating}
              onChange={(e) => {
                setIncludeRepeating(e.target.checked);
                if (filterChanged) reset();
              }}
            />
            <label htmlFor="purge-include-repeating" className="text-sm">
              Include recurring series (truncates active series with an UNTIL at the cutoff)
            </label>
          </div>

          <button
            onClick={() => void handlePreview()}
            disabled={busy || beforeDate === ''}
            className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {busy && phase === 'idle' ? 'Previewing...' : 'Preview (dry run)'}
          </button>
        </div>

        {phase === 'previewed' && previewCount !== null && (
          <div className="mt-6 rounded-md border bg-muted/30 p-4">
            <p className="text-sm">
              <span className="font-semibold">{previewCount}</span>{' '}
              event{previewCount === 1 ? '' : 's'} will be affected.
            </p>
            {previewCount === 0 ? (
              <p className="mt-2 text-sm text-muted-foreground">
                Nothing to purge with these filters.
              </p>
            ) : (
              <div className="mt-3 space-y-2">
                <label htmlFor="purge-confirm" className="block text-sm font-medium">
                  Type <span className="font-mono font-bold">DELETE</span> to confirm
                </label>
                <input
                  id="purge-confirm"
                  type="text"
                  value={confirmText}
                  onChange={(e) => setConfirmText(e.target.value)}
                  placeholder="DELETE"
                  className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                />
                <button
                  onClick={() => void handleRun()}
                  disabled={busy || confirmText !== 'DELETE'}
                  className="inline-flex h-9 items-center rounded-md bg-destructive px-4 text-sm font-medium text-destructive-foreground hover:bg-destructive/90 disabled:opacity-50"
                >
                  {busy ? 'Purging...' : `Purge ${previewCount} events`}
                </button>
              </div>
            )}
          </div>
        )}

        {phase === 'done' && lastResult !== null && (
          <div className="mt-6 rounded-md border border-green-500/30 bg-green-500/5 p-4">
            <p className="text-sm font-semibold">Purge complete</p>
            <p className="mt-1 text-sm text-muted-foreground">
              {lastResult.count} event{lastResult.count === 1 ? '' : 's'} affected, cutoff{' '}
              {lastResult.before_date}
              {lastResult.user_login ? `, user ${lastResult.user_login}` : ''}.
            </p>
            <button
              onClick={reset}
              className="mt-3 inline-flex h-9 items-center rounded-md border px-4 text-sm font-medium hover:bg-accent"
            >
              Run another purge
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
