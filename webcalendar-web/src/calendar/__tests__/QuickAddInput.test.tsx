import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QuickAddInput } from '../QuickAddInput';

describe('QuickAddInput', () => {
  beforeAll(async () => {
    // The parser reaches chrono-node through a dynamic import, kept out of the
    // main bundle since the vendor-chunk split. Whichever test submits first
    // pays for loading and transforming it, and under the full suite that cost
    // lands inside a waitFor window and overruns its one-second default. On its
    // own this file has always passed, which is why it only ever failed when
    // everything ran together. Pay the cost here, where nothing is timing it.
    await import('chrono-node');
  });

  it('renders input and button', () => {
    render(<QuickAddInput onParsed={vi.fn()} />);
    expect(screen.getByPlaceholderText(/quick add/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /parse/i })).toBeInTheDocument();
  });

  it('button is disabled when input is empty', () => {
    render(<QuickAddInput onParsed={vi.fn()} />);
    expect(screen.getByRole('button', { name: /parse/i })).toBeDisabled();
  });

  it('calls onParsed with parsed data on submit', async () => {
    const user = userEvent.setup();
    const onParsed = vi.fn();
    render(<QuickAddInput onParsed={onParsed} />);

    await user.type(screen.getByPlaceholderText(/quick add/i), 'Team meeting');
    await user.click(screen.getByRole('button', { name: /parse/i }));

    await waitFor(() => {
      expect(onParsed).toHaveBeenCalledOnce();
    });
    const parsed = onParsed.mock.calls[0][0];
    expect(parsed.title).toContain('Team meeting');
  });

  it('clears input after submit', async () => {
    const user = userEvent.setup();
    render(<QuickAddInput onParsed={vi.fn()} />);

    const input = screen.getByPlaceholderText(/quick add/i) as HTMLInputElement;
    await user.type(input, 'Lunch');
    await user.click(screen.getByRole('button', { name: /parse/i }));

    await waitFor(() => {
      expect(input.value).toBe('');
    });
  });
});
