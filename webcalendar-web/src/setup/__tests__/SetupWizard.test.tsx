import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { SetupWizard } from '../SetupWizard';

describe('SetupWizard', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders setup wizard with database check step', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ status: 'ok' }), { status: 200 }),
    );

    render(
      <MemoryRouter>
        <SetupWizard />
      </MemoryRouter>,
    );

    expect(screen.getByText(/setup/i)).toBeInTheDocument();
    await waitFor(() => {
      expect(screen.getByText(/database connection successful/i)).toBeInTheDocument();
    });
  });

  it('advances to admin account step', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ status: 'ok' }), { status: 200 }),
    );

    render(
      <MemoryRouter>
        <SetupWizard />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText(/database connection successful/i)).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: /next/i }));

    expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
  });

  it('advances to settings step', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ status: 'ok' }), { status: 200 }),
    );

    render(
      <MemoryRouter>
        <SetupWizard />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText(/database connection successful/i)).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: /next/i }));
    await user.type(screen.getByLabelText(/password/i), 'secret123');
    await user.type(screen.getByLabelText(/email/i), 'admin@test.com');
    await user.click(screen.getByRole('button', { name: /next/i }));

    expect(screen.getByLabelText(/timezone/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/site name/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /install/i })).toBeInTheDocument();
  });

  it('shows success after installation', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockImplementation((url: string) => {
      if (String(url).includes('/setup/install')) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: { message: 'Setup complete' } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          }),
        );
      }
      return Promise.resolve(
        new Response(JSON.stringify({ status: 'ok' }), { status: 200 }),
      );
    });

    render(
      <MemoryRouter>
        <SetupWizard />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText(/database connection successful/i)).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: /next/i }));
    await user.type(screen.getByLabelText(/password/i), 'secret123');
    await user.type(screen.getByLabelText(/email/i), 'admin@test.com');
    await user.click(screen.getByRole('button', { name: /next/i }));
    await user.click(screen.getByRole('button', { name: /install/i }));

    await waitFor(() => {
      expect(screen.getByText(/setup complete/i)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /go to login/i })).toBeInTheDocument();
    });
  });

  it('shows database error state', async () => {
    globalThis.fetch = vi.fn().mockRejectedValue(new Error('Network error'));

    render(
      <MemoryRouter>
        <SetupWizard />
      </MemoryRouter>,
    );

    await waitFor(() => {
      expect(screen.getByText(/database connection failed/i)).toBeInTheDocument();
    });

    // Next button should be disabled
    expect(screen.getByRole('button', { name: /next/i })).toBeDisabled();
  });
});
