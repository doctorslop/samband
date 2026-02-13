// Event types from Police API
export interface RawEvent {
  id: number;
  datetime: string;
  name: string;
  summary: string;
  url: string;
  type: string;
  location: {
    name: string;
    gps: string;
  };
}

export interface EventWithMetadata extends RawEvent {
  event_time: string;
  publish_time: string;
  last_updated: string;
  was_updated: boolean;
}

export interface FormattedEvent {
  id: number | null;
  datetime: string;
  name: string;
  summary: string;
  url: string;
  type: string;
  location: string;
  gps: string;
  color: string;
  icon: string;
  date: {
    day: string;
    month: string;
    time: string;
    relative: string;
    iso: string;
  };
  wasUpdated: boolean;
  updated: string;
}

export interface EventFilters {
  location?: string;
  type?: string;
  search?: string;
  date?: string;
  from?: string;
  to?: string;
}

// Statistics types
export interface DailyStats {
  date: string;
  day: string;
  count: number;
}

export interface TopItem {
  label: string;
  total: number;
}

export interface Statistics {
  period: string;
  total: number;
  totalStored: number;
  last24h: number;
  last7d: number;
  last30d: number;
  avgPerDay: number;
  topTypes: TopItem[];
  topLocations: TopItem[];
  hourly: number[];
  weekdays: number[];
  daily: DailyStats[];
  gpsPercent: number;
  updatedPercent: number;
  uniqueLocations: number;
  uniqueTypes: number;
}

// Type style mapping
export interface TypeStyle {
  icon: string;
  color: string;
  class: string;
}

export const TYPE_STYLES: Record<string, TypeStyle> = {
  'Inbrott': { icon: '🔓', color: '#f97316', class: 'event-type--inbrott' },
  'Brand': { icon: '🔥', color: '#ef4444', class: 'event-type--brand' },
  'Rån': { icon: '💰', color: '#f59e0b', class: 'event-type--ran' },
  'Trafikolycka': { icon: '🚗', color: '#3b82f6', class: 'event-type--trafikolycka' },
  'Misshandel': { icon: '👊', color: '#ef4444', class: 'event-type--misshandel' },
  'Skadegörelse': { icon: '🔨', color: '#f59e0b', class: 'event-type--skadegorelse' },
  'Bedrägeri': { icon: '🕵️', color: '#8b5cf6', class: 'event-type--bedrageri' },
  'Narkotikabrott': { icon: '💊', color: '#10b981', class: 'event-type--narkotikabrott' },
  'Ofredande': { icon: '🚨', color: '#f43f5e', class: 'event-type--ofredande' },
  'Sammanfattning': { icon: '📊', color: '#22c55e', class: 'event-type--sammanfattning' },
  'Stöld': { icon: '🔓', color: '#f97316', class: 'event-type--stold' },
  'Stöld/inbrott': { icon: '🔓', color: '#f97316', class: 'event-type--stold' },
  'Mord/dråp': { icon: '⚠️', color: '#dc2626', class: 'event-type--mord' },
  'Rattfylleri': { icon: '🚗', color: '#ef4444', class: 'event-type--ratta' },
  'default': { icon: '📌', color: '#fcd34d', class: 'event-type--default' },
};

export function getTypeStyle(type: string): TypeStyle {
  if (TYPE_STYLES[type]) {
    return TYPE_STYLES[type];
  }
  // Try partial match
  for (const [key, style] of Object.entries(TYPE_STYLES)) {
    if (type.toLowerCase().includes(key.toLowerCase()) || key.toLowerCase().includes(type.toLowerCase())) {
      return style;
    }
  }
  return TYPE_STYLES['default'];
}

export function getTypeClass(type: string): string {
  return getTypeStyle(type).class;
}

// Operational monitoring types
export interface OperationalStats {
  totalFetches: number;
  successfulFetches: number;
  failedFetches: number;
  fetches24h: number;
  fetches7d: number;
  successRate: number;
  avgFetchInterval: number;
  lastSuccessfulFetch: string | null;
  lastFailedFetch: string | null;
  recentErrors: Array<{ fetched_at: string; error_type: string }>;
  hourlyFetches: number[];
  avgEventsPerFetch: number;
  eventsAddedToday: number;
  uptimeScore: number;
}

export interface FetchLogEntry {
  id: number;
  fetchedAt: string;
  eventsFetched: number;
  eventsNew: number;
  success: boolean;
  errorType: string | null;
}

export interface DatabaseHealth {
  totalEvents: number;
  totalFetchLogs: number;
  eventsWithGps: number;
  eventsWithGpsPercent: number;
  uniqueLocations: number;
  uniqueTypes: number;
  oldestEvent: string | null;
  newestEvent: string | null;
  eventsByType: Array<{ type: string; count: number }>;
  dataFreshnessMinutes: number;
  updatedEvents: number;
  updatedEventsPercent: number;
}
