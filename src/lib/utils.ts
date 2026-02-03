import { EventWithMetadata, FormattedEvent, getTypeStyle } from '@/types';

export function formatRelativeTime(date: Date, now: Date): string {
  const diffSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

  if (diffSeconds < 60) {
    return 'Just nu';
  }

  const diffMinutes = Math.floor(diffSeconds / 60);
  if (diffMinutes < 60) {
    return `${diffMinutes} min sedan`;
  }

  const diffHours = Math.floor(diffMinutes / 60);
  if (diffHours < 24) {
    return `${diffHours} timmar sedan`;
  }

  const diffDays = Math.floor(diffHours / 24);
  return `${diffDays} dagar sedan`;
}

export function formatEventForUi(event: EventWithMetadata): FormattedEvent {
  const now = new Date();
  const eventTime = event.event_time || event.datetime || now.toISOString();

  let date: Date;
  try {
    date = new Date(eventTime);
  } catch {
    date = now;
  }

  const type = event.type || 'Okänd';
  const style = getTypeStyle(type);

  const updated = event.last_updated || event.publish_time || null;
  const updatedDate = updated ? new Date(updated) : null;

  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];

  return {
    id: event.id ?? null,
    datetime: eventTime,
    name: event.name || '',
    summary: event.summary || '',
    url: event.url || '',
    type,
    location: event.location?.name || '',
    gps: event.location?.gps || '',
    color: style.color,
    icon: style.icon,
    date: {
      day: String(date.getDate()).padStart(2, '0'),
      month: months[date.getMonth()],
      time: `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`,
      relative: formatRelativeTime(date, now),
      iso: date.toISOString(),
    },
    wasUpdated: !!event.was_updated,
    updated: updatedDate
      ? `${updatedDate.getFullYear()}-${String(updatedDate.getMonth() + 1).padStart(2, '0')}-${String(updatedDate.getDate()).padStart(2, '0')} ${String(updatedDate.getHours()).padStart(2, '0')}:${String(updatedDate.getMinutes()).padStart(2, '0')}`
      : '',
  };
}

export function sanitizeInput(input: string, maxLength = 255): string {
  // Remove null bytes and control characters
  let sanitized = input.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');
  // Normalize whitespace
  sanitized = sanitized.replace(/\s+/g, ' ');
  // Trim and limit length
  return sanitized.trim().substring(0, maxLength);
}

export function sanitizeLocation(location: string): string {
  const sanitized = sanitizeInput(location, 100);
  // Only allow alphanumeric, spaces, Swedish chars, and common punctuation
  return sanitized.replace(/[^a-zA-ZåäöÅÄÖ0-9\s\-,\.]/g, '');
}

export function sanitizeType(type: string): string {
  const sanitized = sanitizeInput(type, 100);
  // Only allow alphanumeric, spaces, Swedish chars, and slashes
  return sanitized.replace(/[^a-zA-ZåäöÅÄÖ0-9\s\/\-,]/g, '');
}

export function sanitizeSearch(search: string): string {
  return sanitizeInput(search, 200);
}
