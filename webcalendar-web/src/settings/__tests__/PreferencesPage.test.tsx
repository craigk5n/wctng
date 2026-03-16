import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { AuthProvider } from '../../auth/AuthProvider';
import { PreferencesPage } from '../PreferencesPage';

describe('PreferencesPage', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders page title', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    render(
      <MemoryRouter>
        <AuthProvider><ToastProvider><PreferencesPage /></ToastProvider></AuthProvider>
      </MemoryRouter>,
    );
    expect(screen.getByRole('heading', { name: /preferences/i })).toBeInTheDocument();
  });

  it('shows default view selector', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    render(
      <MemoryRouter>
        <AuthProvider><ToastProvider><PreferencesPage /></ToastProvider></AuthProvider>
      </MemoryRouter>,
    );
    expect(screen.getByLabelText(/default view/i)).toBeInTheDocument();
  });

  it('has a save button', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    render(
      <MemoryRouter>
        <AuthProvider><ToastProvider><PreferencesPage /></ToastProvider></AuthProvider>
      </MemoryRouter>,
    );
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument();
  });
});
