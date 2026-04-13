import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { TOKEN_STORAGE_KEY, getApiBaseUrl, getAuthHeaders } from '../api/client';
import { AuthContext, type AuthContextValue, type AuthUser } from './auth-context';

/**
 * Decodes a JWT payload (without verification — verification is server-side).
 */
function decodeJwtPayload(token: string): Record<string, unknown> | null {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) return null;
    const payload = atob(parts[1]);
    return JSON.parse(payload) as Record<string, unknown>;
  } catch {
    return null;
  }
}

/**
 * Checks if a JWT token is expired based on the `exp` claim.
 */
function isTokenExpired(token: string): boolean {
  const payload = decodeJwtPayload(token);
  if (!payload || typeof payload.exp !== 'number') return true;
  return payload.exp < Date.now() / 1000;
}

/**
 * Returns seconds until a JWT token expires, or 0 if already expired.
 */
function secondsUntilExpiry(token: string): number {
  const payload = decodeJwtPayload(token);
  if (!payload || typeof payload.exp !== 'number') return 0;
  return Math.max(0, payload.exp - Date.now() / 1000);
}

/**
 * Extracts user info from a JWT payload.
 */
function userFromToken(token: string): AuthUser | null {
  const payload = decodeJwtPayload(token);
  if (!payload || typeof payload.username !== 'string') return null;
  return {
    login: payload.username,
    firstname: '',
    lastname: '',
    email: '',
    is_admin: payload.is_admin === true,
  };
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(() => {
    const stored = localStorage.getItem(TOKEN_STORAGE_KEY);
    if (stored && !isTokenExpired(stored)) {
      return stored;
    }
    // Clear expired token
    if (stored) {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
    }
    return null;
  });

  const [user, setUser] = useState<AuthUser | null>(() => {
    const stored = localStorage.getItem(TOKEN_STORAGE_KEY);
    if (stored && !isTokenExpired(stored)) {
      return userFromToken(stored);
    }
    return null;
  });

  // Sync localStorage when token changes
  useEffect(() => {
    if (token) {
      localStorage.setItem(TOKEN_STORAGE_KEY, token);
    } else {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
    }
  }, [token]);

  // Auto-refresh token before it expires
  const refreshingRef = useRef(false);
  useEffect(() => {
    if (!token) return;

    const scheduleRefresh = () => {
      const remaining = secondsUntilExpiry(token);
      // Refresh 5 minutes before expiry, or at half-life if TTL < 10 minutes
      const refreshAt = remaining > 600 ? remaining - 300 : remaining / 2;
      if (refreshAt <= 0) return;

      return window.setTimeout(async () => {
        if (refreshingRef.current) return;
        refreshingRef.current = true;
        try {
          const res = await fetch(`${getApiBaseUrl()}/auth/refresh`, {
            method: 'POST',
            headers: getAuthHeaders(),
          });
          if (res.ok) {
            const body = await res.json();
            if (body?.data?.token) {
              setToken(body.data.token);
              if (body.data.user) setUser(body.data.user);
            }
          }
        } catch {
          // Silent failure — user will be logged out when token expires
        } finally {
          refreshingRef.current = false;
        }
      }, refreshAt * 1000);
    };

    const timerId = scheduleRefresh();
    return () => {
      if (timerId) clearTimeout(timerId);
    };
  }, [token]);

  const login = useCallback((newToken: string, newUser: AuthUser) => {
    setToken(newToken);
    setUser(newUser);
  }, []);

  const logout = useCallback(() => {
    setToken(null);
    setUser(null);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      token,
      isAuthenticated: token !== null && user !== null,
      login,
      logout,
    }),
    [user, token, login, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
