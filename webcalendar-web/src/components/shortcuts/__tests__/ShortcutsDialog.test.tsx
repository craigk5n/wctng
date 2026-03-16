import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ShortcutsDialog } from '../ShortcutsDialog';

describe('ShortcutsDialog', () => {
  it('renders when open', () => {
    render(<ShortcutsDialog open={true} onClose={() => {}} />);
    expect(screen.getByText(/keyboard shortcuts/i)).toBeInTheDocument();
  });

  it('does not render when closed', () => {
    render(<ShortcutsDialog open={false} onClose={() => {}} />);
    expect(screen.queryByText(/keyboard shortcuts/i)).not.toBeInTheDocument();
  });

  it('shows navigation shortcuts', () => {
    render(<ShortcutsDialog open={true} onClose={() => {}} />);
    expect(screen.getByText(/←/)).toBeInTheDocument();
    expect(screen.getByText(/→/)).toBeInTheDocument();
  });

  it('shows view switching shortcuts', () => {
    render(<ShortcutsDialog open={true} onClose={() => {}} />);
    expect(screen.getByText('Month view')).toBeInTheDocument();
    expect(screen.getByText('Week view')).toBeInTheDocument();
    expect(screen.getByText('Day view')).toBeInTheDocument();
  });

  it('shows event creation shortcut', () => {
    render(<ShortcutsDialog open={true} onClose={() => {}} />);
    expect(screen.getByText(/new event/i)).toBeInTheDocument();
  });

  it('calls onClose when close button clicked', async () => {
    const onClose = vi.fn();
    render(<ShortcutsDialog open={true} onClose={onClose} />);
    await userEvent.click(screen.getByRole('button', { name: /close/i }));
    expect(onClose).toHaveBeenCalled();
  });
});
