import { useCallback, useEffect, useState } from 'react';

import { DAYS, parseRrule, type EndType } from './rrule';

interface RecurrenceEditorProps {
  value: string; // RRULE string or empty
  onChange: (rrule: string) => void;
}

type Preset = 'none' | 'daily' | 'weekly' | 'monthly' | 'yearly' | 'custom';


function detectPreset(rrule: string): Preset {
  if (!rrule) return 'none';
  const upper = rrule.toUpperCase();
  if (upper === 'FREQ=DAILY') return 'daily';
  if (upper === 'FREQ=WEEKLY') return 'weekly';
  if (upper === 'FREQ=MONTHLY') return 'monthly';
  if (upper === 'FREQ=YEARLY') return 'yearly';
  return 'custom';
}



export function RecurrenceEditor({ value, onChange }: RecurrenceEditorProps) {
  const [preset, setPreset] = useState<Preset>(detectPreset(value));
  const [freq, setFreq] = useState('WEEKLY');
  const [interval, setInterval] = useState(1);
  const [byDay, setByDay] = useState<string[]>([]);
  const [endType, setEndType] = useState<EndType>('never');
  const [count, setCount] = useState(10);
  const [until, setUntil] = useState('');

  // Parse initial value for custom mode
  useEffect(() => {
    if (value && detectPreset(value) === 'custom') {
      const parsed = parseRrule(value);
      setFreq(parsed.freq);
      setInterval(parsed.interval);
      setByDay(parsed.byDay);
      setEndType(parsed.endType);
      setCount(parsed.count);
      setUntil(parsed.until);
    }
    // Intentionally mount-only: this seeds the custom-mode fields from the
    // incoming RRULE once. Adding `value` would re-parse on every change this
    // component itself emits, overwriting what the user is editing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const buildCustomRrule = useCallback(() => {
    const parts = [`FREQ=${freq}`];
    if (interval > 1) parts.push(`INTERVAL=${interval}`);
    if (byDay.length > 0 && freq === 'WEEKLY') parts.push(`BYDAY=${byDay.join(',')}`);
    if (endType === 'count') parts.push(`COUNT=${count}`);
    if (endType === 'until' && until) parts.push(`UNTIL=${until.replace(/-/g, '')}`);
    onChange(parts.join(';'));
  }, [freq, interval, byDay, endType, count, until, onChange]);

  const handlePresetChange = useCallback((newPreset: Preset) => {
    setPreset(newPreset);
    switch (newPreset) {
      case 'none': onChange(''); break;
      case 'daily': onChange('FREQ=DAILY'); break;
      case 'weekly': onChange('FREQ=WEEKLY'); break;
      case 'monthly': onChange('FREQ=MONTHLY'); break;
      case 'yearly': onChange('FREQ=YEARLY'); break;
      case 'custom': buildCustomRrule(); break;
    }
  }, [onChange, buildCustomRrule]);

  // Rebuild RRULE when custom fields change
  useEffect(() => {
    if (preset === 'custom') {
      buildCustomRrule();
    }
  }, [preset, freq, interval, byDay, endType, count, until, buildCustomRrule]);

  const toggleDay = (day: string) => {
    setByDay((prev) =>
      prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day],
    );
  };

  return (
    <div className="space-y-3">
      <div className="space-y-1">
        <label htmlFor="recurrence-preset" className="text-sm font-medium">Repeats</label>
        <select
          id="recurrence-preset"
          value={preset}
          onChange={(e) => handlePresetChange(e.target.value as Preset)}
          className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        >
          <option value="none">Does not repeat</option>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
          <option value="yearly">Yearly</option>
          <option value="custom">Custom...</option>
        </select>
      </div>

      {preset === 'custom' && (
        <div className="space-y-3 rounded-md border bg-muted/30 p-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <label htmlFor="recurrence-freq" className="text-xs font-medium">Frequency</label>
              <select
                id="recurrence-freq"
                value={freq}
                onChange={(e) => setFreq(e.target.value)}
                className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
              >
                <option value="DAILY">Daily</option>
                <option value="WEEKLY">Weekly</option>
                <option value="MONTHLY">Monthly</option>
                <option value="YEARLY">Yearly</option>
              </select>
            </div>
            <div className="space-y-1">
              <label htmlFor="recurrence-interval" className="text-xs font-medium">Interval</label>
              <input
                id="recurrence-interval"
                type="number"
                min={1}
                max={99}
                value={interval}
                onChange={(e) => setInterval(parseInt(e.target.value, 10) || 1)}
                className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
              />
            </div>
          </div>

          {freq === 'WEEKLY' && (
            <div className="space-y-1">
              <label className="text-xs font-medium">On days</label>
              <div className="flex gap-1">
                {DAYS.map((d) => (
                  <button
                    key={d.key}
                    type="button"
                    onClick={() => toggleDay(d.key)}
                    className={`rounded px-2 py-1 text-xs font-medium transition-colors ${
                      byDay.includes(d.key)
                        ? 'bg-primary text-primary-foreground'
                        : 'border border-border hover:bg-accent'
                    }`}
                  >
                    {d.label}
                  </button>
                ))}
              </div>
            </div>
          )}

          <div className="space-y-1">
            <label htmlFor="recurrence-end" className="text-xs font-medium">End</label>
            <select
              id="recurrence-end"
              value={endType}
              onChange={(e) => setEndType(e.target.value as EndType)}
              className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
            >
              <option value="never">Never</option>
              <option value="count">After N occurrences</option>
              <option value="until">On date</option>
            </select>
          </div>

          {endType === 'count' && (
            <input
              type="number"
              min={1}
              value={count}
              onChange={(e) => setCount(parseInt(e.target.value, 10) || 1)}
              className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
              aria-label="Number of occurrences"
              placeholder="Number of occurrences"
            />
          )}

          {endType === 'until' && (
            <input
              type="date"
              value={until}
              onChange={(e) => setUntil(e.target.value)}
              className="flex h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
              aria-label="End date for recurrence"
            />
          )}

          {/* Show generated RRULE */}
          <p className="text-[10px] font-mono text-muted-foreground">{value}</p>
        </div>
      )}
    </div>
  );
}

/**
 * Converts an RRULE string to a human-readable description.
 */
