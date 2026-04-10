import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PurgePage } from '../PurgePage';

const apiFetch = vi.fn();
vi.mock('../../api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}));

const toast = vi.fn();
vi.mock('../../components/toast/ToastProvider', () => ({
  useToast: () => ({ toast }),
}));

describe('PurgePage', () => {
  beforeEach(() => {
    apiFetch.mockReset();
    toast.mockReset();
  });

  it('renders the page heading and preview button', () => {
    render(<PurgePage />);
    expect(screen.getByRole('heading', { name: /Event Purge/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Preview/i })).toBeInTheDocument();
  });

  it('sends a dry-run request when Preview is clicked', async () => {
    apiFetch.mockResolvedValue({
      data: { count: 5, dry_run: true, before_date: '2025-01-01', user_login: null },
      error: null,
    });
    const user = userEvent.setup();

    render(<PurgePage />);
    await user.clear(screen.getByLabelText(/Delete events before/i));
    await user.type(screen.getByLabelText(/Delete events before/i), '2025-01-01');
    await user.click(screen.getByRole('button', { name: /Preview/i }));

    await waitFor(() => {
      expect(apiFetch).toHaveBeenCalledTimes(1);
    });
    const [path, opts] = apiFetch.mock.calls[0];
    expect(path).toBe('/admin/events/purge');
    expect(opts.method).toBe('POST');
    const body = JSON.parse(opts.body);
    expect(body.dry_run).toBe(true);
    expect(body.before_date).toBe('2025-01-01');
    expect(body.include_repeating).toBe(false);
    expect(body.confirm_count).toBeUndefined();
  });

  it('shows the preview count and disables Purge until DELETE is typed', async () => {
    apiFetch.mockResolvedValue({
      data: { count: 7, dry_run: true, before_date: '2025-01-01', user_login: null },
      error: null,
    });
    const user = userEvent.setup();

    render(<PurgePage />);
    await user.click(screen.getByRole('button', { name: /Preview/i }));

    await waitFor(() => {
      expect(screen.getByText(/will be affected/i)).toBeInTheDocument();
    });

    const purgeBtn = screen.getByRole('button', { name: /Purge 7 events/i });
    expect(purgeBtn).toBeDisabled();

    await user.type(screen.getByLabelText(/Type/i), 'DELETE');
    expect(purgeBtn).toBeEnabled();
  });

  it('Purge button sends live-run with confirm_count matching preview', async () => {
    apiFetch
      .mockResolvedValueOnce({
        data: { count: 3, dry_run: true, before_date: '2025-01-01', user_login: null },
        error: null,
      })
      .mockResolvedValueOnce({
        data: { count: 3, dry_run: false, before_date: '2025-01-01', user_login: null },
        error: null,
      });
    const user = userEvent.setup();

    render(<PurgePage />);
    await user.click(screen.getByRole('button', { name: /Preview/i }));
    await waitFor(() => screen.getByText(/will be affected/i));

    await user.type(screen.getByLabelText(/Type/i), 'DELETE');
    await user.click(screen.getByRole('button', { name: /Purge 3 events/i }));

    await waitFor(() => {
      expect(apiFetch).toHaveBeenCalledTimes(2);
    });
    const [, opts] = apiFetch.mock.calls[1];
    const body = JSON.parse(opts.body);
    expect(body.dry_run).toBe(false);
    expect(body.confirm_count).toBe(3);
  });

  it('shows zero-count state without a confirm prompt', async () => {
    apiFetch.mockResolvedValue({
      data: { count: 0, dry_run: true, before_date: '2025-01-01', user_login: null },
      error: null,
    });
    const user = userEvent.setup();

    render(<PurgePage />);
    await user.click(screen.getByRole('button', { name: /Preview/i }));

    await waitFor(() => {
      expect(screen.getByText(/Nothing to purge/i)).toBeInTheDocument();
    });
    expect(screen.queryByRole('button', { name: /Purge/ })).not.toBeInTheDocument();
  });

  it('changing the date after preview resets the confirm flow', async () => {
    apiFetch.mockResolvedValue({
      data: { count: 5, dry_run: true, before_date: '2025-01-01', user_login: null },
      error: null,
    });
    const user = userEvent.setup();

    render(<PurgePage />);
    await user.click(screen.getByRole('button', { name: /Preview/i }));
    await waitFor(() => screen.getByText(/will be affected/i));

    const dateInput = screen.getByLabelText(/Delete events before/i);
    await user.clear(dateInput);
    await user.type(dateInput, '2024-06-01');

    // The preview panel should have cleared — no confirm input visible
    expect(screen.queryByLabelText(/Type/i)).not.toBeInTheDocument();
  });

  it('includes user_login in the body when provided', async () => {
    apiFetch.mockResolvedValue({
      data: { count: 1, dry_run: true, before_date: '2025-01-01', user_login: 'alice' },
      error: null,
    });
    const user = userEvent.setup();

    render(<PurgePage />);
    await user.type(screen.getByLabelText(/Limit to user/i), 'alice');
    await user.click(screen.getByRole('button', { name: /Preview/i }));

    await waitFor(() => {
      expect(apiFetch).toHaveBeenCalled();
    });
    const body = JSON.parse(apiFetch.mock.calls[0][1].body);
    expect(body.user_login).toBe('alice');
  });

  it('passes include_repeating when checkbox is set', async () => {
    apiFetch.mockResolvedValue({
      data: { count: 2, dry_run: true, before_date: '2025-01-01', user_login: null },
      error: null,
    });
    const user = userEvent.setup();

    render(<PurgePage />);
    await user.click(screen.getByLabelText(/Include recurring/i));
    await user.click(screen.getByRole('button', { name: /Preview/i }));

    await waitFor(() => {
      expect(apiFetch).toHaveBeenCalled();
    });
    const body = JSON.parse(apiFetch.mock.calls[0][1].body);
    expect(body.include_repeating).toBe(true);
  });

  it('toasts an error when preview fails', async () => {
    apiFetch.mockResolvedValue({ data: null, error: { code: 400, message: 'bad date' } });
    const user = userEvent.setup();

    render(<PurgePage />);
    await user.click(screen.getByRole('button', { name: /Preview/i }));

    await waitFor(() => {
      expect(toast).toHaveBeenCalledWith(expect.objectContaining({ variant: 'error' }));
    });
  });
});
