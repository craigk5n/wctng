import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

vi.mock('../../auth/auth-context', () => ({
  useAuth: () => ({ user: { login: 'admin' } }),
}));

import { ExportButton } from '../ExportButton';

describe('ExportButton', () => {
  it('renders export button', () => {
    render(<ExportButton />);
    expect(screen.getByText(/export/i)).toBeInTheDocument();
  });
});
