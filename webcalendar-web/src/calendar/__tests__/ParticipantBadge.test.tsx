import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ParticipantBadge, statusLabel } from '../ParticipantBadge';

describe('ParticipantBadge', () => {
  it('renders login and accepted status', () => {
    render(<ParticipantBadge login="john" status="A" />);
    expect(screen.getByText('john')).toBeInTheDocument();
    expect(screen.getByText('Accepted')).toBeInTheDocument();
  });

  it('renders rejected status', () => {
    render(<ParticipantBadge login="jane" status="R" />);
    expect(screen.getByText('Rejected')).toBeInTheDocument();
  });

  it('renders waiting status', () => {
    render(<ParticipantBadge login="bob" status="W" />);
    expect(screen.getByText('Pending')).toBeInTheDocument();
  });
});

describe('statusLabel', () => {
  it('maps all status codes', () => {
    expect(statusLabel('A')).toBe('Accepted');
    expect(statusLabel('R')).toBe('Rejected');
    expect(statusLabel('W')).toBe('Pending');
    expect(statusLabel('C')).toBe('Completed');
    expect(statusLabel('P')).toBe('In Progress');
    expect(statusLabel('D')).toBe('Deleted');
    expect(statusLabel('X')).toBe('X');
  });
});
