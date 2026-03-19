import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { BackupPage } from '../BackupPage';

vi.mock('../../api/client', () => ({
  apiFetch: vi.fn().mockResolvedValue({
    data: [
      { filename: 'webcalendar-backup-2026-03-18.sql', size_bytes: 45000, created_at: '2026-03-18T12:00:00+00:00' },
      { filename: 'webcalendar-backup-2026-03-17.sql', size_bytes: 44000, created_at: '2026-03-17T12:00:00+00:00' },
    ],
    error: null,
  }),
}));

vi.mock('../../components/toast/ToastProvider', () => ({
  useToast: () => ({ toast: vi.fn() }),
}));

describe('BackupPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the page title', async () => {
    render(<BackupPage />);
    await waitFor(() => {
      expect(screen.getByText(/Database Backup/)).toBeInTheDocument();
    });
  });

  it('shows create backup button', async () => {
    render(<BackupPage />);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /create backup/i })).toBeInTheDocument();
    });
  });

  it('lists existing backups', async () => {
    render(<BackupPage />);
    await waitFor(() => {
      expect(screen.getByText('webcalendar-backup-2026-03-18.sql')).toBeInTheDocument();
      expect(screen.getByText('webcalendar-backup-2026-03-17.sql')).toBeInTheDocument();
    });
  });

  it('shows restore section with confirmation input', async () => {
    render(<BackupPage />);
    await waitFor(() => {
      expect(screen.getByText(/Restore from Backup/)).toBeInTheDocument();
      expect(screen.getByLabelText(/Type RESTORE/)).toBeInTheDocument();
      expect(screen.getByLabelText(/Backup File/)).toBeInTheDocument();
    });
  });

  it('restore button is disabled without confirmation', async () => {
    render(<BackupPage />);
    await waitFor(() => {
      const btn = screen.getByText('Restore Database');
      expect(btn).toBeDisabled();
    });
  });
});
