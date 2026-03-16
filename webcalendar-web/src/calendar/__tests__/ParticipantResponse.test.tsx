import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ParticipantResponse } from '../ParticipantResponse';

describe('ParticipantResponse', () => {
  it('shows Accept/Reject buttons for pending participant', () => {
    render(<ParticipantResponse status="W" onAccept={() => {}} onReject={() => {}} />);

    expect(screen.getByRole('button', { name: /accept/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /reject|decline/i })).toBeInTheDocument();
  });

  it('calls onAccept when Accept clicked', async () => {
    const onAccept = vi.fn();
    render(<ParticipantResponse status="W" onAccept={onAccept} onReject={() => {}} />);

    await userEvent.click(screen.getByRole('button', { name: /accept/i }));
    expect(onAccept).toHaveBeenCalled();
  });

  it('calls onReject when Decline clicked', async () => {
    const onReject = vi.fn();
    render(<ParticipantResponse status="W" onAccept={() => {}} onReject={onReject} />);

    await userEvent.click(screen.getByRole('button', { name: /reject|decline/i }));
    expect(onReject).toHaveBeenCalled();
  });

  it('shows current status with change options for already-responded', () => {
    render(<ParticipantResponse status="A" onAccept={() => {}} onReject={() => {}} />);

    expect(screen.getByText(/accepted/i)).toBeInTheDocument();
    // Should still show option to change
    expect(screen.getByRole('button', { name: /decline/i })).toBeInTheDocument();
  });

  it('shows rejected status with accept option', () => {
    render(<ParticipantResponse status="R" onAccept={() => {}} onReject={() => {}} />);

    expect(screen.getByText(/rejected|declined/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /accept/i })).toBeInTheDocument();
  });

  it('disables buttons when loading', () => {
    render(<ParticipantResponse status="W" onAccept={() => {}} onReject={() => {}} isLoading />);

    expect(screen.getByRole('button', { name: /accept/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /decline/i })).toBeDisabled();
  });
});
