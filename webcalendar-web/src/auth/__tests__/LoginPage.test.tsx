import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
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
});
