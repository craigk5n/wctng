import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { DashboardPage } from '../DashboardPage';

vi.mock('../../api/client', () => ({
  apiFetch: vi.fn().mockResolvedValue({
    data: {
      users: { total: 42, active_7d: 15, created_7d: 2 },
      events: { total: 1234, created_7d: 56, upcoming_7d: 23 },
      system: { db_size_mb: 45.2, php_version: '8.2.30', db_driver: 'mysql', recent_errors: 3 },
      email: { reminders_sent_7d: 89, agenda_sent_7d: 12 },
    },
    error: null,
  }),
}));

describe('DashboardPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the page title', async () => {
    render(<DashboardPage />);
    await waitFor(() => {
      expect(screen.getByText('System Dashboard')).toBeInTheDocument();
    });
  });

  it('shows user statistics', async () => {
    render(<DashboardPage />);
    await waitFor(() => {
      expect(screen.getByText('42')).toBeInTheDocument();
      expect(screen.getByText(/15 active/)).toBeInTheDocument();
    });
  });

  it('shows event statistics', async () => {
    render(<DashboardPage />);
    await waitFor(() => {
      expect(screen.getByText('1234')).toBeInTheDocument();
      expect(screen.getByText('23')).toBeInTheDocument();
    });
  });

  it('shows system information', async () => {
    render(<DashboardPage />);
    await waitFor(() => {
      expect(screen.getByText('8.2.30')).toBeInTheDocument();
      expect(screen.getByText('mysql')).toBeInTheDocument();
      expect(screen.getByText('45.2 MB')).toBeInTheDocument();
    });
  });

  it('shows error count', async () => {
    render(<DashboardPage />);
    await waitFor(() => {
      expect(screen.getByText('3')).toBeInTheDocument();
    });
  });

  it('shows email stats', async () => {
    render(<DashboardPage />);
    await waitFor(() => {
      expect(screen.getByText('89')).toBeInTheDocument();
      expect(screen.getByText('12')).toBeInTheDocument();
    });
  });
});
