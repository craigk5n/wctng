import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { EventDialog } from '../EventDialog';

const originalFetch = globalThis.fetch;

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

const mockGroups = [
  { id: 1, name: 'Engineering', owner: 'admin' },
  { id: 2, name: 'Marketing', owner: 'admin' },
];

const mockGroupDetail = {
  id: 1,
  name: 'Engineering',
  owner: 'admin',
  members: ['alice', 'bob', 'charlie'],
};

function setupFetchMock() {
  const fn = vi.fn().mockImplementation((url: string) => {
    const urlStr = String(url);

    // GET /groups/1 - group detail with members
    if (urlStr.includes('/groups/1')) {
      return Promise.resolve(
        new Response(JSON.stringify({ data: mockGroupDetail }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      );
    }

    // GET /groups - list all groups
    if (urlStr.includes('/groups')) {
      return Promise.resolve(
        new Response(JSON.stringify({ data: mockGroups }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      );
    }

    // Default: categories endpoint returns empty
    return Promise.resolve(
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
  });
  globalThis.fetch = fn;
  return fn;
}

function renderEventDialog(props: Record<string, unknown> = {}) {
  const defaultProps = {
    open: true,
    onClose: vi.fn(),
    onSave: vi.fn().mockResolvedValue(true),
    initialDate: '2026-03-15',
    initialTime: '10:00',
    initialAllDay: false,
  };
  return render(<EventDialog {...defaultProps} {...props} />, { wrapper: createWrapper() });
}

describe('EventDialog - Group Participants', () => {
  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('shows groups in suggestions when typing in participant input', async () => {
    const user = userEvent.setup();
    setupFetchMock();

    renderEventDialog();

    // Wait for groups to load
    await waitFor(() => {
      expect(globalThis.fetch).toHaveBeenCalled();
    });

    const input = screen.getByPlaceholderText(/type username/i);
    await user.type(input, 'eng');

    await waitFor(() => {
      expect(screen.getByText(/engineering/i)).toBeInTheDocument();
    });
  });

  it('shows group icon to distinguish from users', async () => {
    const user = userEvent.setup();
    setupFetchMock();

    renderEventDialog();

    await waitFor(() => {
      expect(globalThis.fetch).toHaveBeenCalled();
    });

    const input = screen.getByPlaceholderText(/type username/i);
    await user.type(input, 'eng');

    await waitFor(() => {
      // Group should have the 👥 icon
      const groupOption = screen.getByText(/engineering/i).closest('button');
      expect(groupOption?.textContent).toContain('👥');
    });
  });

  it('expands group to individual members when selected', async () => {
    const user = userEvent.setup();
    setupFetchMock();

    renderEventDialog();

    await waitFor(() => {
      expect(globalThis.fetch).toHaveBeenCalled();
    });

    const input = screen.getByPlaceholderText(/type username/i);
    await user.type(input, 'eng');

    await waitFor(() => {
      expect(screen.getByText(/engineering/i)).toBeInTheDocument();
    });

    // Click the group to add all members
    await user.click(screen.getByText(/engineering/i).closest('button')!);

    // All group members should now be in the participant list
    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
      expect(screen.getByText('bob')).toBeInTheDocument();
      expect(screen.getByText('charlie')).toBeInTheDocument();
    });
  });

  it('does not add duplicate members when selecting a group', async () => {
    const user = userEvent.setup();
    setupFetchMock();

    // Start with alice already as a participant
    renderEventDialog({ initialValues: { participants: ['alice'] } });

    await waitFor(() => {
      expect(globalThis.fetch).toHaveBeenCalled();
    });

    const input = screen.getByPlaceholderText(/type username/i);
    await user.type(input, 'eng');

    await waitFor(() => {
      expect(screen.getByText(/engineering/i)).toBeInTheDocument();
    });

    await user.click(screen.getByText(/engineering/i).closest('button')!);

    // Should have alice, bob, charlie - no duplicate alice
    await waitFor(() => {
      const badges = screen.getAllByText('alice');
      // alice should appear exactly once (as a participant badge)
      expect(badges).toHaveLength(1);
      expect(screen.getByText('bob')).toBeInTheDocument();
      expect(screen.getByText('charlie')).toBeInTheDocument();
    });
  });
});
