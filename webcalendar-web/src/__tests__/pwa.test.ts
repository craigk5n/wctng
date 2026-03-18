import { describe, it, expect } from 'vitest';
import manifestJson from '../../public/manifest.json';

describe('PWA', () => {
  it('manifest has correct app name', () => {
    expect(manifestJson.name).toBe('WebCalendar');
    expect(manifestJson.short_name).toBe('WebCal');
  });

  it('manifest has standalone display', () => {
    expect(manifestJson.display).toBe('standalone');
  });

  it('manifest has start_url', () => {
    expect(manifestJson.start_url).toBe('/');
  });

  it('manifest has icons', () => {
    expect(manifestJson.icons).toHaveLength(2);
  });
});
