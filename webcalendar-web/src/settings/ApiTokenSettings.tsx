import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useAuth } from '../auth/auth-context';
import { useToast } from '../components/toast/ToastProvider';

interface Pref {
  key: string;
  value: string;
}

export function ApiTokenSettings() {
  const { user } = useAuth();
  const { toast } = useToast();
  const login = user?.login ?? '';

  const [hasToken, setHasToken] = useState(false);
  const [newToken, setNewToken] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);

  const fetchTokenStatus = useCallback(async () => {
    if (!login) return;
    const { data } = await apiFetch<Pref[]>(`/users/${login}/preferences`);
    const tokenPref = data?.find((p) => p.key === 'api_token');
    setHasToken(!!tokenPref && tokenPref.value !== '');
    setLoading(false);
  }, [login]);

  useEffect(() => {
    void fetchTokenStatus();
  }, [fetchTokenStatus]);

  const handleGenerate = async () => {
    if (!login) return;
    setGenerating(true);

    // Generate a random token
    const token = Array.from(crypto.getRandomValues(new Uint8Array(32)))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');

    const { error } = await apiFetch(`/users/${login}/preferences`, {
      method: 'PUT',
      body: JSON.stringify({ api_token: token }),
    });

    setGenerating(false);
    if (!error) {
      setNewToken(token);
      setHasToken(true);
      toast({ title: 'API token generated', variant: 'success' });
    } else {
      toast({ title: 'Failed to generate token', variant: 'error' });
    }
  };

  const handleRevoke = async () => {
    if (!login) return;
    const { error } = await apiFetch(`/users/${login}/preferences`, {
      method: 'PUT',
      body: JSON.stringify({ api_token: '' }),
    });
    if (error) {
      toast({ title: error.message ?? 'Failed to revoke token', variant: 'error' });
      return;
    }
    setHasToken(false);
    setNewToken(null);
    toast({ title: 'API token revoked', variant: 'success' });
  };

  const baseUrl = window.location.origin;

  return (
    <div className="space-y-6">
      <h2 className="text-xl font-semibold">API Tokens</h2>
      <p className="text-sm text-muted-foreground">
        Generate API tokens for integrating with the MCP server and other tools.
      </p>

      {loading && <p className="text-sm text-muted-foreground">Loading...</p>}

      {!loading && (
        <div className="max-w-lg space-y-4">
          {/* Token status */}
          <div className="rounded-lg border p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium">
                  {hasToken ? (
                    <span className="text-green-600">Active token</span>
                  ) : (
                    <span className="text-muted-foreground">No active token</span>
                  )}
                </p>
              </div>
              <div className="flex gap-2">
                {hasToken && (
                  <button
                    onClick={handleRevoke}
                    className="rounded border border-destructive px-3 py-1 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground"
                  >
                    Revoke
                  </button>
                )}
                <button
                  onClick={handleGenerate}
                  disabled={generating}
                  className="rounded bg-primary px-3 py-1 text-xs text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                >
                  {generating ? 'Generating...' : hasToken ? 'Regenerate' : 'Generate Token'}
                </button>
              </div>
            </div>

            {/* Show newly generated token */}
            {newToken && (
              <div className="mt-3 rounded bg-muted p-3">
                <p className="text-xs font-medium text-destructive">
                  Copy this token now — it won't be shown again!
                </p>
                <code className="mt-1 block break-all font-mono text-xs">{newToken}</code>
                <button
                  onClick={() => {
                    void navigator.clipboard.writeText(newToken);
                    toast({ title: 'Token copied', variant: 'success' });
                  }}
                  className="mt-2 rounded border px-3 py-1 text-xs hover:bg-accent"
                >
                  Copy to Clipboard
                </button>
              </div>
            )}
          </div>

          {/* MCP connection instructions */}
          <div className="space-y-3 rounded-lg border bg-muted/30 p-4">
            <h3 className="text-sm font-medium">MCP Connection Instructions</h3>
            <div className="space-y-2 text-xs text-muted-foreground">
              <p>
                <strong>Endpoint:</strong> <code>{baseUrl}/api/v2/mcp</code>
              </p>
              <p>
                <strong>Method:</strong> POST
              </p>
              <p>
                <strong>Authentication:</strong> Include header <code>X-API-Token: YOUR_TOKEN</code>
              </p>
              <p>
                <strong>Protocol:</strong> JSON-RPC 2.0
              </p>
            </div>
            <details className="text-xs">
              <summary className="cursor-pointer font-medium text-muted-foreground hover:text-foreground">
                Example: List events
              </summary>
              <pre className="mt-2 overflow-x-auto rounded bg-muted p-2 text-xs">
                {`curl -X POST ${baseUrl}/api/v2/mcp \\
  -H "Content-Type: application/json" \\
  -H "X-API-Token: YOUR_TOKEN" \\
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
      "name": "list_events",
      "arguments": {
        "start_date": "20260601",
        "end_date": "20260630"
      }
    },
    "id": 1
  }'`}
              </pre>
            </details>
            <details className="text-xs">
              <summary className="cursor-pointer font-medium text-muted-foreground hover:text-foreground">
                Available tools
              </summary>
              <ul className="mt-2 space-y-1 text-muted-foreground">
                <li>
                  <code>list_events</code> — List events in a date range
                </li>
                <li>
                  <code>get_event</code> — Get event details by ID
                </li>
                <li>
                  <code>create_event</code> — Create a new event
                </li>
                <li>
                  <code>update_event</code> — Update event fields
                </li>
                <li>
                  <code>delete_event</code> — Delete an event
                </li>
                <li>
                  <code>search_events</code> — Search by keyword
                </li>
                <li>
                  <code>get_availability</code> — Check free/busy
                </li>
              </ul>
            </details>
          </div>
        </div>
      )}
    </div>
  );
}
