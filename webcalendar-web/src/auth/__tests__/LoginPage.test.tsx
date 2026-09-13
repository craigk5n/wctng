import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AuthProvider } from '../AuthProvider';
import { LoginPage } from '../LoginPage';

// LoginPage now uses plain fetch, so no need to mock createApiClient

function renderLoginPage() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <LoginPage />
      </AuthProvider>
    </MemoryRouter>,
  );
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function mockFeatureFlagsFetch(flags: Record<string, string>): void {
  globalThis.fetch = vi.fn().mockImplementation((input: RequestInfo | URL) => {
    const url = typeof input === 'string' ? input : input.toString();
    if (url.includes('/config/features')) {
      return Promise.resolve(jsonResponse({ data: flags, error: null }));
    }
    if (url.includes('/auth/oauth/providers')) {
      return Promise.resolve(jsonResponse({ data: [], error: null }));
    }
    return Promise.resolve(jsonResponse({ data: null, error: null }, 404));
  });
}

describe('LoginPage', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    localStorage.clear();
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    localStorage.clear();
  });

  it('renders username and password fields', () => {
    renderLoginPage();

    expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sign in|log in/i })).toBeInTheDocument();
  });

  it('shows error on invalid credentials', async () => {
    const user = userEvent.setup();

    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: null,
          meta: null,
          error: { code: 401, message: 'Invalid credentials', details: [] },
        }),
        { status: 401, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    renderLoginPage();

    await user.type(screen.getByLabelText(/username/i), 'admin');
    await user.type(screen.getByLabelText(/password/i), 'wrong');
    await user.click(screen.getByRole('button', { name: /sign in|log in/i }));

    expect(await screen.findByText(/invalid|incorrect|failed|error/i)).toBeInTheDocument();
  });

  it('disables submit button during request', async () => {
    const user = userEvent.setup();

    globalThis.fetch = vi.fn().mockImplementation(
      () =>
        new Promise((resolve) =>
          setTimeout(
            () =>
              resolve(
                new Response(
                  JSON.stringify({
                    data: null,
                    meta: null,
                    error: { code: 401, message: 'Invalid', details: [] },
                  }),
                  { status: 401, headers: { 'Content-Type': 'application/json' } },
                ),
              ),
            500,
          ),
        ),
    );

    renderLoginPage();

    await user.type(screen.getByLabelText(/username/i), 'admin');
    await user.type(screen.getByLabelText(/password/i), 'pass');
    await user.click(screen.getByRole('button', { name: /sign in|log in/i }));

    expect(screen.getByRole('button', { name: /sign in|log in|signing/i })).toBeDisabled();
  });

  it('has accessible labels on form fields', () => {
    renderLoginPage();

    const usernameInput = screen.getByLabelText(/username/i);
    const passwordInput = screen.getByLabelText(/password/i);

    expect(usernameInput).toHaveAttribute('type', 'text');
    expect(passwordInput).toHaveAttribute('type', 'password');
  });

  it('shows Remember me checkbox when the feature is enabled', async () => {
    mockFeatureFlagsFetch({ DISABLE_REMEMBER_ME: 'N' });

    renderLoginPage();

    expect(await screen.findByLabelText(/remember me/i)).toBeInTheDocument();
  });

  it('hides Remember me checkbox when admin has disabled it', async () => {
    mockFeatureFlagsFetch({ DISABLE_REMEMBER_ME: 'Y' });

    renderLoginPage();

    // Give the fetch a chance to resolve and the component to re-render
    await waitFor(() => {
      expect(screen.queryByLabelText(/remember me/i)).not.toBeInTheDocument();
    });

    // Username field should still be there — confirms the page did render
    expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
  });
});
