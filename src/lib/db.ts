import Database from 'better-sqlite3';
import path from 'path';
import { EventFilters, EventWithMetadata, RawEvent, Statistics, DailyStats, TopItem } from '@/types';

// Database configuration
const DATA_DIR = path.join(process.cwd(), 'data');
const DB_PATH = path.join(DATA_DIR, 'events.db');

// Singleton database instance
let db: Database.Database | null = null;

// Initialize database tables if they don't exist
function initializeDatabase(database: Database.Database): void {
  // Create events table
  database.exec(`
    CREATE TABLE IF NOT EXISTS events (
      id INTEGER PRIMARY KEY,
      datetime TEXT,
      event_time TEXT,
      publish_time TEXT,
      last_updated TEXT,
      name TEXT,
      summary TEXT,
      url TEXT,
      type TEXT,
      location_name TEXT,
      location_gps TEXT,
      raw_data TEXT,
      fetched_at TEXT,
      content_hash TEXT
    )
  `);

  // Create fetch_log table
  database.exec(`
    CREATE TABLE IF NOT EXISTS fetch_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      fetched_at TEXT,
      events_fetched INTEGER,
      events_new INTEGER,
      success INTEGER,
      error_message TEXT
    )
  `);

  // Create indexes for better query performance
  database.exec(`
    CREATE INDEX IF NOT EXISTS idx_events_event_time ON events(event_time);
    CREATE INDEX IF NOT EXISTS idx_events_location ON events(location_name);
    CREATE INDEX IF NOT EXISTS idx_events_type ON events(type);
    CREATE INDEX IF NOT EXISTS idx_fetch_log_fetched_at ON fetch_log(fetched_at);
  `);
}

export function getDatabase(): Database.Database {
  if (!db) {
    db = new Database(DB_PATH, { readonly: false });

    // Enable WAL mode for better performance
    db.pragma('journal_mode = WAL');
    db.pragma('synchronous = NORMAL');
    db.pragma('cache_size = -64000'); // 64MB cache
    db.pragma('temp_store = MEMORY');
    db.pragma('foreign_keys = ON');

    // Initialize tables
    initializeDatabase(db);
  }
  return db;
}

// Normalize datetime to ISO 8601 format
function normalizeDateTime(datetime: string): string {
  let normalized = datetime.replace(/^(\d{4}-\d{2}-\d{2}) /, '$1T');
  normalized = normalized.replace(/ ([+-]\d{2}:\d{2})$/, '$1');
  return normalized;
}

// Extract actual event time from event data
function extractEventTime(event: RawEvent): string | null {
  const { summary = '', name = '', datetime, type = '' } = event;

  // For summaries, try to extract the time period they cover
  if (type.toLowerCase().includes('sammanfattning') || name.toLowerCase().includes('sammanfattning')) {
    const timeMatch = summary.match(/kl\.?\s*(\d{1,2})[:\.]?(\d{2})?\s*[-–]\s*(\d{1,2})/i);
    if (timeMatch && datetime) {
      try {
        const date = new Date(datetime);
        const startHour = parseInt(timeMatch[1], 10);
        date.setHours(startHour, 0, 0, 0);
        return date.toISOString();
      } catch {
        // Fall through
      }
    }

    const periodMatch = summary.match(/(dygn|dag|natt|kväll|morgon)/i);
    if (periodMatch && datetime) {
      try {
        const date = new Date(datetime);
        if (/natt/i.test(summary)) {
          date.setHours(0, 0, 0, 0);
        } else if (/kväll/i.test(summary)) {
          date.setHours(18, 0, 0, 0);
        } else if (/morgon/i.test(summary)) {
          date.setHours(6, 0, 0, 0);
        } else {
          date.setHours(0, 0, 0, 0);
        }
        return date.toISOString();
      } catch {
        // Fall through
      }
    }
  }

  // Primary: Extract time from name field format "DD månad HH.MM, Type, Location"
  const nameMatch = name.match(/^(\d{1,2})\s+\w+\s+(\d{1,2})[\.:,](\d{2})/);
  if (nameMatch && datetime) {
    const day = parseInt(nameMatch[1], 10);
    const hour = parseInt(nameMatch[2], 10);
    const minute = parseInt(nameMatch[3], 10);

    if (hour >= 0 && hour <= 23 && minute >= 0 && minute <= 59 && day >= 1 && day <= 31) {
      try {
        const date = new Date(datetime);
        const apiDay = date.getDate();

        if (day !== apiDay) {
          if (day < apiDay) {
            date.setDate(day);
          } else {
            date.setMonth(date.getMonth() - 1);
            date.setDate(day);
          }
        }
        date.setHours(hour, minute, 0, 0);
        return date.toISOString();
      } catch {
        // Fall through
      }
    }
  }

  // Fallback: Extract time from summary using "Kl" or "Klockan" prefix
  const klMatch = summary.match(/[Kk]l(?:ockan)?\.?\s*(\d{1,2})[:\.](\d{2})/);
  if (klMatch && datetime) {
    const hour = parseInt(klMatch[1], 10);
    const minute = parseInt(klMatch[2], 10);

    if (hour >= 0 && hour <= 23 && minute >= 0 && minute <= 59) {
      try {
        const date = new Date(datetime);
        const apiHour = date.getHours();
        if (hour > apiHour + 2) {
          date.setDate(date.getDate() - 1);
        }
        date.setHours(hour, minute, 0, 0);
        return date.toISOString();
      } catch {
        // Fall through
      }
    }
  }

  return datetime ? normalizeDateTime(datetime) : null;
}

// Generate content hash for change detection
function generateContentHash(event: RawEvent): string {
  const content = `${event.name || ''}|${event.summary || ''}|${event.type || ''}`;
  // Simple hash function
  let hash = 0;
  for (let i = 0; i < content.length; i++) {
    const char = content.charCodeAt(i);
    hash = ((hash << 5) - hash) + char;
    hash = hash & hash; // Convert to 32bit integer
  }
  return hash.toString(16);
}

// Insert or update event in database
export function insertEvent(event: RawEvent): 'new' | 'updated' | 'unchanged' {
  const pdo = getDatabase();
  const normalizedDatetime = normalizeDateTime(event.datetime);
  const now = new Date().toISOString();
  const contentHash = generateContentHash(event);

  // Check if event already exists
  const existing = pdo.prepare('SELECT content_hash, event_time FROM events WHERE id = ?').get(event.id) as { content_hash: string; event_time: string } | undefined;

  if (existing) {
    if (existing.content_hash === contentHash) {
      return 'unchanged';
    }

    // Content changed - update the event
    pdo.prepare(`
      UPDATE events SET
        datetime = ?,
        name = ?,
        summary = ?,
        url = ?,
        type = ?,
        location_name = ?,
        location_gps = ?,
        raw_data = ?,
        last_updated = ?,
        content_hash = ?
      WHERE id = ?
    `).run(
      normalizedDatetime,
      event.name,
      event.summary || '',
      event.url || '',
      event.type,
      event.location.name,
      event.location.gps || '',
      JSON.stringify(event),
      now,
      contentHash,
      event.id
    );
    return 'updated';
  }

  // New event - extract event_time and insert
  const eventTime = extractEventTime(event) || normalizedDatetime;

  pdo.prepare(`
    INSERT INTO events
    (id, datetime, event_time, publish_time, last_updated, name, summary, url, type,
     location_name, location_gps, raw_data, fetched_at, content_hash)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    event.id,
    normalizedDatetime,
    eventTime,
    now,
    now,
    event.name,
    event.summary || '',
    event.url || '',
    event.type,
    event.location.name,
    event.location.gps || '',
    JSON.stringify(event),
    now,
    contentHash
  );
  return 'new';
}

// Log a fetch operation
export function logFetch(eventsFetched: number, eventsNew: number, success: boolean, error?: string): void {
  const pdo = getDatabase();
  pdo.prepare(`
    INSERT INTO fetch_log (fetched_at, events_fetched, events_new, success, error_message)
    VALUES (?, ?, ?, ?, ?)
  `).run(new Date().toISOString(), eventsFetched, eventsNew, success ? 1 : 0, error || null);
}

// Get events from database with optional filters
export function getEventsFromDb(filters: EventFilters = {}, limit = 500, offset = 0): EventWithMetadata[] {
  const pdo = getDatabase();
  const params: (string | number)[] = [];
  let query = 'SELECT raw_data, event_time, publish_time, last_updated FROM events WHERE 1=1';

  if (filters.location) {
    query += ' AND location_name = ?';
    params.push(filters.location);
  }

  if (filters.type) {
    query += ' AND type = ?';
    params.push(filters.type);
  }

  if (filters.date) {
    query += ' AND event_time LIKE ?';
    params.push(filters.date + '%');
  }

  if (filters.from) {
    query += ' AND event_time >= ?';
    params.push(filters.from);
  }

  if (filters.to) {
    query += ' AND event_time <= ?';
    params.push(filters.to + 'T23:59:59');
  }

  if (filters.search) {
    query += ' AND (name LIKE ? OR summary LIKE ? OR location_name LIKE ?)';
    const searchTerm = '%' + filters.search + '%';
    params.push(searchTerm, searchTerm, searchTerm);
  }

  query += ' ORDER BY event_time DESC, id DESC LIMIT ? OFFSET ?';
  params.push(limit, offset);

  const rows = pdo.prepare(query).all(...params) as Array<{
    raw_data: string;
    event_time: string;
    publish_time: string;
    last_updated: string;
  }>;

  return rows.map(row => {
    const event = JSON.parse(row.raw_data) as RawEvent;
    return {
      ...event,
      event_time: row.event_time,
      publish_time: row.publish_time,
      last_updated: row.last_updated,
      was_updated: Boolean(row.last_updated && row.publish_time && row.last_updated !== row.publish_time),
    };
  });
}

// Count events in database with optional filters
export function countEventsInDb(filters: EventFilters = {}): number {
  const pdo = getDatabase();
  const params: string[] = [];
  let query = 'SELECT COUNT(*) as count FROM events WHERE 1=1';

  if (filters.location) {
    query += ' AND location_name = ?';
    params.push(filters.location);
  }

  if (filters.type) {
    query += ' AND type = ?';
    params.push(filters.type);
  }

  if (filters.search) {
    query += ' AND (name LIKE ? OR summary LIKE ? OR location_name LIKE ?)';
    const searchTerm = '%' + filters.search + '%';
    params.push(searchTerm, searchTerm, searchTerm);
  }

  const result = pdo.prepare(query).get(...params) as { count: number };
  return result.count;
}

// Get last fetch time
export function getLastFetchTime(): Date | null {
  const pdo = getDatabase();
  const result = pdo.prepare('SELECT fetched_at FROM fetch_log ORDER BY fetched_at DESC LIMIT 1').get() as { fetched_at: string } | undefined;
  return result ? new Date(result.fetched_at) : null;
}

// Get filter options
export function getFilterOptions(column: 'location_name' | 'type'): string[] {
  const pdo = getDatabase();
  const rows = pdo.prepare(`SELECT DISTINCT ${column} AS value FROM events WHERE ${column} != '' ORDER BY ${column} ASC`).all() as Array<{ value: string }>;
  return rows.map(row => row.value);
}

// Get statistics summary
export function getStatsSummary(): Statistics {
  const pdo = getDatabase();
  const now = new Date();
  const since24h = new Date(now.getTime() - 24 * 60 * 60 * 1000).toISOString();
  const since7d = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000).toISOString();
  const since30d = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000).toISOString();

  const excludePattern = '%Sammanfattning%';

  // Last 24h
  const last24h = (pdo.prepare('SELECT COUNT(*) as count FROM events WHERE event_time >= ? AND type NOT LIKE ?').get(since24h, excludePattern) as { count: number }).count;

  // Last 7 days
  const last7d = (pdo.prepare('SELECT COUNT(*) as count FROM events WHERE event_time >= ? AND type NOT LIKE ?').get(since7d, excludePattern) as { count: number }).count;

  // Last 30 days
  const last30d = (pdo.prepare('SELECT COUNT(*) as count FROM events WHERE event_time >= ? AND type NOT LIKE ?').get(since30d, excludePattern) as { count: number }).count;

  // Total
  const total = (pdo.prepare('SELECT COUNT(*) as count FROM events WHERE type NOT LIKE ?').get(excludePattern) as { count: number }).count;

  // Oldest event for average calculation
  const oldest = pdo.prepare('SELECT MIN(event_time) as oldest FROM events WHERE type NOT LIKE ?').get(excludePattern) as { oldest: string | null };
  let avgPerDay = 0;
  if (oldest?.oldest) {
    const oldestDate = new Date(oldest.oldest);
    const daysDiff = Math.max(1, Math.floor((now.getTime() - oldestDate.getTime()) / (24 * 60 * 60 * 1000)));
    avgPerDay = Math.round((total / daysDiff) * 10) / 10;
  }

  // Top types
  const topTypes = pdo.prepare('SELECT type AS label, COUNT(*) AS total FROM events WHERE type NOT LIKE ? GROUP BY type ORDER BY total DESC LIMIT 8').all(excludePattern) as TopItem[];

  // Top locations
  const topLocations = pdo.prepare('SELECT location_name AS label, COUNT(*) AS total FROM events WHERE type NOT LIKE ? GROUP BY location_name ORDER BY total DESC LIMIT 8').all(excludePattern) as TopItem[];

  // Per hour last 24h
  const hourlyRows = pdo.prepare("SELECT strftime('%H', event_time) AS hour, COUNT(*) AS total FROM events WHERE event_time >= ? AND type NOT LIKE ? GROUP BY hour ORDER BY hour").all(since24h, excludePattern) as Array<{ hour: string; total: number }>;
  const hourly: number[] = Array(24).fill(0);
  for (const row of hourlyRows) {
    hourly[parseInt(row.hour, 10)] = row.total;
  }

  // Per weekday last 30 days
  const weekdayRows = pdo.prepare("SELECT strftime('%w', event_time) AS weekday, COUNT(*) AS total FROM events WHERE event_time >= ? AND type NOT LIKE ? GROUP BY weekday ORDER BY weekday").all(since30d, excludePattern) as Array<{ weekday: string; total: number }>;
  const weekdayData: number[] = Array(7).fill(0);
  for (const row of weekdayRows) {
    weekdayData[parseInt(row.weekday, 10)] = row.total;
  }
  // Convert to Monday-Sunday order (Swedish)
  const weekdays = [
    weekdayData[1], // Monday
    weekdayData[2], // Tuesday
    weekdayData[3], // Wednesday
    weekdayData[4], // Thursday
    weekdayData[5], // Friday
    weekdayData[6], // Saturday
    weekdayData[0], // Sunday
  ];

  // Events per day last 7 days
  const dailyRows = pdo.prepare("SELECT date(event_time) AS day, COUNT(*) AS total FROM events WHERE event_time >= ? AND type NOT LIKE ? GROUP BY day ORDER BY day").all(since7d, excludePattern) as Array<{ day: string; total: number }>;
  const dailyMap: Record<string, number> = {};
  for (const row of dailyRows) {
    dailyMap[row.day] = row.total;
  }

  const daily: DailyStats[] = [];
  const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  for (let i = 6; i >= 0; i--) {
    const date = new Date(now.getTime() - i * 24 * 60 * 60 * 1000);
    const dateStr = date.toISOString().split('T')[0];
    daily.push({
      date: dateStr,
      day: dayNames[date.getDay()],
      count: dailyMap[dateStr] || 0,
    });
  }

  return {
    total,
    last24h,
    last7d,
    last30d,
    avgPerDay,
    topTypes,
    topLocations,
    hourly,
    weekdays,
    daily,
  };
}
