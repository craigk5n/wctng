import { describe, it, expect, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { extractTenantSlug, useTenant } from '../useTenant';

describe('extractTenantSlug', () => {
  const originalLocation = window.location;

  afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation, writable: true });
  });

  function setHostname(hostname: string) {
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, hostname },
      writable: true,
    });
  }

  it('returns null for localhost', () => {
    setHostname('localhost');
    expect(extractTenantSlug()).toBeNull();
  });

  it('returns null for IP addresses', () => {
    setHostname('192.168.0.100');
    expect(extractTenantSlug()).toBeNull();
  });

  it('returns null for bare domain (no subdomain)', () => {
    setHostname('webcalendar.com');
    expect(extractTenantSlug()).toBeNull();
  });

  it('extracts slug from subdomain', () => {
    setHostname('acme.webcalendar.com');
    expect(extractTenantSlug()).toBe('acme');
  });

  it('extracts slug from multi-level domain', () => {
    setHostname('globex.calendar.example.com');
    expect(extractTenantSlug()).toBe('globex');
  });

  it('returns null for www subdomain', () => {
    setHostname('www.webcalendar.com');
    expect(extractTenantSlug()).toBeNull();
  });

  it('returns null for admin subdomain', () => {
    setHostname('admin.webcalendar.com');
    expect(extractTenantSlug()).toBeNull();
  });

  it('returns null for control subdomain', () => {
    setHostname('control.webcalendar.com');
    expect(extractTenantSlug()).toBeNull();
  });
});

describe('useTenant', () => {
  it('returns null tenant for localhost', () => {
    Object.defineProperty(window, 'location', {
      value: { ...window.location, hostname: 'localhost' },
      writable: true,
    });

    const { result } = renderHook(() => useTenant());
    expect(result.current.tenant).toBeNull();
    expect(result.current.isLoading).toBe(false);
  });
});
