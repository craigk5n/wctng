import { useState, useCallback } from 'react';
import { parseNaturalLanguage } from './naturalLanguageParser';
import type { EventFormData } from './EventDialog';

interface QuickAddInputProps {
  onParsed: (data: Partial<EventFormData> & { start_date_display?: string; start_time_display?: string }) => void;
}

export function QuickAddInput({ onParsed }: QuickAddInputProps) {
  const [text, setText] = useState('');

  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    if (!text.trim()) return;

    const parsed = parseNaturalLanguage(text.trim());

    const formData: Partial<EventFormData> & { start_date_display?: string; start_time_display?: string } = {
      title: parsed.title,
    };

    if (parsed.startDate) {
      formData.start_date = parsed.startDate.replace(/-/g, '');
      formData.start_date_display = parsed.startDate;
    }
    if (parsed.startTime) {
      formData.start_time = parsed.startTime.replace(/:/g, '') + '00';
      formData.start_time_display = parsed.startTime;
    }
    if (parsed.duration) {
      formData.duration = parsed.duration;
    }
    if (parsed.location) {
      formData.location = parsed.location;
    }
    if (parsed.participants.length > 0) {
      formData.participants = parsed.participants;
    }

    onParsed(formData);
    setText('');
  }, [text, onParsed]);

  return (
    <form onSubmit={handleSubmit} className="flex gap-1">
      <input
        type="text"
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder="Quick add: &quot;Lunch with Bob tomorrow at noon&quot;"
        className="flex h-9 w-64 rounded-md border border-input bg-background px-3 text-xs"
      />
      <button
        type="submit"
        disabled={!text.trim()}
        title="Parse and create event"
        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-input text-sm hover:bg-accent disabled:opacity-50"
      >
        ✨
      </button>
    </form>
  );
}
