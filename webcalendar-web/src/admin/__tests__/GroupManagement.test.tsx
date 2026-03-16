import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { GroupManagement } from '../GroupManagement';

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: 0 } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <ToastProvider>{children}</ToastProvider>
      </QueryClientProvider>
    );
  };
}

function mockFetchResponses(...responses: Array<{ data: unknown; status?: number }>) {
  const fn = vi.fn();
  for (const r of responses) {
    fn.mockResolvedValueOnce(
      new Response(JSON.stringify({ data: r.data }), {
        status: r.status ?? 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
  }
  globalThis.fetch = fn;
  return fn;
}

describe('GroupManagement', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders the page title', () => {
    mockFetchResponses({ data: [] });
    render(<GroupManagement />, { wrapper: createWrapper() });
    expect(screen.getByText(/groups/i)).toBeInTheDocument();
  });

  it('displays groups in a list', async () => {
    mockFetchResponses({
      data: [
        { id: 1, name: 'Engineering', owner: 'admin' },
        { id: 2, name: 'Marketing', owner: 'admin' },
      ],
    });

    render(<GroupManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Engineering')).toBeInTheDocument();
      expect(screen.getByText('Marketing')).toBeInTheDocument();
    });
  });

  it('has a Create Group button', () => {
    mockFetchResponses({ data: [] });
    render(<GroupManagement />, { wrapper: createWrapper() });
    expect(screen.getByRole('button', { name: /new group/i })).toBeInTheDocument();
  });

  it('shows create form when button clicked', async () => {
    const user = userEvent.setup();
    mockFetchResponses({ data: [] });

    render(<GroupManagement />, { wrapper: createWrapper() });
    await user.click(screen.getByRole('button', { name: /new group/i }));

    expect(screen.getByLabelText(/name/i)).toBeInTheDocument();
  });

  it('creates a group via API', async () => {
    const user = userEvent.setup();
    const fetchMock = mockFetchResponses(
      { data: [] },                                          // initial list
      { data: { id: 3, name: 'Design', owner: 'admin' }, status: 201 }, // create
      { data: [{ id: 3, name: 'Design', owner: 'admin' }] }, // refetch
    );

    render(<GroupManagement />, { wrapper: createWrapper() });
    await user.click(screen.getByRole('button', { name: /new group/i }));
    await user.type(screen.getByLabelText(/name/i), 'Design');
    await user.click(screen.getByRole('button', { name: /^create$/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(3);
      const createCall = fetchMock.mock.calls[1];
      expect(createCall[0]).toContain('/groups');
      expect(createCall[1].method).toBe('POST');
    });
  });

  it('shows group members when clicking a group', async () => {
    const user = userEvent.setup();
    mockFetchResponses(
      { data: [{ id: 1, name: 'Engineering', owner: 'admin' }] },
      { data: { id: 1, name: 'Engineering', owner: 'admin', members: ['alice', 'bob'] } },
    );

    render(<GroupManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Engineering')).toBeInTheDocument();
    });

    await user.click(screen.getByText('Engineering'));

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
      expect(screen.getByText('bob')).toBeInTheDocument();
    });
  });

  it('can add a member to a group', async () => {
    const user = userEvent.setup();
    const fetchMock = mockFetchResponses(
      { data: [{ id: 1, name: 'Engineering', owner: 'admin' }] },
      { data: { id: 1, name: 'Engineering', owner: 'admin', members: [] } },
      { data: { message: 'Members added' } },               // add member
      { data: { id: 1, name: 'Engineering', owner: 'admin', members: ['charlie'] } }, // refetch
    );

    render(<GroupManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Engineering')).toBeInTheDocument();
    });

    await user.click(screen.getByText('Engineering'));

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/add member/i)).toBeInTheDocument();
    });

    await user.type(screen.getByPlaceholderText(/add member/i), 'charlie');
    await user.click(screen.getByRole('button', { name: /^add$/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(4);
      const addCall = fetchMock.mock.calls[2];
      expect(addCall[0]).toContain('/members');
      expect(addCall[1].method).toBe('POST');
    });
  });

  it('can remove a member from a group', async () => {
    const user = userEvent.setup();
    const fetchMock = mockFetchResponses(
      { data: [{ id: 1, name: 'Engineering', owner: 'admin' }] },
      { data: { id: 1, name: 'Engineering', owner: 'admin', members: ['alice'] } },
      // DELETE response (204 - no content)
    );

    // Override for the DELETE call which returns 204
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));
    // Refetch group after remove
    fetchMock.mockResolvedValueOnce(
      new Response(
        JSON.stringify({ data: { id: 1, name: 'Engineering', owner: 'admin', members: [] } }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    render(<GroupManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Engineering')).toBeInTheDocument();
    });

    await user.click(screen.getByText('Engineering'));

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
    });

    // Click remove button next to alice
    const removeButtons = screen.getAllByRole('button', { name: /remove|✕|×/i });
    await user.click(removeButtons[0]);

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(4);
      const deleteCall = fetchMock.mock.calls[2];
      expect(deleteCall[0]).toContain('/members/alice');
      expect(deleteCall[1].method).toBe('DELETE');
    });
  });

  it('shows empty state when no groups', async () => {
    mockFetchResponses({ data: [] });

    render(<GroupManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/no groups/i)).toBeInTheDocument();
    });
  });
});
