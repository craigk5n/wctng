import { describe, it, expect, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';

// Use the real ToastProvider (not the global mock)
vi.unmock('../ToastProvider');
const { ToastProvider, useToast } = await import('../ToastProvider');

const actionFn = vi.fn();

function TestConsumer() {
  const { toast } = useToast();
  return (
    <div>
      <button onClick={() => toast({ title: 'Success', variant: 'success' })}>Show Success</button>
      <button onClick={() => toast({ title: 'Error occurred', variant: 'error' })}>Show Error</button>
      <button onClick={() => toast({ title: 'Info message' })}>Show Default</button>
      <button
        onClick={() =>
          toast({
            title: 'Deleted',
            variant: 'success',
            action: { label: 'Undo', onClick: actionFn },
            duration: 5000,
          })
        }
      >
        Show Undo
      </button>
    </div>
  );
}

function renderWithToast() {
  return render(
    <ToastProvider>
      <TestConsumer />
    </ToastProvider>,
  );
}

describe('Toast System', () => {
  it('shows a success toast when triggered', async () => {
    renderWithToast();

    await act(async () => {
      screen.getByText('Show Success').click();
    });

    expect(screen.getByText('Success')).toBeInTheDocument();
  });

  it('shows an error toast when triggered', async () => {
    renderWithToast();

    await act(async () => {
      screen.getByText('Show Error').click();
    });

    expect(screen.getByText('Error occurred')).toBeInTheDocument();
  });

  it('shows a default toast', async () => {
    renderWithToast();

    await act(async () => {
      screen.getByText('Show Default').click();
    });

    expect(screen.getByText('Info message')).toBeInTheDocument();
  });

  it('auto-dismisses toast after timeout', async () => {
    vi.useFakeTimers();
    renderWithToast();

    await act(async () => {
      screen.getByText('Show Success').click();
    });

    expect(screen.getByText('Success')).toBeInTheDocument();

    await act(async () => {
      vi.advanceTimersByTime(4000);
    });

    expect(screen.queryByText('Success')).not.toBeInTheDocument();

    vi.useRealTimers();
  });

  it('renders action button in toast and calls onClick', async () => {
    vi.useFakeTimers();
    actionFn.mockReset();

    function UndoConsumer() {
      const { toast: t } = useToast();
      return (
        <button
          onClick={() =>
            t({
              title: 'Item removed',
              action: { label: 'Undo', onClick: actionFn },
              duration: 10000,
            })
          }
        >
          Trigger
        </button>
      );
    }

    render(
      <ToastProvider>
        <UndoConsumer />
      </ToastProvider>,
    );

    await act(async () => {
      screen.getByText('Trigger').click();
    });

    expect(screen.getByText('Item removed')).toBeInTheDocument();
    const undoBtn = screen.getByRole('button', { name: /undo/i });
    expect(undoBtn).toBeInTheDocument();

    await act(async () => {
      undoBtn.click();
    });

    expect(actionFn).toHaveBeenCalledTimes(1);
    // Toast dismissed after action click
    expect(screen.queryByText('Item removed')).not.toBeInTheDocument();

    vi.useRealTimers();
  });

  it('respects custom duration', async () => {
    vi.useFakeTimers();
    renderWithToast();

    await act(async () => {
      screen.getByText('Show Undo').click();
    });

    expect(screen.getByText('Deleted')).toBeInTheDocument();

    // Default is 3000ms, but custom is 5000ms — should still be visible at 4000ms
    await act(async () => {
      vi.advanceTimersByTime(4000);
    });

    expect(screen.getByText('Deleted')).toBeInTheDocument();

    // At 5000ms it should be gone
    await act(async () => {
      vi.advanceTimersByTime(1500);
    });

    expect(screen.queryByText('Deleted')).not.toBeInTheDocument();

    vi.useRealTimers();
  });
});
