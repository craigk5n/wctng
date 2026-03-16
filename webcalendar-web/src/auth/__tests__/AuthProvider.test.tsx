import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { AuthProvider } from '../AuthProvider';
import { useAuth } from '../auth-context';
import { TOKEN_STORAGE_KEY } from '../../api/client';

function TestConsumer() {
  const { user, token, isAuthenticated, logout } = useAuth();
  return (
    <div>
      <span data-testid="is-auth">{String(isAuthenticated)}</span>
      <span data-testid="user">{user ? user.login : 'null'}</span>
      <span data-testid="token">{token ?? 'null'}</span>
      <button onClick={logout}>Logout</button>
    </div>
  );
}

describe('AuthProvider', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('provides null user when not logged in', () => {
    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>,
    );

    expect(screen.getByTestId('is-auth').textContent).toBe('false');
    expect(screen.getByTestId('user').textContent).toBe('null');
    expect(screen.getByTestId('token').textContent).toBe('null');
  });

  it('restores session from localStorage on mount', () => {
    // Store a valid (non-expired) fake JWT
    const payload = btoa(
      JSON.stringify({
        username: 'admin',
        is_admin: true,
        exp: Math.floor(Date.now() / 1000) + 3600,
        iat: Math.floor(Date.now() / 1000),
      }),
    );
    const fakeToken = `eyJhbGciOiJSUzI1NiJ9.${payload}.signature`;
    localStorage.setItem(TOKEN_STORAGE_KEY, fakeToken);

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>,
    );

    expect(screen.getByTestId('is-auth').textContent).toBe('true');
    expect(screen.getByTestId('user').textContent).toBe('admin');
    expect(screen.getByTestId('token').textContent).toBe(fakeToken);
  });

  it('clears expired token on mount', () => {
    const payload = btoa(
      JSON.stringify({
        username: 'admin',
        is_admin: true,
        exp: Math.floor(Date.now() / 1000) - 100, // expired
        iat: Math.floor(Date.now() / 1000) - 3700,
      }),
    );
    const expiredToken = `eyJhbGciOiJSUzI1NiJ9.${payload}.signature`;
    localStorage.setItem(TOKEN_STORAGE_KEY, expiredToken);

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>,
    );

    expect(screen.getByTestId('is-auth').textContent).toBe('false');
    expect(screen.getByTestId('user').textContent).toBe('null');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('clears user on logout', async () => {
    const payload = btoa(
      JSON.stringify({
        username: 'admin',
        is_admin: true,
        exp: Math.floor(Date.now() / 1000) + 3600,
        iat: Math.floor(Date.now() / 1000),
      }),
    );
    const fakeToken = `eyJhbGciOiJSUzI1NiJ9.${payload}.signature`;
    localStorage.setItem(TOKEN_STORAGE_KEY, fakeToken);

    render(
      <AuthProvider>
        <TestConsumer />
      </AuthProvider>,
    );

    expect(screen.getByTestId('is-auth').textContent).toBe('true');

    await act(async () => {
      screen.getByText('Logout').click();
    });

    expect(screen.getByTestId('is-auth').textContent).toBe('false');
    expect(screen.getByTestId('user').textContent).toBe('null');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });
});
