import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface ShareToken {
  id: number;
  token: string;
  owner_login: string;
  expires_at: string | null;
  created_at: string;
}

export function ShareSettings() {
  const [tokens, setTokens] = useState<ShareToken[]>([]);
  const [loading, setLoading] = useState(true);
  const [copiedToken, setCopiedToken] = useState<string | null>(null);

  const fetchTokens = useCallback(async () => {
    const result = await apiFetch<ShareToken[]>('/calendars/share');
    if (result.data) {
      setTokens(result.data);
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    void fetchTokens();
  }, [fetchTokens]);

  const handleCreate = async () => {
    const result = await apiFetch<ShareToken>('/calendars/share', {
      method: 'POST',
      body: JSON.stringify({}),
    });
    if (result.data) {
      void fetchTokens();
    }
  };

  const handleDelete = async (token: string) => {
    await apiFetch(`/calendars/share/${token}`, { method: 'DELETE' });
    void fetchTokens();
  };

  const handleCopy = async (token: string) => {
    const url = `${window.location.origin}/public/embed/${token}`;
    try {
      await navigator.clipboard.writeText(url);
      setCopiedToken(token);
      setTimeout(() => setCopiedToken(null), 2000);
    } catch {
      // Fallback: select text
    }
  };

  const getShareUrl = (token: string) => `${window.location.origin}/public/embed/${token}`;
  const getEmbedSnippet = (token: string) =>
    `<iframe src="${getShareUrl(token)}" width="800" height="600" frameborder="0"></iframe>`;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-semibold text-foreground">Share Calendar</h2>
        <button
          className="rounded bg-primary px-4 py-2 text-sm text-primary-foreground hover:bg-primary/90"
          onClick={handleCreate}
        >
          Create Share Link
        </button>
      </div>

      <p className="text-sm text-muted-foreground">
        Share your public calendar events via a link or embed it on your website.
      </p>

      {loading && <p className="text-muted-foreground">Loading...</p>}

      {!loading && tokens.length === 0 && (
        <p className="text-sm text-muted-foreground">No share links yet. Create one to get started.</p>
      )}

      <div className="space-y-4">
        {tokens.map((token) => (
          <div
            key={token.id}
            className="rounded-lg border bg-card p-4 space-y-3"
          >
            <div className="flex items-center justify-between">
              <code className="text-sm font-mono text-foreground">{token.token}</code>
              <div className="flex gap-2">
                <button
                  className="rounded border px-3 py-1 text-xs hover:bg-accent"
                  onClick={() => handleCopy(token.token)}
                >
                  {copiedToken === token.token ? 'Copied!' : 'Copy URL'}
                </button>
                <button
                  className="rounded border border-destructive px-3 py-1 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground"
                  onClick={() => handleDelete(token.token)}
                >
                  Revoke
                </button>
              </div>
            </div>

            <div className="text-xs text-muted-foreground">
              Created: {new Date(token.created_at).toLocaleDateString()}
              {token.expires_at && (
                <span className="ml-3">
                  Expires: {new Date(token.expires_at).toLocaleDateString()}
                </span>
              )}
            </div>

            <details className="text-xs">
              <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
                Embed Code (iframe)
              </summary>
              <pre className="mt-2 overflow-x-auto rounded bg-muted p-2 text-xs">
                {getEmbedSnippet(token.token)}
              </pre>
            </details>
          </div>
        ))}
      </div>
    </div>
  );
}
