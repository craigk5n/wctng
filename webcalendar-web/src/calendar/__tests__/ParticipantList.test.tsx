import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ParticipantList } from '../ParticipantList';

describe('ParticipantList', () => {
  it('renders participants with status badges', () => {
    render(
      <ParticipantList
        participants={[
          { login: 'alice', status: 'A' },
          { login: 'bob', status: 'W' },
        ]}
      />,
    );

    expect(screen.getByText('alice')).toBeInTheDocument();
    expect(screen.getByText('bob')).toBeInTheDocument();
    expect(screen.getByText('Accepted')).toBeInTheDocument();
    expect(screen.getByText('Pending')).toBeInTheDocument();
  });

  it('shows empty message when no participants', () => {
    render(<ParticipantList participants={[]} />);
    expect(screen.getByText(/no participants/i)).toBeInTheDocument();
  });

  it('shows remove button when editable', async () => {
    const onRemove = vi.fn();
    render(
      <ParticipantList
        participants={[{ login: 'alice', status: 'A' }]}
        editable
        onRemove={onRemove}
      />,
    );

    const removeBtn = screen.getByRole('button', { name: /remove alice/i });
    await userEvent.click(removeBtn);
    expect(onRemove).toHaveBeenCalledWith('alice');
  });

  it('hides remove button when not editable', () => {
    render(
      <ParticipantList
        participants={[{ login: 'alice', status: 'A' }]}
      />,
    );

    expect(screen.queryByRole('button', { name: /remove/i })).not.toBeInTheDocument();
  });
});
