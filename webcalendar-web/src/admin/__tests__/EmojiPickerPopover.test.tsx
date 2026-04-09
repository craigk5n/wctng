import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { EmojiPickerPopover } from '../EmojiPickerPopover';

// The inner picker is lazy-loaded and pulls in frimousse; replace it
// with a deterministic stub so these tests stay fast and hermetic.
vi.mock('../EmojiPickerInner', () => ({
  EmojiPickerInner: ({ onSelect }: { onSelect: (e: string) => void }) => (
    <button type="button" onClick={() => onSelect('🎂')}>
      pick-cake
    </button>
  ),
}));

describe('EmojiPickerPopover', () => {
  it('shows a default placeholder when no emoji is set', () => {
    const onChange = vi.fn();
    render(<EmojiPickerPopover value={null} onChange={onChange} />);
    expect(screen.getByRole('button', { name: /pick an emoji/i })).toBeInTheDocument();
  });

  it('shows the selected emoji', () => {
    render(<EmojiPickerPopover value="🎂" onChange={vi.fn()} />);
    expect(screen.getByRole('button', { name: /change emoji/i })).toHaveTextContent('🎂');
  });

  it('opens the picker on click and reports selection', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<EmojiPickerPopover value={null} onChange={onChange} />);

    await user.click(screen.getByRole('button', { name: /pick an emoji/i }));

    const dialog = await screen.findByRole('dialog', { name: /emoji picker/i });
    expect(dialog).toBeInTheDocument();

    await user.click(await screen.findByRole('button', { name: 'pick-cake' }));
    expect(onChange).toHaveBeenCalledWith('🎂');
    // Dialog closes after selection
    expect(screen.queryByRole('dialog', { name: /emoji picker/i })).not.toBeInTheDocument();
  });

  it('clears the emoji when the × button is clicked', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<EmojiPickerPopover value="🎂" onChange={onChange} />);

    await user.click(screen.getByRole('button', { name: /clear emoji/i }));
    expect(onChange).toHaveBeenCalledWith(null);
  });

  it('does not render the clear button when there is nothing to clear', () => {
    render(<EmojiPickerPopover value={null} onChange={vi.fn()} />);
    expect(screen.queryByRole('button', { name: /clear emoji/i })).not.toBeInTheDocument();
  });

  it('toggle button aria-expanded reflects popover state', async () => {
    const user = userEvent.setup();
    render(<EmojiPickerPopover value={null} onChange={vi.fn()} />);

    const toggle = screen.getByRole('button', { name: /pick an emoji/i });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');

    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
  });
});
