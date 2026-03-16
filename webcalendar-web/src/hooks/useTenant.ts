import { useEffect, useState } from 'react';

export interface TenantInfo {
  slug: string;
  name: string;
}

/**
 * Extracts tenant slug from the current hostname subdomain.
 *
 * For `acme.webcalendar.com`, returns slug `acme`.
 * For `localhost` or bare domains, returns null (standalone mode).
 */
function extractTenantSlug(): string | null {
  const hostname = window.location.hostname;

  // localhost or IP — standalone mode
  if (hostname === 'localhost' || /^\d+\.\d+\.\d+\.\d+$/.test(hostname)) {
    return null;
  }

  const parts = hostname.split('.');
  // Need at least 3 parts: slug.domain.tld
  if (parts.length < 3) {
    return null;
  }

  const slug = parts[0];
  // Skip common non-tenant subdomains
  if (['www', 'admin', 'control', 'api'].includes(slug)) {
    return null;
  }

  return slug;
}

/**
 * Hook that provides tenant context from the hostname subdomain.
 * Returns null in standalone mode.
 */
export function useTenant(): { tenant: TenantInfo | null; isLoading: boolean } {
  const [tenant, setTenant] = useState<TenantInfo | null>(null);
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    const slug = extractTenantSlug();
    if (!slug) {
      setTenant(null);
      return;
    }

    setIsLoading(true);

    const baseUrl = import.meta.env.VITE_API_URL ?? '/api/v2';

    void fetch(`${baseUrl}/tenant/info`)
      .then((res) => (res.ok ? res.json() : null))
      .then((body) => {
        if (body?.data) {
          setTenant({ slug, name: body.data.name ?? slug });
        } else {
          // Fallback: use slug as name if endpoint doesn't exist yet
          setTenant({ slug, name: slug });
        }
      })
      .catch(() => {
        setTenant({ slug, name: slug });
      })
      .finally(() => setIsLoading(false));
  }, []);

  return { tenant, isLoading };
}

// Export for testing
export { extractTenantSlug };
