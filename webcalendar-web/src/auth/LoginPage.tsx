import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { type AuthUser, useAuth } from './auth-context';
import { TOKEN_STORAGE_KEY } from '../api/client';
import { useTenant } from '../hooks/useTenant';

interface OAuthProviderInfo {
  id: number;
  name: string;
  type: string;
}

export function LoginPage() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [oauthProviders, setOauthProviders] = useState<OAuthProviderInfo[]>([]);
  const { tenant } = useTenant();
  const { login } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const baseUrl = import.meta.env.VITE_API_URL ?? '/api/v2';

  // Fetch available OAuth providers
  useEffect(() => {
    void fetch(`${baseUrl}/auth/oauth/providers`)
      .then((res) => (res.ok ? res.json() : null))
      .then((body) => {
        if (body?.data && Array.isArray(body.data)) {
          setOauthProviders(body.data as OAuthProviderInfo[]);
        }
      })
      .catch(() => {});
  }, [baseUrl]);

  // Handle OAuth callback (code in URL params)
  useEffect(() => {
    const code = searchParams.get('code');
    const providerId = searchParams.get('provider');
    const codeVerifier = sessionStorage.getItem('oauth_code_verifier');

    if (code && providerId && codeVerifier) {
      sessionStorage.removeItem('oauth_code_verifier');
      sessionStorage.removeItem('oauth_state');

      void (async () => {
        setIsLoading(true);
        try {
          const res = await fetch(`${baseUrl}/auth/oauth/${providerId}/callback`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code, code_verifier: codeVerifier }),
          });

          const body = await res.json();
          if (res.ok && body.data?.token) {
            localStorage.setItem(TOKEN_STORAGE_KEY, body.data.token);
            login(body.data.token, body.data.user);
            navigate('/', { replace: true });
          } else {
            setError(body.error?.message ?? 'OAuth login failed');
          }
        } catch {
          setError('OAuth callback failed');
        } finally {
          setIsLoading(false);
        }
      })();
    }
  }, [searchParams, baseUrl, login, navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setIsLoading(true);

    try {
      const response = await fetch(`${baseUrl}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password }),
      });

      const body = await response.json() as {
        data: { token: string; user: AuthUser; expires_at: string } | null;
        error: { code: number; message: string } | null;
      };

      if (!response.ok || !body.data?.token) {
        setError(body.error?.message ?? 'Invalid username or password');
        return;
      }

      localStorage.setItem(TOKEN_STORAGE_KEY, body.data.token);
      login(body.data.token, body.data.user);
      navigate('/', { replace: true });
    } catch (err) {
      console.error('Login error:', err);
      setError('An error occurred. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  const handleOAuthLogin = async (provider: OAuthProviderInfo) => {
    try {
      const res = await fetch(`${baseUrl}/auth/oauth/${provider.id}/redirect`);
      const body = await res.json();

      if (res.ok && body.data?.auth_url) {
        // Store PKCE verifier and state in sessionStorage
        sessionStorage.setItem('oauth_code_verifier', body.data.code_verifier);
        sessionStorage.setItem('oauth_state', body.data.state);

        // Redirect to provider
        window.location.href = body.data.auth_url;
      } else {
        setError('Failed to start OAuth flow');
      }
    } catch {
      setError('Failed to connect to OAuth provider');
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-background">
      <div className="w-full max-w-sm space-y-6 p-8">
        <div className="text-center">
          <h1 className="text-2xl font-bold tracking-tight">{tenant ? tenant.name : 'WebCalendar'}</h1>
          <p className="mt-1 text-sm text-muted-foreground">Sign in to your account</p>
        </div>

        {/* OAuth provider buttons */}
        {oauthProviders.length > 0 && (
          <div className="space-y-2">
            {oauthProviders.map((provider) => (
              <button
                key={provider.id}
                type="button"
                onClick={() => void handleOAuthLogin(provider)}
                className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md border border-input bg-background text-sm font-medium hover:bg-accent"
              >
                {provider.type === 'oidc' ? '🔐' : '🔑'} Sign in with {provider.name}
              </button>
            ))}
            <div className="relative my-4">
              <div className="absolute inset-0 flex items-center"><div className="w-full border-t border-border" /></div>
              <div className="relative flex justify-center text-xs"><span className="bg-background px-2 text-muted-foreground">or</span></div>
            </div>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          {error && (
            <div role="alert" className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
              {error}
            </div>
          )}

          <div className="space-y-2">
            <label htmlFor="username" className="text-sm font-medium">Username</label>
            <input
              id="username"
              type="text"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              required
              autoComplete="username"
              autoFocus
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              placeholder="Enter your username"
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="password" className="text-sm font-medium">Password</label>
            <input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              autoComplete="current-password"
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              placeholder="Enter your password"
            />
          </div>

          <button
            type="submit"
            disabled={isLoading}
            className="inline-flex h-10 w-full items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground ring-offset-background transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
          >
            {isLoading ? 'Signing in...' : 'Sign in'}
          </button>
        </form>
      </div>
    </div>
  );
}
