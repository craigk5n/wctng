import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ExtParticipantInput, type ExtParticipant } from '../ExtParticipantInput';

function setup(initial: ExtParticipant[] = []) {
  const onChange = vi.fn();
  const utils = render(<ExtParticipantInput value={initial} onChange={onChange} />);
  return { onChange, ...utils };
}

describe('ExtParticipantInput', () => {
  it('renders the name and email inputs', () => {
    setup();
    expect(screen.getByLabelText(/name/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Add/i })).toBeInTheDocument();
  });

  it('adds a participant with just a name', async () => {
    const user = userEvent.setup();
    const { onChange } = setup();

    await user.type(screen.getByLabelText(/name/i), 'Bob Guest');
    await user.click(screen.getByRole('button', { name: /Add/i }));

    expect(onChange).toHaveBeenCalledWith([{ name: 'Bob Guest', email: null }]);
  });

  it('adds a participant with name and email', async () => {
    const user = userEvent.setup();
    const { onChange } = setup();

    await user.type(screen.getByLabelText(/name/i), 'Alice Vendor');
    await user.type(screen.getByLabelText(/email/i), 'alice@vendor.com');
    await user.click(screen.getByRole('button', { name: /Add/i }));

    expect(onChange).toHaveBeenCalledWith([
      { name: 'Alice Vendor', email: 'alice@vendor.com' },
    ]);
  });

  it('trims whitespace', async () => {
    const user = userEvent.setup();
    const { onChange } = setup();

    await user.type(screen.getByLabelText(/name/i), '  Bob  ');
    await user.type(screen.getByLabelText(/email/i), '  bob@ex.com  ');
    await user.click(screen.getByRole('button', { name: /Add/i }));

    expect(onChange).toHaveBeenCalledWith([{ name: 'Bob', email: 'bob@ex.com' }]);
  });

  it('shows an error when name is blank', async () => {
    const user = userEvent.setup();
    const { onChange } = setup();

    await user.click(screen.getByRole('button', { name: /Add/i }));

    expect(screen.getByText(/name is required/i)).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('shows an error on invalid email', async () => {
    const user = userEvent.setup();
    const { onChange } = setup();

    await user.type(screen.getByLabelText(/name/i), 'X');
    await user.type(screen.getByLabelText(/email/i), 'not-an-email');
    await user.click(screen.getByRole('button', { name: /Add/i }));

    expect(screen.getByText(/invalid email/i)).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('prevents duplicates by name', async () => {
    const user = userEvent.setup();
    const { onChange } = setup([{ name: 'Bob', email: null }]);

    await user.type(screen.getByLabelText(/name/i), 'Bob');
    await user.click(screen.getByRole('button', { name: /Add/i }));

    expect(screen.getByText(/already added/i)).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('submits on Enter key in name field', async () => {
    const user = userEvent.setup();
    const { onChange } = setup();

    const nameInput = screen.getByLabelText(/name/i);
    await user.type(nameInput, 'Alice{Enter}');

    expect(onChange).toHaveBeenCalledWith([{ name: 'Alice', email: null }]);
  });

  it('renders existing participants as chips', () => {
    setup([
      { name: 'Bob', email: 'bob@ex.com' },
      { name: 'Alice', email: null },
    ]);

    expect(screen.getByText(/Bob/)).toBeInTheDocument();
    expect(screen.getByText(/<bob@ex.com>/)).toBeInTheDocument();
    expect(screen.getByText(/Alice/)).toBeInTheDocument();
  });

  it('removes a participant when × clicked', async () => {
    const user = userEvent.setup();
    const { onChange } = setup([
      { name: 'Bob', email: 'bob@ex.com' },
      { name: 'Alice', email: null },
    ]);

    await user.click(screen.getByRole('button', { name: /Remove Bob/i }));

    expect(onChange).toHaveBeenCalledWith([{ name: 'Alice', email: null }]);
  });
});
