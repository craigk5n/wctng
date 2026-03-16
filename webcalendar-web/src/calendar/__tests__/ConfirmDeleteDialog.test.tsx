import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConfirmDeleteDialog } from '../ConfirmDeleteDialog';

describe('ConfirmDeleteDialog', () => {
  const defaultProps = {
    open: true,
    eventTitle: 'Team Meeting',
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    isDeleting: false,
  };

  it('shows confirmation with event title', () => {
    render(<ConfirmDeleteDialog {...defaultProps} />);

    expect(screen.getByRole('heading', { name: /delete event/i })).toBeInTheDocument();
    expect(screen.getByText(/team meeting/i)).toBeInTheDocument();
  });

  it('has confirm and cancel buttons', () => {
    render(<ConfirmDeleteDialog {...defaultProps} />);

    expect(screen.getByRole('button', { name: /delete|confirm/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
  });

  it('calls onConfirm when confirm button clicked', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(<ConfirmDeleteDialog {...defaultProps} onConfirm={onConfirm} />);

    await user.click(screen.getByRole('button', { name: /delete|confirm/i }));
    expect(onConfirm).toHaveBeenCalled();
  });

  it('calls onCancel when cancel button clicked', async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();
    render(<ConfirmDeleteDialog {...defaultProps} onCancel={onCancel} />);

    await user.click(screen.getByRole('button', { name: /cancel/i }));
    expect(onCancel).toHaveBeenCalled();
  });

  it('does not render when open is false', () => {
    render(<ConfirmDeleteDialog {...defaultProps} open={false} />);
    expect(screen.queryByText(/team meeting/i)).not.toBeInTheDocument();
  });

  it('disables confirm button while deleting', () => {
    render(<ConfirmDeleteDialog {...defaultProps} isDeleting={true} />);

    const confirmBtn = screen.getByRole('button', { name: /delet/i });
    expect(confirmBtn).toBeDisabled();
  });

  it('shows deleting state text', () => {
    render(<ConfirmDeleteDialog {...defaultProps} isDeleting={true} />);
    expect(screen.getByText(/deleting/i)).toBeInTheDocument();
  });
});
