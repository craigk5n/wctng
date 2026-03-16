import { describe, it, expect, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { ToastProvider, useToast } from '../ToastProvider';

function TestConsumer() {
  const { toast } = useToast();
  return (
    <div>
      <button onClick={() => toast({ title: 'Success', variant: 'success' })}>Show Success</button>
      <button onClick={() => toast({ title: 'Error occurred', variant: 'error' })}>Show Error</button>
      <button onClick={() => toast({ title: 'Info message' })}>Show Default</button>
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
});
