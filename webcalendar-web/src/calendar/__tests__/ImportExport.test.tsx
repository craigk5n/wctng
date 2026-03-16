import { describe, it, expect, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { AuthProvider } from '../../auth/AuthProvider';
import { ImportDialog } from '../ImportDialog';
import { ExportButton } from '../ExportButton';

describe('ExportButton', () => {
  it('renders export button', () => {
    render(<ExportButton />);
    expect(screen.getByRole('button', { name: /export/i })).toBeInTheDocument();
  });
});

describe('ImportDialog', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders when open', () => {
    render(
      <MemoryRouter>
        <AuthProvider>
          <ToastProvider>
            <ImportDialog open={true} onClose={() => {}} onImported={() => {}} />
          </ToastProvider>
        </AuthProvider>
      </MemoryRouter>,
    );
    expect(screen.getByRole('heading', { name: /import/i })).toBeInTheDocument();
  });

  it('does not render when closed', () => {
    render(
      <MemoryRouter>
        <AuthProvider>
          <ToastProvider>
            <ImportDialog open={false} onClose={() => {}} onImported={() => {}} />
          </ToastProvider>
        </AuthProvider>
      </MemoryRouter>,
    );
    expect(screen.queryByText(/drop.*file|select.*file/i)).not.toBeInTheDocument();
  });

  it('has a file input', () => {
    render(
      <MemoryRouter>
        <AuthProvider>
          <ToastProvider>
            <ImportDialog open={true} onClose={() => {}} onImported={() => {}} />
          </ToastProvider>
        </AuthProvider>
      </MemoryRouter>,
    );
    const input = document.querySelector('input[type="file"]');
    expect(input).toBeInTheDocument();
  });

  it('has cancel button', () => {
    render(
      <MemoryRouter>
        <AuthProvider>
          <ToastProvider>
            <ImportDialog open={true} onClose={() => {}} onImported={() => {}} />
          </ToastProvider>
        </AuthProvider>
      </MemoryRouter>,
    );
    expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
  });
});
