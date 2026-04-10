/**
 * Calculate the moon phase for a given date.
 *
 * Uses the synodic month (29.53059 days) with a known new-moon
 * reference (2000-01-06 18:14 UTC). Returns an index 0–7 and
 * the corresponding emoji.
 *
 * Phases: 0=new, 1=waxing crescent, 2=first quarter, 3=waxing gibbous,
 *         4=full, 5=waning gibbous, 6=last quarter, 7=waning crescent
 */

const SYNODIC_MONTH = 29.53058868;
// Known new moon: 2000-01-06 18:14 UTC
const KNOWN_NEW_MOON = Date.UTC(2000, 0, 6, 18, 14, 0) / 86400000;

const PHASE_EMOJIS = ['🌑', '🌒', '🌓', '🌔', '🌕', '🌖', '🌗', '🌘'] as const;
const PHASE_NAMES = [
  'New Moon',
  'Waxing Crescent',
  'First Quarter',
  'Waxing Gibbous',
  'Full Moon',
  'Waning Gibbous',
  'Last Quarter',
  'Waning Crescent',
] as const;

export interface MoonPhase {
  index: number;
  emoji: string;
  name: string;
}

export function getMoonPhase(date: Date): MoonPhase {
  // Normalize to noon UTC to avoid timezone edge effects
  const utcNoon =
    Date.UTC(date.getFullYear(), date.getMonth(), date.getDate(), 12, 0, 0) / 86400000;
  const daysSinceNewMoon = utcNoon - KNOWN_NEW_MOON;
  const phase = ((daysSinceNewMoon % SYNODIC_MONTH) + SYNODIC_MONTH) % SYNODIC_MONTH;
  const index = Math.floor((phase / SYNODIC_MONTH) * 8) % 8;

  return {
    index,
    emoji: PHASE_EMOJIS[index],
    name: PHASE_NAMES[index],
  };
}
