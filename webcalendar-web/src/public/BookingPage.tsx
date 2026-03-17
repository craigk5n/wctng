import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { getApiBaseUrl } from '../api/client';

interface TimeSlot {
  start: string;
  end: string;
}

interface AvailabilityData {
  date: string;
  username: string;
  slots: TimeSlot[];
}

export function BookingPage() {
  const { username } = useParams<{ username: string }>();
  const [selectedDate, setSelectedDate] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() + 1); // Default to tomorrow
    return d.toISOString().slice(0, 10);
  });
  const [slots, setSlots] = useState<TimeSlot[]>([]);
  const [selectedSlot, setSelectedSlot] = useState<TimeSlot | null>(null);
  const [loading, setLoading] = useState(false);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [description, setDescription] = useState('');
  const [booking, setBooking] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchSlots = useCallback(async () => {
    if (!username || !selectedDate) return;
    setLoading(true);
    setError(null);

    const baseUrl = getApiBaseUrl();
    try {
      const res = await fetch(`${baseUrl}/public/availability/${username}?date=${selectedDate}`);
      const body = await res.json();
      if (res.ok && body.data) {
        setSlots((body.data as AvailabilityData).slots);
      } else {
        setSlots([]);
      }
    } catch {
      setSlots([]);
    } finally {
      setLoading(false);
    }
  }, [username, selectedDate]);

  useEffect(() => {
    void fetchSlots();
  }, [fetchSlots]);

  const handleBook = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedSlot || !username) return;

    setBooking(true);
    setError(null);

    const baseUrl = getApiBaseUrl();
    try {
      const res = await fetch(`${baseUrl}/public/book/${username}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name,
          email,
          date: selectedDate,
          time: selectedSlot.start,
          duration: 30,
          description,
        }),
      });

      const body = await res.json();
      if (res.ok) {
        setConfirmed(true);
      } else {
        setError(body?.error?.message ?? 'Booking failed');
      }
    } catch {
      setError('Booking failed');
    } finally {
      setBooking(false);
    }
  };

  if (confirmed) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <div className="mx-4 max-w-md rounded-lg bg-card p-8 text-center shadow-lg">
          <h2 className="text-2xl font-bold text-green-600">Booking Confirmed</h2>
          <p className="mt-3 text-muted-foreground">
            Your appointment with <strong>{username}</strong> on{' '}
            <strong>{selectedDate}</strong> at <strong>{selectedSlot?.start}</strong> has been submitted.
          </p>
          <p className="mt-2 text-sm text-muted-foreground">
            A confirmation will be sent to {email}.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background">
      <header className="border-b bg-card px-4 py-3">
        <div className="mx-auto max-w-2xl">
          <h1 className="text-xl font-semibold text-foreground">
            Book time with {username}
          </h1>
        </div>
      </header>

      <main className="mx-auto max-w-2xl p-4">
        <div className="grid gap-6 md:grid-cols-2">
          {/* Left: Date + Slots */}
          <div className="space-y-4">
            <div className="space-y-2">
              <label htmlFor="booking-date" className="text-sm font-medium">Select a date</label>
              <input
                id="booking-date"
                type="date"
                value={selectedDate}
                onChange={(e) => { setSelectedDate(e.target.value); setSelectedSlot(null); }}
                min={new Date().toISOString().slice(0, 10)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>

            <div className="space-y-2">
              <span className="text-sm font-medium">Available times</span>
              {loading && <p className="text-xs text-muted-foreground">Loading...</p>}

              {!loading && slots.length === 0 && (
                <p className="text-xs text-muted-foreground">No available slots for this date.</p>
              )}

              {!loading && slots.length > 0 && (
                <div className="grid grid-cols-3 gap-2">
                  {slots.map((slot) => (
                    <button
                      key={slot.start}
                      type="button"
                      onClick={() => setSelectedSlot(slot)}
                      className={`rounded-md border px-3 py-2 text-sm font-medium transition-colors ${
                        selectedSlot?.start === slot.start
                          ? 'border-primary bg-primary text-primary-foreground'
                          : 'border-border hover:border-primary hover:bg-primary/5'
                      }`}
                    >
                      {slot.start}
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* Right: Booking form */}
          <form onSubmit={handleBook} className="space-y-4">
            <div className="space-y-2">
              <label htmlFor="book-name" className="text-sm font-medium">Your Name</label>
              <input
                id="book-name"
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Your name"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>

            <div className="space-y-2">
              <label htmlFor="book-email" className="text-sm font-medium">Email</label>
              <input
                id="book-email"
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="your@email.com"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>

            <div className="space-y-2">
              <label htmlFor="book-desc" className="text-sm font-medium">Description (optional)</label>
              <textarea
                id="book-desc"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                rows={3}
                placeholder="What is this meeting about?"
                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              />
            </div>

            {selectedSlot && (
              <p className="text-sm text-muted-foreground">
                Selected: <strong>{selectedDate}</strong> at <strong>{selectedSlot.start}</strong> – {selectedSlot.end}
              </p>
            )}

            {error && (
              <p className="text-sm text-destructive">{error}</p>
            )}

            <button
              type="submit"
              disabled={!selectedSlot || booking || !name || !email}
              className="w-full rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
            >
              {booking ? 'Booking...' : 'Confirm Booking'}
            </button>
          </form>
        </div>
      </main>
    </div>
  );
}
