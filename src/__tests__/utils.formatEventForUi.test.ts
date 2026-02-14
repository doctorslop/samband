import { formatEventForUi } from '@/lib/utils';
import { EventWithMetadata } from '@/types';

// Mock better-sqlite3 to avoid native module issues in tests
jest.mock('better-sqlite3', () => {
  return jest.fn(() => ({
    pragma: jest.fn(),
    exec: jest.fn(),
    prepare: jest.fn(() => ({
      run: jest.fn(),
      get: jest.fn(),
      all: jest.fn(() => []),
    })),
  }));
});

// The runtime database can return null for optional string fields even though
// the TypeScript interface declares them as `string`.  We use this relaxed
// type so the test data matches real-world DB rows without noisy casts.
type TestEvent = Omit<EventWithMetadata, 'event_time' | 'publish_time' | 'last_updated'> & {
  event_time: string | null;
  publish_time: string | null;
  last_updated: string | null;
};

function createEvent(overrides: Partial<TestEvent> = {}): EventWithMetadata {
  const base: TestEvent = {
    id: 1,
    datetime: '2024-06-15T14:30:00Z',
    event_time: '2024-06-15T14:30:00Z',
    publish_time: '2024-06-15T14:00:00Z',
    last_updated: null,
    name: '15 juni 14:30, Trafikolycka, Stockholm',
    summary: 'En trafikolycka har inträffat.',
    url: 'https://polisen.se/aktuellt/handelser/2024/juni/15/trafikolycka-stockholm/',
    type: 'Trafikolycka',
    location: { name: 'Stockholm', gps: '59.3293,18.0686' },
    was_updated: false,
    ...overrides,
  };
  // Cast to EventWithMetadata — the runtime code handles null gracefully.
  return base as unknown as EventWithMetadata;
}

describe('formatEventForUi', () => {
  it('formats a standard event correctly', () => {
    const event = createEvent();
    const result = formatEventForUi(event);

    expect(result.id).toBe(1);
    expect(result.type).toBe('Trafikolycka');
    expect(result.location).toBe('Stockholm');
    expect(result.gps).toBe('59.3293,18.0686');
    expect(result.name).toBe('15 juni 14:30, Trafikolycka, Stockholm');
    expect(result.summary).toBe('En trafikolycka har inträffat.');
    expect(result.url).toContain('polisen.se');
  });

  it('uses event_time for date formatting when available', () => {
    const event = createEvent({ event_time: '2024-06-15T10:00:00Z' });
    const result = formatEventForUi(event);

    expect(result.date.iso).toBe('2024-06-15T10:00:00.000Z');
    expect(result.date.month).toBe('Jun');
  });

  it('falls back to datetime when event_time is null', () => {
    const event = createEvent({ event_time: null, datetime: '2024-03-01T08:00:00Z' });
    const result = formatEventForUi(event);

    expect(result.date.iso).toBe('2024-03-01T08:00:00.000Z');
    expect(result.date.month).toBe('Mar');
  });

  it('falls back to current time when both event_time and datetime are null', () => {
    const before = new Date();
    const event = createEvent({ event_time: null, datetime: null as unknown as string });
    const result = formatEventForUi(event);
    const after = new Date();

    const resultDate = new Date(result.date.iso);
    expect(resultDate.getTime()).toBeGreaterThanOrEqual(before.getTime() - 1000);
    expect(resultDate.getTime()).toBeLessThanOrEqual(after.getTime() + 1000);
  });

  it('handles invalid date strings gracefully', () => {
    const event = createEvent({ event_time: 'not-a-date', datetime: 'also-not-a-date' });
    const result = formatEventForUi(event);

    // Should fall back to now
    expect(result.date.iso).toBeTruthy();
    expect(new Date(result.date.iso).getTime()).not.toBeNaN();
  });

  it('defaults type to Okänd when missing', () => {
    const event = createEvent({ type: '' });
    const result = formatEventForUi(event);

    expect(result.type).toBe('Okänd');
  });

  it('includes color and icon from type styling', () => {
    const event = createEvent({ type: 'Trafikolycka' });
    const result = formatEventForUi(event);

    expect(result.color).toBeTruthy();
    expect(result.icon).toBeTruthy();
  });

  it('handles missing location gracefully', () => {
    const event = createEvent({
      location: undefined as unknown as { name: string; gps: string },
    });
    const result = formatEventForUi(event);

    expect(result.location).toBe('');
    expect(result.gps).toBe('');
  });

  it('formats the date components correctly', () => {
    const event = createEvent({ event_time: '2024-01-05T09:07:00Z' });
    const result = formatEventForUi(event);

    expect(result.date.day).toBe('05');
    expect(result.date.month).toBe('Jan');
    // Time depends on timezone, but should be formatted as HH:MM
    expect(result.date.time).toMatch(/^\d{2}:\d{2}$/);
  });

  it('includes relative time string', () => {
    const event = createEvent();
    const result = formatEventForUi(event);

    // Event is from the past, so relative time should contain "sedan" or be "Just nu"
    expect(result.date.relative).toBeTruthy();
  });

  it('marks wasUpdated correctly', () => {
    const updated = createEvent({ was_updated: true });
    const notUpdated = createEvent({ was_updated: false });

    expect(formatEventForUi(updated).wasUpdated).toBe(true);
    expect(formatEventForUi(notUpdated).wasUpdated).toBe(false);
  });

  it('formats last_updated as a readable string', () => {
    const event = createEvent({ last_updated: '2024-06-15T16:45:00Z' });
    const result = formatEventForUi(event);

    expect(result.updated).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
  });

  it('uses publish_time as fallback for updated when last_updated is null', () => {
    const event = createEvent({ last_updated: null, publish_time: '2024-06-15T14:00:00Z' });
    const result = formatEventForUi(event);

    expect(result.updated).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
  });

  it('returns empty string for updated when both last_updated and publish_time are null', () => {
    const event = createEvent({ last_updated: null, publish_time: null });
    const result = formatEventForUi(event);

    expect(result.updated).toBe('');
  });

  it('handles null id', () => {
    const event = createEvent({ id: null as unknown as number });
    const result = formatEventForUi(event);

    expect(result.id).toBeNull();
  });

  it('handles empty name and summary', () => {
    const event = createEvent({ name: '', summary: '' });
    const result = formatEventForUi(event);

    expect(result.name).toBe('');
    expect(result.summary).toBe('');
  });
});
