const CONTROL_TOKEN_KEY = 'wctng_control_token';
const CONTROL_USER_KEY = 'wctng_control_user';

export interface ControlUser {
  username: string;
  role: string;
}

export function getControlToken(): string | null {
  return localStorage.getItem(CONTROL_TOKEN_KEY);
}

export function getControlUser(): ControlUser | null {
  const raw = localStorage.getItem(CONTROL_USER_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as ControlUser;
  } catch {
    return null;
  }
}

export function setControlAuth(token: string, user: ControlUser): void {
  localStorage.setItem(CONTROL_TOKEN_KEY, token);
  localStorage.setItem(CONTROL_USER_KEY, JSON.stringify(user));
}

export function clearControlAuth(): void {
  localStorage.removeItem(CONTROL_TOKEN_KEY);
  localStorage.removeItem(CONTROL_USER_KEY);
}

export function isControlAuthenticated(): boolean {
  return getControlToken() !== null;
}

export async function controlApiFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<{ data: T | null; error: { code: number; message: string } | null }> {
  const baseUrl = import.meta.env.VITE_API_URL ?? '/api/v2';
  // Control plane uses same host but /control/v1 prefix
  const controlBase = baseUrl.replace('/api/v2', '/control/v1');
  const url = `${controlBase}${path}`;

  const token = getControlToken();
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(options.headers as Record<string, string> ?? {}),
  };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  try {
    const res = await fetch(url, { ...options, headers });
    if (res.status === 204) return { data: null, error: null };

    const body = await res.json();

    if (!res.ok) {
      return { data: null, error: body.error ?? { code: res.status, message: 'Request failed' } };
    }

    return { data: body.data ?? null, error: null };
  } catch (e) {
    return { data: null, error: { code: 0, message: (e as Error).message } };
  }
}
