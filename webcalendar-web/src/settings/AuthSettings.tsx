import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface AuthMethod {
  type: string;
  name: string;
}

interface LdapConfig {
  host: string;
  port: number;
  base_dn: string;
  bind_dn: string;
  user_filter: string;
  use_tls: boolean;
  enabled: boolean;
}

export function AuthSettings() {
  const [methods, setMethods] = useState<AuthMethod[]>([]);
  const [ldap, setLdap] = useState<LdapConfig | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const { toast } = useToast();

  const fetchData = useCallback(async () => {
    setIsLoading(true);
    const [methodsRes, ldapRes] = await Promise.all([
      apiFetch<AuthMethod[]>('/admin/auth-providers'),
      apiFetch<LdapConfig>('/admin/ldap-config'),
    ]);
    if (methodsRes.data) setMethods(methodsRes.data);
    if (ldapRes.data) setLdap(ldapRes.data);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    void fetchData();
  }, [fetchData]);

  const handleLdapToggle = async () => {
    if (!ldap) return;
    setIsSaving(true);
    const { error } = await apiFetch('/admin/ldap-config', {
      method: 'PUT',
      body: JSON.stringify({ enabled: !ldap.enabled }),
    });
    setIsSaving(false);
    if (!error) {
      toast({ title: ldap.enabled ? 'LDAP disabled' : 'LDAP enabled', variant: 'success' });
      void fetchData();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  const handleLdapTest = async () => {
    const { data, error } = await apiFetch<{ status: string; message: string }>('/admin/ldap-config/test', {
      method: 'POST',
    });
    if (data) {
      toast({ title: data.message, variant: 'success' });
    } else {
      toast({ title: error?.message ?? 'Connection test failed', variant: 'error' });
    }
  };

  return (
    <div>
      <h2 className="text-2xl font-bold">Authentication</h2>
      <p className="mt-2 text-sm text-muted-foreground">
        Configure authentication providers for this calendar.
      </p>

      {isLoading ? (
        <p className="mt-4 text-sm text-muted-foreground">Loading...</p>
      ) : (
        <div className="mt-6 space-y-6">
          {/* Password Auth — always enabled */}
          <div className="rounded-lg border border-border p-4">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="font-medium">Password</h3>
                <p className="text-xs text-muted-foreground">Username and password authentication (always enabled)</p>
              </div>
              <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>
            </div>
          </div>

          {/* LDAP */}
          <div className="rounded-lg border border-border p-4">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="font-medium">LDAP / Active Directory</h3>
                <p className="text-xs text-muted-foreground">
                  {ldap?.host ? `Server: ${ldap.host}:${ldap.port}` : 'Not configured'}
                </p>
              </div>
              <div className="flex items-center gap-2">
                {ldap?.host && (
                  <button
                    onClick={() => void handleLdapTest()}
                    className="rounded border border-input px-2 py-1 text-xs hover:bg-accent"
                  >
                    Test
                  </button>
                )}
                <button
                  onClick={() => void handleLdapToggle()}
                  disabled={isSaving}
                  className={`rounded-full px-3 py-1 text-xs font-medium ${
                    ldap?.enabled
                      ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                      : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
                  }`}
                >
                  {ldap?.enabled ? 'Enabled' : 'Disabled'}
                </button>
              </div>
            </div>
          </div>

          {/* OAuth/OIDC Providers */}
          {methods.length > 0 && methods.map((m, i) => (
            <div key={i} className="rounded-lg border border-border p-4">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="font-medium">{m.name}</h3>
                  <p className="text-xs text-muted-foreground">{m.type === 'oidc' ? 'OpenID Connect' : 'OAuth2'}</p>
                </div>
                <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
