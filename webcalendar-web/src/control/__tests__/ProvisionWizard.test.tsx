import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { setControlAuth, clearControlAuth } from '../control-auth';
import { ProvisionWizard } from '../ProvisionWizard';

describe('ProvisionWizard', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    setControlAuth('fake-token', { username: 'superadmin', role: 'super_admin' });
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    clearControlAuth();
  });

  it('renders step 1 with slug and name fields', () => {
    render(
      <MemoryRouter>
        <ProvisionWizard onClose={() => {}} />
      </MemoryRouter>,
    );

    expect(screen.getByText('New Tenant')).toBeInTheDocument();
    expect(screen.getByLabelText(/slug/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/company name/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /next/i })).toBeInTheDocument();
  });

  it('validates slug format on step 1', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ProvisionWizard onClose={() => {}} />
      </MemoryRouter>,
    );

    await user.type(screen.getByLabelText(/slug/i), 'AB');
    await user.type(screen.getByLabelText(/company name/i), 'Test');
    await user.click(screen.getByRole('button', { name: /next/i }));

    expect(screen.getByText(/at least 3/i)).toBeInTheDocument();
  });

  it('rejects reserved slugs', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ProvisionWizard onClose={() => {}} />
      </MemoryRouter>,
    );

    await user.type(screen.getByLabelText(/slug/i), 'admin');
    await user.type(screen.getByLabelText(/company name/i), 'Test');
    await user.click(screen.getByRole('button', { name: /next/i }));

    expect(screen.getByText(/reserved/i)).toBeInTheDocument();
  });

  it('advances to step 2 with valid slug', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ProvisionWizard onClose={() => {}} />
      </MemoryRouter>,
    );

    await user.type(screen.getByLabelText(/slug/i), 'test-company');
    await user.type(screen.getByLabelText(/company name/i), 'Test Company');
    await user.click(screen.getByRole('button', { name: /next/i }));

    expect(screen.getByLabelText(/admin email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/plan/i)).toBeInTheDocument();
  });

  it('shows review step with all details', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ProvisionWizard onClose={() => {}} />
      </MemoryRouter>,
    );

    // Step 1
    await user.type(screen.getByLabelText(/slug/i), 'review-co');
    await user.type(screen.getByLabelText(/company name/i), 'Review Corp');
    await user.click(screen.getByRole('button', { name: /next/i }));

    // Step 2
    await user.type(screen.getByLabelText(/admin email/i), 'admin@review.com');
    await user.click(screen.getByRole('button', { name: /next/i }));

    // Step 3 - Review
    expect(screen.getByText('review-co')).toBeInTheDocument();
    expect(screen.getByText('Review Corp')).toBeInTheDocument();
    expect(screen.getByText('admin@review.com')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /provision tenant/i })).toBeInTheDocument();
  });

  it('shows success screen after provisioning', async () => {
    const user = userEvent.setup();

    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({
        data: { slug: 'success-co', admin_email: 'admin@success.com', admin_password: 'gen123' },
      }), { status: 201, headers: { 'Content-Type': 'application/json' } }),
    );

    render(
      <MemoryRouter>
        <ProvisionWizard onClose={() => {}} />
      </MemoryRouter>,
    );

    await user.type(screen.getByLabelText(/slug/i), 'success-co');
    await user.type(screen.getByLabelText(/company name/i), 'Success Corp');
    await user.click(screen.getByRole('button', { name: /next/i }));
    await user.type(screen.getByLabelText(/admin email/i), 'admin@success.com');
    await user.click(screen.getByRole('button', { name: /next/i }));
    await user.click(screen.getByRole('button', { name: /provision tenant/i }));

    await waitFor(() => {
      expect(screen.getByText(/provisioned successfully/i)).toBeInTheDocument();
      expect(screen.getByText('gen123')).toBeInTheDocument();
    });
  });

  it('shows error screen with retry on failure', async () => {
    const user = userEvent.setup();

    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({
        error: { code: 400, message: 'Slug already taken' },
      }), { status: 400, headers: { 'Content-Type': 'application/json' } }),
    );

    render(
      <MemoryRouter>
        <ProvisionWizard onClose={() => {}} />
      </MemoryRouter>,
    );

    await user.type(screen.getByLabelText(/slug/i), 'fail-co');
    await user.type(screen.getByLabelText(/company name/i), 'Fail Corp');
    await user.click(screen.getByRole('button', { name: /next/i }));
    await user.type(screen.getByLabelText(/admin email/i), 'a@b.com');
    await user.click(screen.getByRole('button', { name: /next/i }));
    await user.click(screen.getByRole('button', { name: /provision tenant/i }));

    await waitFor(() => {
      expect(screen.getByText(/slug already taken/i)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
    });
  });
});
