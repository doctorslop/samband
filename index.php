<?php
/**
 * Sambandscentralen
 * Polisens händelsenotiser - Self-contained with local SQLite storage
 *
 * @version 5.0 - Integrated API (no external VPS required)
 */

date_default_timezone_set('Europe/Stockholm');

// ============================================================================
// SECURITY HEADERS & HTTPS ENFORCEMENT
// ============================================================================

// Enforce HTTPS in production
if (!empty($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost' &&
    empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src [...]");

// Admin authentication for sensitive endpoints
define('ADMIN_KEY', 'loltrappa123');
function isAdminAuthorized(): bool {
    $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
    return hash_equals(ADMIN_KEY, $providedKey);
}

// Strict CORS - only allow same-origin for AJAX requests
if (isset($_GET['ajax'])) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';

    // Only allow same-origin requests
    if ($origin && parse_url($origin, PHP_URL_HOST) === $host) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    // For same-origin requests without Origin header (same-site navigation)
    header('Access-Control-Allow-Credentials: false');
}

// Configuration
define('CACHE_TIME', 120);           // 2 minutes for events (more real-time)
define('EVENTS_PER_PAGE', 40);
define('ASSET_VERSION', '5.6.0');    // Bump this to bust browser cache
define('MAX_FETCH_RETRIES', 3);      // Max retries for API fetch
define('USER_AGENT', 'FreshRSS/1.28.0 (Linux; https://freshrss.org)');
define('POLICE_API_URL', 'https://polisen.se/api/events');
define('POLICE_API_TIMEOUT', 30);

// Database configuration - store in a data directory
define('DATA_DIR', __DIR__ . '/data');
define('DB_PATH', DATA_DIR . '/events.db');
define('BACKUP_DIR', DATA_DIR . '/backups');

// Allowed views (security)
define('ALLOWED_VIEWS', ['list', 'map', 'stats', 'press']);

// ============================================================================
// INPUT SANITIZATION FUNCTIONS
// ============================================================================

/**
 * Sanitize string input - removes null bytes and normalizes whitespace
 */
function sanitizeInput(string $input, int $maxLength = 255): string {
    // Remove null bytes and control characters (except newline, tab)
    $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
    // Normalize whitespace
    $input = preg_replace('/\s+/', ' ', $input);
    // Trim and limit length
    return mb_substr(trim($input), 0, $maxLength);
}

/**
 * Sanitize location name for database queries
 */
function sanitizeLocation(string $location): string {
    $location = sanitizeInput($location, 100);
    // Only allow alphanumeric, spaces, Swedish chars, and common punctuation
    return preg_replace('/[^a-zA-ZåäöÅÄÖ0-9\s\-,\.]/', '', $location);
}

/**
 * Sanitize event type for database queries
 */
function sanitizeType(string $type): string {
    $type = sanitizeInput($type, 100);
    // Only allow alphanumeric, spaces, Swedish chars, and slashes
    return preg_replace('/[^a-zA-ZåäöÅÄÖ0-9\s\/\-,]/', '', $type);
}

/**
 * Sanitize search query
 */
function sanitizeSearch(string $search): string {
    return sanitizeInput($search, 200);
}

// ============================================================================
// DATABASE FUNCTIONS
// ============================================================================

/**
 * Get PDO connection to SQLite database
 */
function getDatabase(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        // Ensure data directory exists
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0755, true);
        }

        $isNew = !file_exists(DB_PATH);

        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30
        ]);

        // Enable WAL mode for better performance and durability
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA cache_size=-64000'); // 64MB cache
        $pdo->exec('PRAGMA temp_store=MEMORY');
        $pdo->exec('PRAGMA foreign_keys=ON');

        if ($isNew) {
            initDatabase($pdo);
        }

        // Run any pending migrations
        runMigrations($pdo);
    }

    return $pdo;
}

/**
 * Initialize database schema
 */
function initDatabase(PDO $pdo): void {
    $pdo->exec(" 
        -- Events table - stores raw data from Police API forever
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY,
            datetime TEXT NOT NULL,
            name TEXT NOT NULL,
            summary TEXT,
            url TEXT,
            type TEXT NOT NULL,
            location_name TEXT NOT NULL,
            location_gps TEXT,
            raw_data TEXT NOT NULL,
            fetched_at TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        -- Indexes for fast queries
        CREATE INDEX IF NOT EXISTS idx_events_datetime ON events(datetime DESC);
        CREATE INDEX IF NOT EXISTS idx_events_location ON events(location_name);
        CREATE INDEX IF NOT EXISTS idx_events_type ON events(type);
        CREATE INDEX IF NOT EXISTS idx_events_location_datetime ON events(location_name, datetime DESC);
        CREATE INDEX IF NOT EXISTS idx_events_type_datetime ON events(type, datetime DESC);

        -- Fetch log - keeps track of API calls
        CREATE TABLE IF NOT EXISTS fetch_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fetched_at TEXT NOT NULL,
            events_fetched INTEGER NOT NULL,
            events_new INTEGER NOT NULL,
            success INTEGER NOT NULL,
            error_message TEXT
        );

        -- Backup log
        CREATE TABLE IF NOT EXISTS backup_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            backup_at TEXT NOT NULL,
            filename TEXT NOT NULL,
            size_bytes INTEGER,
            success INTEGER NOT NULL,
            error_message TEXT
        );

        -- Migrations tracking table
        CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            applied_at TEXT NOT NULL
        );
    ");
}

/**
 * Run database migrations
 */
function runMigrations(PDO $pdo): void {
    // Migration 1: Normalize datetime format
    $migrationName = 'normalize_datetime_format_v1';
    $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE name = ?");
    $stmt->execute([$migrationName]);
    if (!$stmt->fetch()) {
        // Fix datetime format: "2026-01-11 16:47:22 +01:00" -> "2026-01-11T16:47:22+01:00"
        $pdo->exec("UPDATE events SET datetime = REPLACE(datetime, ' +', '+') WHERE datetime LIKE '% +%'");
        $pdo->exec("UPDATE events SET datetime = REPLACE(datetime, ' -', '-') WHERE datetime LIKE '% -%' AND datetime NOT LIKE '%T%'");
        $pdo->exec(" 
            UPDATE events
            SET datetime = SUBSTR(datetime, 1, 10) || 'T' || SUBSTR(datetime, 12)
            WHERE datetime LIKE '____-__-__ %'
        ");
        $stmt = $pdo->prepare("INSERT INTO migrations (name, applied_at) VALUES (?, ?)");
        $stmt->execute([$migrationName, date('c')]);
    }

    // Migration 2: Add dual-time model (event_time + publish_time)
    $migrationName = 'add_dual_time_model_v1';
    $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE name = ?");
    $stmt->execute([$migrationName]);
    if (!$stmt->fetch()) {
        // Add new columns for dual-time model
        $pdo->exec("ALTER TABLE events ADD COLUMN event_time TEXT");
        $pdo->exec("ALTER TABLE events ADD COLUMN publish_time TEXT");
        $pdo->exec("ALTER TABLE events ADD COLUMN last_updated TEXT");
        $pdo->exec("ALTER TABLE events ADD COLUMN content_hash TEXT");

        // Populate event_time from datetime for existing records
        // For existing events, we assume datetime is the event_time
        $pdo->exec("UPDATE events SET event_time = datetime WHERE event_time IS NULL");
        // Set publish_time to fetched_at for existing records
        $pdo->exec("UPDATE events SET publish_time = fetched_at WHERE publish_time IS NULL");
        // Set last_updated to publish_time initially
        $pdo->exec("UPDATE events SET last_updated = publish_time WHERE last_updated IS NULL");

        // Create index on event_time for sorting
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_event_time ON events(event_time DESC)");

        // Record migration
        $stmt = $pdo->prepare("INSERT INTO migrations (name, applied_at) VALUES (?, ?)");
        $stmt->execute([$migrationName, date('c')]);
    }

    // Migration 3: Add composite indexes for filtered queries with event_time sorting
    $migrationName = 'add_composite_event_time_indexes_v1';
    $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE name = ?");
    $stmt->execute([$migrationName]);
    if (!$stmt->fetch()) {
        // Composite indexes for common filter + sort patterns
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_location_event_time ON events(location_name, event_time DESC, id DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_type_event_time ON events(type, event_time DESC, id DESC)");
        // Index for event_time + id sorting (used in all queries)
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_event_time_id ON events(event_time DESC, id DESC)");

        $stmt = $pdo->prepare("INSERT INTO migrations (name, applied_at) VALUES (?, ?)");
        $stmt->execute([$migrationName, date('c')]);
    }
}

/**
 * Normalize datetime to ISO 8601 format for SQLite compatibility
 * Converts "2026-01-11 16:47:22 +01:00" to "2026-01-11T16:47:22+01:00"
 */
function normalizeDateTime(string $datetime): string {
    // Replace first space with T (between date and time)
    $normalized = preg_replace('/^(\d{4}-\d{2}-\d{2}) /', '$1T', $datetime);
    // Remove space before timezone offset (e.g., " +01:00" -> "+01:00")
    $normalized = preg_replace('/ ([+-]\d{2}:\d{2})$/', '$1', $normalized);
    return $normalized;
}

/**
 * Extract the actual event time from event data
 */
function extractEventTime(array $event): ?string {
    $summary = $event['summary'] ?? '';
    $name = $event['name'] ?? '';
    $apiDatetime = $event['datetime'] ?? null;
    $type = $event['type'] ?? '';

    // For summaries, try to extract the time period they cover
    if (stripos($type, 'Sammanfattning') !== false || stripos($name, 'Sammanfattning') !== false) {
        if (preg_match('/kl\.??\s*(\d{1,2})[:\.]?(\d{2})?\s*[-–]\s*(\d{1,2})/i', $summary, $m)) {
            $startHour = intval($m[1]);
            if ($apiDatetime) {
                try {
                    $date = new DateTime($apiDatetime);
                    $date->setTime($startHour, 0, 0);
                    return $date->format('c');
                } catch (Exception $e) {}
            }
        }
        if (preg_match('/(dygn|dag|natt|kväll|morgon)/i', $summary)) {
            if ($apiDatetime) {
                try {
                    $date = new DateTime($apiDatetime);
                    if (preg_match('/natt/i', $summary)) {
                        $date->setTime(0, 0, 0);
                    } elseif (preg_match('/kväll/i', $summary)) {
                        $date->setTime(18, 0, 0);
                    } elseif (preg_match('/morgon/i', $summary)) {
                        $date->setTime(6, 0, 0);
                    } else {
                        $date->setTime(0, 0, 0);
                    }
                    return $date->format('c');
                } catch (Exception $e) {}
            }
        }
    }

    if (preg_match('/[Kk]l(?:ockan)?\.?\s*(\d{1,2})[:\.](\d{2})/', $summary, $m)) {
        $hour = intval($m[1]);
        $minute = intval($m[2]);
        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
            if ($apiDatetime) {
                try {
                    $date = new DateTime($apiDatetime);
                    $apiHour = intval($date->format('H'));
                    if ($hour > $apiHour + 2) {
                        $date->modify('-1 day');
                    }
                    $date->setTime($hour, $minute, 0);
                    return $date->format('c');
                } catch (Exception $e) {}
            }
        }
    }

    return $apiDatetime ? normalizeDateTime($apiDatetime) : null;
}

/**
 * Generate a hash of event content to detect changes
 */
function generateContentHash(array $event): string {
    $content = ($event['name'] ?? '') . '|' . ($event['summary'] ?? '') . '|' . ($event['type'] ?? '');
    return md5($content);
}

/**
 * Insert or update event in database
 */
function insertEvent(PDO $pdo, array $event): string {
    $eventId = $event['id'];
    $normalizedDatetime = normalizeDateTime($event['datetime']);
    $now = date('c');
    $contentHash = generateContentHash($event);

    // Check if event already exists
    $stmt = $pdo->prepare("SELECT content_hash, event_time FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Event exists - check if content changed
        if ($existing['content_hash'] === $contentHash) {
            return 'unchanged';
        }

        // Content changed - update the event but keep original event_time
        $stmt = $pdo->prepare(" 
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
        ");
        $stmt->execute([
            $normalizedDatetime,
            $event['name'],
            $event['summary'] ?? '',
            $event['url'] ?? '',
            $event['type'],
            $event['location']['name'],
            $event['location']['gps'] ?? '',
            json_encode($event, JSON_UNESCAPED_UNICODE),
            $now,
            $contentHash,
            $eventId
        ]);
        return 'updated';
    }

    // New event - extract event_time and insert
    $eventTime = extractEventTime($event) ?? $normalizedDatetime;

    $stmt = $pdo->prepare(" 
        INSERT INTO events
        (id, datetime, event_time, publish_time, last_updated, name, summary, url, type,
         location_name, location_gps, raw_data, fetched_at, content_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $eventId,
        $normalizedDatetime,
        $eventTime,
        $now,
        $now,
        $event['name'],
        $event['summary'] ?? '',
        $event['url'] ?? '',
        $event['type'],
        $event['location']['name'],
        $event['location']['gps'] ?? '',
        json_encode($event, JSON_UNESCAPED_UNICODE),
        $now,
        $contentHash
    ]);
    return 'new';
}

/**
 * Log a fetch operation
 */
function logFetch(PDO $pdo, int $eventsFetched, int $eventsNew, bool $success, ?string $error = null): void {
    $stmt = $pdo->prepare(" 
        INSERT INTO fetch_log (fetched_at, events_fetched, events_new, success, error_message)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([date('c'), $eventsFetched, $eventsNew, $success ? 1 : 0, $error]);
}

/**
 * Get events from database with optional filters
 * Now returns events sorted by event_time (when the event occurred)
 * Each event includes event_time, publish_time, and last_updated metadata
 */
function getEventsFromDb(array $filters = [], int $limit = 500, int $offset = 0): array {
    $pdo = getDatabase();

    // Select raw_data plus the time columns for dual-time model
    $query = "SELECT raw_data, event_time, publish_time, last_updated FROM events WHERE 1=1";
    $params = [];

    if (!empty($filters['location'])) {
        $query .= " AND location_name = ?";
        $params[] = $filters['location'];
    }

    if (!empty($filters['type'])) {
        $query .= " AND type = ?";
        $params[] = $filters['type'];
    }

    if (!empty($filters['date'])) {
        // Filter by event_time date, not datetime (publish time)
        $query .= " AND event_time LIKE ?";
        $params[] = $filters['date'] . '%';
    }

    if (!empty($filters['from'])) {
        $query .= " AND event_time >= ?";
        $params[] = $filters['from'];
    }

    if (!empty($filters['to'])) {
        $query .= " AND event_time <= ?";
        $params[] = $filters['to'] . 'T23:59:59';
    }

    if (!empty($filters['search'])) {
        $query .= " AND (name LIKE ? OR summary LIKE ? OR location_name LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    // Sort by most-recent activity: prefer last_updated (edits), then publish_time (creation),
    // then event_time (when the event occurred), finally id as a stable tiebreaker.
    $query .= " ORDER BY COALESCE(last_updated, publish_time, event_time) DESC, id DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $events = [];
    while ($row = $stmt->fetch()) {
        $event = json_decode($row['raw_data'], true);
        // Add the dual-time model fields to each event
        $event['event_time'] = $row['event_time'];
        $event['publish_time'] = $row['publish_time'];
        $event['last_updated'] = $row['last_updated'];
        // Check if event has been updated (last_updated differs from publish_time)
        $event['was_updated'] = $row['last_updated'] && $row['publish_time'] &&
                                 $row['last_updated'] !== $row['publish_time'];
        $events[] = $event;
    }

    return $events;
}

/**
 * Count events in database with optional filters (including search)
 */
function countEventsInDb(array $filters = []): int {
    $pdo = getDatabase();

    $query = "SELECT COUNT(*) as count FROM events WHERE 1=1";
    $params = [];

    if (!empty($filters['location'])) {
        $query .= " AND location_name = ?";
        $params[] = $filters['location'];
    }

    if (!empty($filters['type'])) {
        $query .= " AND type = ?";
        $params[] = $filters['type'];
    }

    if (!empty($filters['search'])) {
        $query .= " AND (name LIKE ? OR summary LIKE ? OR location_name LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return (int) $stmt->fetch()['count'];
}

[TRUNCATED FOR BREVITY: the rest of the file remains unchanged]
