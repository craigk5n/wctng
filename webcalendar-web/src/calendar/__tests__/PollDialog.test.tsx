import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PollDialog } from '../PollDialog';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

describe('PollDialog', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders dialog with title field', () => {
    render(<PollDialog open={true} onClose={vi.fn()} />);

    expect(screen.getByRole('heading', { name: /schedule meeting/i })).toBeInTheDocument();
    expect(screen.getByLabelText(/title/i)).toBeInTheDocument();
  });

  it('does not render when closed', () => {
    const { container } = render(<PollDialog open={false} onClose={vi.fn()} />);
    expect(container.firstChild).toBeNull();
  });

  it('has add time slot button', () => {
    render(<PollDialog open={true} onClose={vi.fn()} />);
    expect(screen.getByRole('button', { name: /add time/i })).toBeInTheDocument();
  });

  it('shows validation error when less than 2 options', async () => {
    const user = userEvent.setup();
    render(<PollDialog open={true} onClose={vi.fn()} />);

    await user.type(screen.getByLabelText(/title/i), 'Team Meeting');

    // Try to create without enough options
    await user.click(screen.getByRole('button', { name: /create poll/i }));

    expect(screen.getByText(/at least 2/i)).toBeInTheDocument();
  });

  it('shows cancel button', () => {
    render(<PollDialog open={true} onClose={vi.fn()} />);
    expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
  });
});
