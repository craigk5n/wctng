import { useState } from 'react';
import { controlApiFetch } from './control-auth';

type Step = 'slug' | 'details' | 'review' | 'provisioning' | 'success' | 'error';

interface ProvisionResult {
  slug: string;
  admin_email: string;
  admin_password: string;
}

export function ProvisionWizard({ onClose }: { onClose: () => void }) {
  const [step, setStep] = useState<Step>('slug');
  const [slug, setSlug] = useState('');
  const [name, setName] = useState('');
  const [adminEmail, setAdminEmail] = useState('');
  const [plan, setPlan] = useState('free');
  const [slugError, setSlugError] = useState('');
  const [result, setResult] = useState<ProvisionResult | null>(null);
  const [errorMsg, setErrorMsg] = useState('');

  const validateSlug = (s: string): string => {
    if (s.length < 3) return 'Must be at least 3 characters';
    if (s.length > 50) return 'Must be 50 characters or less';
    if (!/^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(s)) return 'Lowercase letters, numbers, hyphens only';
    const reserved = ['admin', 'api', 'app', 'www', 'mail', 'control', 'dashboard'];
    if (reserved.includes(s)) return `"${s}" is reserved`;
    return '';
  };

  const handleSlugNext = () => {
    const err = validateSlug(slug);
    if (err) {
      setSlugError(err);
      return;
    }
    if (!name.trim()) {
      setSlugError('Name is required');
      return;
    }
    setSlugError('');
    setStep('details');
  };

  const handleDetailsNext = () => {
    if (!adminEmail.trim()) {
      return;
    }
    setStep('review');
  };

  const handleProvision = async () => {
    setStep('provisioning');
    setErrorMsg('');

    const { data, error } = await controlApiFetch<ProvisionResult>('/tenants', {
      method: 'POST',
      body: JSON.stringify({ slug, name: name.trim(), admin_email: adminEmail.trim(), plan }),
    });

    if (error) {
      setErrorMsg(error.message);
      setStep('error');
      return;
    }

    setResult(data);
    setStep('success');
  };

  const stepIndicator = (
    <div className="mb-6 flex items-center gap-2 text-xs text-muted-foreground">
      {['Slug & Name', 'Details', 'Review'].map((label, i) => {
        const stepOrder: Step[] = ['slug', 'details', 'review'];
        const currentIdx = stepOrder.indexOf(step as 'slug' | 'details' | 'review');
        const isActive = i <= currentIdx && currentIdx >= 0;
        return (
          <span key={label} className={`rounded-full px-2 py-0.5 ${isActive ? 'bg-primary text-primary-foreground' : 'bg-muted'}`}>
            {label}
          </span>
        );
      })}
    </div>
  );

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={onClose}>
      <div className="w-full max-w-md rounded-lg bg-card p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold">New Tenant</h2>
          <button onClick={onClose} className="rounded p-1 text-muted-foreground hover:bg-accent" aria-label="Close">✕</button>
        </div>

        {(step === 'slug' || step === 'details' || step === 'review') && stepIndicator}

        {/* Step 1: Slug + Name */}
        {step === 'slug' && (
          <div className="mt-4 space-y-4">
            <div className="space-y-2">
              <label htmlFor="prov-slug" className="text-sm font-medium">Slug</label>
              <input
                id="prov-slug"
                type="text"
                value={slug}
                onChange={(e) => { setSlug(e.target.value.toLowerCase()); setSlugError(''); }}
                placeholder="my-company"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
              />
              {slugError && <p className="text-xs text-destructive">{slugError}</p>}
            </div>
            <div className="space-y-2">
              <label htmlFor="prov-name" className="text-sm font-medium">Company Name</label>
              <input
                id="prov-name"
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="My Company Inc."
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="flex justify-end">
              <button onClick={handleSlugNext} className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                Next
              </button>
            </div>
          </div>
        )}

        {/* Step 2: Details */}
        {step === 'details' && (
          <div className="mt-4 space-y-4">
            <div className="space-y-2">
              <label htmlFor="prov-email" className="text-sm font-medium">Admin Email</label>
              <input
                id="prov-email"
                type="email"
                value={adminEmail}
                onChange={(e) => setAdminEmail(e.target.value)}
                placeholder="admin@company.com"
                required
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-2">
              <label htmlFor="prov-plan" className="text-sm font-medium">Plan</label>
              <select
                id="prov-plan"
                value={plan}
                onChange={(e) => setPlan(e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="free">Free</option>
                <option value="pro">Pro</option>
                <option value="enterprise">Enterprise</option>
              </select>
            </div>
            <div className="flex justify-between">
              <button onClick={() => setStep('slug')} className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent">
                Back
              </button>
              <button onClick={handleDetailsNext} className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                Next
              </button>
            </div>
          </div>
        )}

        {/* Step 3: Review */}
        {step === 'review' && (
          <div className="mt-4 space-y-4">
            <div className="rounded-md border border-border p-4 text-sm space-y-2">
              <div><span className="font-medium text-muted-foreground">Slug:</span> <span className="font-mono">{slug}</span></div>
              <div><span className="font-medium text-muted-foreground">Name:</span> {name}</div>
              <div><span className="font-medium text-muted-foreground">Admin Email:</span> {adminEmail}</div>
              <div><span className="font-medium text-muted-foreground">Plan:</span> {plan}</div>
            </div>
            <div className="flex justify-between">
              <button onClick={() => setStep('details')} className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent">
                Back
              </button>
              <button onClick={() => void handleProvision()} className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                Provision Tenant
              </button>
            </div>
          </div>
        )}

        {/* Provisioning */}
        {step === 'provisioning' && (
          <div className="mt-8 text-center">
            <div className="text-2xl">⏳</div>
            <p className="mt-2 text-sm text-muted-foreground">Provisioning tenant...</p>
            <p className="mt-1 text-xs text-muted-foreground">Creating database and admin user</p>
          </div>
        )}

        {/* Success */}
        {step === 'success' && result && (
          <div className="mt-4 space-y-4">
            <div className="rounded-md bg-green-50 p-4 text-sm dark:bg-green-950">
              <p className="font-medium text-green-800 dark:text-green-200">Tenant provisioned successfully!</p>
            </div>
            <div className="rounded-md border border-border p-4 text-sm space-y-2">
              <div><span className="font-medium text-muted-foreground">Slug:</span> <span className="font-mono">{result.slug}</span></div>
              <div><span className="font-medium text-muted-foreground">Admin Email:</span> {result.admin_email}</div>
              <div><span className="font-medium text-muted-foreground">Admin Password:</span> <code className="rounded bg-muted px-1 py-0.5 text-xs">{result.admin_password}</code></div>
            </div>
            <div className="flex justify-end">
              <button onClick={onClose} className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                Done
              </button>
            </div>
          </div>
        )}

        {/* Error */}
        {step === 'error' && (
          <div className="mt-4 space-y-4">
            <div role="alert" className="rounded-md bg-destructive/10 p-4 text-sm text-destructive">
              {errorMsg}
            </div>
            <div className="flex justify-between">
              <button onClick={() => setStep('review')} className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent">
                Back
              </button>
              <button onClick={() => void handleProvision()} className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                Retry
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
