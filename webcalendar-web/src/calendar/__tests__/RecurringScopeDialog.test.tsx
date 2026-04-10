import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RecurringScopeDialog } from '../RecurringScopeDialog';

describe('RecurringScopeDialog', () => {
  const defaultProps = {
    open: true,
    mode: 'edit' as const,
    eventTitle: 'Weekly Standup',
    onSelect: vi.fn(),
    onCancel: vi.fn(),
  };

  it('does not render when closed', () => {
    render(<RecurringScopeDialog {...defaultProps} open={false} />);
    expect(screen.queryByText(/Weekly Standup/)).not.toBeInTheDocument();
  });

  it('renders edit mode heading', () => {
    render(<RecurringScopeDialog {...defaultProps} mode="edit" />);
    expect(screen.getByRole('heading', { name: /edit recurring/i })).toBeInTheDocument();
    expect(screen.getByText(/Weekly Standup/)).toBeInTheDocument();
  });

  it('renders delete mode heading', () => {
    render(<RecurringScopeDialog {...defaultProps} mode="delete" />);
    expect(screen.getByRole('heading', { name: /cancel recurring/i })).toBeInTheDocument();
  });

  it('calls onSelect with "all" when entire series button clicked', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(<RecurringScopeDialog {...defaultProps} onSelect={onSelect} />);

    await user.click(screen.getByRole('button', { name: /edit entire series/i }));
    expect(onSelect).toHaveBeenCalledWith('all');
  });

  it('calls onCancel when go back button clicked', async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();
    render(<RecurringScopeDialog {...defaultProps} onCancel={onCancel} />);

    await user.click(screen.getByRole('button', { name: /go back/i }));
    expect(onCancel).toHaveBeenCalled();
  });

  it('disables date-dependent buttons when no date selected', () => {
    render(<RecurringScopeDialog {...defaultProps} mode="edit" />);
    const editFromBtn = screen.getByRole('button', { name: /edit from this date/i });
    expect(editFromBtn).toBeDisabled();
  });

  it('enables date-dependent buttons when date is selected', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(<RecurringScopeDialog {...defaultProps} onSelect={onSelect} />);

    const dateInput = screen.getByDisplayValue('');
    await user.type(dateInput, '2026-05-01');

    const editFromBtn = screen.getByRole('button', { name: /edit from this date/i });
    expect(editFromBtn).toBeEnabled();

    await user.click(editFromBtn);
    expect(onSelect).toHaveBeenCalledWith('future', '20260501');
  });

  it('shows "Cancel this date" button in delete mode', () => {
    render(<RecurringScopeDialog {...defaultProps} mode="delete" />);
    expect(screen.getByRole('button', { name: /cancel this date/i })).toBeInTheDocument();
  });

  it('does not show "Cancel this date" in edit mode', () => {
    render(<RecurringScopeDialog {...defaultProps} mode="edit" />);
    expect(screen.queryByRole('button', { name: /cancel this date/i })).not.toBeInTheDocument();
  });
});
