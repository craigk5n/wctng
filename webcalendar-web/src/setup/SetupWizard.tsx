import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

type Step = 'database' | 'admin' | 'settings' | 'installing' | 'done';

export function SetupWizard() {
  const [step, setStep] = useState<Step>('database');
  const [dbStatus, setDbStatus] = useState<'checking' | 'ok' | 'error'>('checking');
  const [username, setUsername] = useState('admin');
  const [password, setPassword] = useState('');
  const [email, setEmail] = useState('');
  const [timezone, setTimezone] = useState(Intl.DateTimeFormat().resolvedOptions().timeZone);
  const [siteName, setSiteName] = useState('WebCalendar');
  const [error, setError] = useState('');
  const navigate = useNavigate();

  const baseUrl = import.meta.env.VITE_API_URL ?? '/api/v2';

  // Step 1: Check database
  const checkDatabase = async () => {
    setDbStatus('checking');
    try {
      const res = await fetch(`${baseUrl}/health`);
      if (res.ok) {
        setDbStatus('ok');
      } else {
        setDbStatus('error');
      }
    } catch {
      setDbStatus('error');
    }
  };

  // Auto-check on mount
  useEffect(() => {
    if (step === 'database') {
      void checkDatabase();
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const handleInstall = async () => {
    setStep('installing');
    setError('');

    try {
      const res = await fetch(`${baseUrl}/setup/install`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password, email }),
      });

      const body = await res.json();

      if (!res.ok) {
        setError(body.error?.message ?? 'Installation failed');
        setStep('admin');
        return;
      }

      setStep('done');
    } catch {
      setError('Network error');
      setStep('admin');
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-4">
      <div className="w-full max-w-md rounded-lg border border-border bg-card p-6 shadow-lg">
        <h1 className="text-xl font-bold">WebCalendar Setup</h1>
        <p className="mt-1 text-sm text-muted-foreground">First-time installation wizard</p>

        {/* Step indicator */}
        <div className="mt-4 flex gap-2 text-xs">
          {['Database', 'Admin Account', 'Settings'].map((label, i) => {
            const steps: Step[] = ['database', 'admin', 'settings'];
            const currentIdx = steps.indexOf(step);
            const isActive = i <= currentIdx && currentIdx >= 0;
            return (
              <span key={label} className={`rounded-full px-2 py-0.5 ${isActive ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}>
                {label}
              </span>
            );
          })}
        </div>

        {error && (
          <div role="alert" className="mt-4 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
            {error}
          </div>
        )}

        {/* Step 1: Database */}
        {step === 'database' && (
          <div className="mt-6 space-y-4">
            <div className="rounded-md border border-border p-4 text-sm">
              <div className="flex items-center gap-2">
                <span>{dbStatus === 'ok' ? '✅' : dbStatus === 'error' ? '❌' : '⏳'}</span>
                <span>
                  {dbStatus === 'checking' && 'Checking database connection...'}
                  {dbStatus === 'ok' && 'Database connection successful'}
                  {dbStatus === 'error' && 'Database connection failed'}
                </span>
              </div>
            </div>
            <div className="flex justify-end">
              <button
                onClick={() => setStep('admin')}
                disabled={dbStatus !== 'ok'}
                className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
              >
                Next
              </button>
            </div>
          </div>
        )}

        {/* Step 2: Admin Account */}
        {step === 'admin' && (
          <div className="mt-6 space-y-4">
            <div className="space-y-2">
              <label htmlFor="setup-username" className="text-sm font-medium">Username</label>
              <input
                id="setup-username"
                type="text"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-2">
              <label htmlFor="setup-password" className="text-sm font-medium">Password</label>
              <input
                id="setup-password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-2">
              <label htmlFor="setup-email" className="text-sm font-medium">Email</label>
              <input
                id="setup-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="flex justify-between">
              <button onClick={() => setStep('database')} className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent">Back</button>
              <button
                onClick={() => setStep('settings')}
                disabled={!password || !email}
                className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
              >
                Next
              </button>
            </div>
          </div>
        )}

        {/* Step 3: Settings */}
        {step === 'settings' && (
          <div className="mt-6 space-y-4">
            <div className="space-y-2">
              <label htmlFor="setup-timezone" className="text-sm font-medium">Timezone</label>
              <input
                id="setup-timezone"
                type="text"
                value={timezone}
                onChange={(e) => setTimezone(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-2">
              <label htmlFor="setup-sitename" className="text-sm font-medium">Site Name</label>
              <input
                id="setup-sitename"
                type="text"
                value={siteName}
                onChange={(e) => setSiteName(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="flex justify-between">
              <button onClick={() => setStep('admin')} className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent">Back</button>
              <button
                onClick={() => void handleInstall()}
                className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
              >
                Install
              </button>
            </div>
          </div>
        )}

        {/* Installing */}
        {step === 'installing' && (
          <div className="mt-8 text-center">
            <div className="text-2xl">⏳</div>
            <p className="mt-2 text-sm text-muted-foreground">Installing WebCalendar...</p>
          </div>
        )}

        {/* Done */}
        {step === 'done' && (
          <div className="mt-6 space-y-4">
            <div className="rounded-md bg-green-50 p-4 text-sm dark:bg-green-950">
              <p className="font-medium text-green-800 dark:text-green-200">Setup complete!</p>
              <p className="mt-1 text-green-700 dark:text-green-300">You can now log in with your admin credentials.</p>
            </div>
            <button
              onClick={() => navigate('/login')}
              className="inline-flex h-10 w-full items-center justify-center rounded-md bg-primary text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
              Go to Login
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
