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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://*.basemaps.cartocdn.com; connect-src 'self' https://vmaapi.sr.se");

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
define('ASSET_VERSION', '6.2.0');    // Bump this to bust browser cache
define('MAX_FETCH_RETRIES', 3);      // Max retries for API fetch
define('USER_AGENT', 'FreshRSS/1.28.0 (Linux; https://freshrss.org)');
define('POLICE_API_URL', 'https://polisen.se/api/events');
define('POLICE_API_TIMEOUT', 30);

// Database configuration - store in a data directory
define('DATA_DIR', __DIR__ . '/data');
define('DB_PATH', DATA_DIR . '/events.db');
define('BACKUP_DIR', DATA_DIR . '/backups');

// Allowed views (security)
define('ALLOWED_VIEWS', ['list', 'map', 'stats', 'vma']);

// VMA API configuration
define('VMA_API_URL', 'https://vmaapi.sr.se/api/v3/alerts/feed.atom');
define('VMA_API_TIMEOUT', 15);
define('VMA_CACHE_FILE', DATA_DIR . '/vma_cache.json');
define('VMA_CACHE_TTL', 300); // 5 minutes

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

    // Migration 4: Re-extract event_time from name field for proper chronological sorting
    // The previous extraction only looked at summary field, but actual event time is in the name
    $migrationName = 'reextract_event_time_from_name_v1';
    $stmt = $pdo->prepare("SELECT 1 FROM migrations WHERE name = ?");
    $stmt->execute([$migrationName]);
    if (!$stmt->fetch()) {
        // Re-process all events to extract correct event_time from name field
        $events = $pdo->query("SELECT id, raw_data FROM events")->fetchAll();
        $updateStmt = $pdo->prepare("UPDATE events SET event_time = ? WHERE id = ?");

        foreach ($events as $row) {
            $eventData = json_decode($row['raw_data'], true);
            if ($eventData) {
                $newEventTime = extractEventTime($eventData);
                if ($newEventTime) {
                    $updateStmt->execute([$newEventTime, $row['id']]);
                }
            }
        }

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
 *
 * The API returns events with a 'datetime' that represents when the event was
 * published, not when it actually occurred. The actual event time is embedded
 * in the 'name' field in the format: "DD månad HH.MM, Type, Location"
 * (e.g., "16 januari 20.29, Stöld/inbrott, Nacka")
 *
 * This function extracts the actual event time for proper chronological sorting.
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

    // Primary: Extract time from name field format "DD månad HH.MM, Type, Location"
    // Examples: "16 januari 20.29, Stöld/inbrott, Nacka" or "16 januari 17.50, Trafikolycka, Nacka"
    if (preg_match('/^(\d{1,2})\s+\w+\s+(\d{1,2})[\.:,](\d{2})/u', $name, $m)) {
        $day = intval($m[1]);
        $hour = intval($m[2]);
        $minute = intval($m[3]);
        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $day >= 1 && $day <= 31) {
            if ($apiDatetime) {
                try {
                    $date = new DateTime($apiDatetime);
                    $apiDay = intval($date->format('d'));
                    // If the event day differs from publish day, adjust the date
                    if ($day !== $apiDay) {
                        // Event is from a previous day (published later)
                        if ($day < $apiDay) {
                            $date->setDate((int)$date->format('Y'), (int)$date->format('m'), $day);
                        } else {
                            // Day is greater but we're in a new month - go back to previous month
                            $date->modify('-1 month');
                            $date->setDate((int)$date->format('Y'), (int)$date->format('m'), $day);
                        }
                    }
                    $date->setTime($hour, $minute, 0);
                    return $date->format('c');
                } catch (Exception $e) {}
            }
        }
    }

    // Fallback: Extract time from summary using "Kl" or "Klockan" prefix
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

    // Sort by event_time (when the event actually occurred) for consistent chronological ordering.
    // This ensures newest events always appear first, regardless of when they were published or updated.
    // Secondary sort by id DESC ensures stable ordering for events with identical timestamps.
    $query .= " ORDER BY event_time DESC, id DESC LIMIT ? OFFSET ?";
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

// ============================================================================
// DATA FETCHING & PRESENTATION HELPERS
// ============================================================================

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fetchJson(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => POLICE_API_TIMEOUT,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        throw new RuntimeException($error ?: 'HTTP error ' . $status);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON response');
    }

    return $data;
}

function refreshEventsIfNeeded(): array {
    $pdo = getDatabase();
    $stmt = $pdo->query("SELECT fetched_at FROM fetch_log ORDER BY fetched_at DESC LIMIT 1");
    $lastFetch = $stmt->fetchColumn();
    $shouldFetch = !$lastFetch || (time() - strtotime($lastFetch)) > CACHE_TIME;

    if (!$shouldFetch) {
        return ['fetched' => 0, 'new' => 0, 'updated' => 0, 'success' => true, 'error' => null];
    }

    $eventsFetched = 0;
    $eventsNew = 0;
    $eventsUpdated = 0;
    $error = null;

    try {
        $events = [];
        $attempt = 0;
        while ($attempt < MAX_FETCH_RETRIES) {
            $attempt++;
            try {
                $events = fetchJson(POLICE_API_URL);
                break;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                if ($attempt >= MAX_FETCH_RETRIES) {
                    throw $e;
                }
                usleep(200000);
            }
        }

        foreach ($events as $event) {
            $eventsFetched++;
            $status = insertEvent($pdo, $event);
            if ($status === 'new') {
                $eventsNew++;
            } elseif ($status === 'updated') {
                $eventsUpdated++;
            }
        }

        logFetch($pdo, $eventsFetched, $eventsNew, true, null);
        return ['fetched' => $eventsFetched, 'new' => $eventsNew, 'updated' => $eventsUpdated, 'success' => true, 'error' => null];
    } catch (RuntimeException $e) {
        logFetch($pdo, $eventsFetched, $eventsNew, false, $error ?: $e->getMessage());
        return ['fetched' => $eventsFetched, 'new' => $eventsNew, 'updated' => $eventsUpdated, 'success' => false, 'error' => $e->getMessage()];
    }
}

function getTypeStyles(): array {
    return [
        'Inbrott' => ['icon' => '🔓', 'color' => '#f97316', 'class' => 'event-type--inbrott'],
        'Brand' => ['icon' => '🔥', 'color' => '#ef4444', 'class' => 'event-type--brand'],
        'Rån' => ['icon' => '💰', 'color' => '#f59e0b', 'class' => 'event-type--ran'],
        'Trafikolycka' => ['icon' => '🚗', 'color' => '#3b82f6', 'class' => 'event-type--trafikolycka'],
        'Misshandel' => ['icon' => '👊', 'color' => '#ef4444', 'class' => 'event-type--misshandel'],
        'Skadegörelse' => ['icon' => '🔨', 'color' => '#f59e0b', 'class' => 'event-type--skadegorelse'],
        'Bedrägeri' => ['icon' => '🕵️', 'color' => '#8b5cf6', 'class' => 'event-type--bedrageri'],
        'Narkotikabrott' => ['icon' => '💊', 'color' => '#10b981', 'class' => 'event-type--narkotikabrott'],
        'Ofredande' => ['icon' => '🚨', 'color' => '#f43f5e', 'class' => 'event-type--ofredande'],
        'Sammanfattning' => ['icon' => '📊', 'color' => '#22c55e', 'class' => 'event-type--sammanfattning'],
        'Stöld' => ['icon' => '🔓', 'color' => '#f97316', 'class' => 'event-type--stold'],
        'Stöld/inbrott' => ['icon' => '🔓', 'color' => '#f97316', 'class' => 'event-type--stold'],
        'Mord/dråp' => ['icon' => '⚠️', 'color' => '#dc2626', 'class' => 'event-type--mord'],
        'Rattfylleri' => ['icon' => '🚗', 'color' => '#ef4444', 'class' => 'event-type--ratta'],
        'default' => ['icon' => '📌', 'color' => '#fcd34d', 'class' => 'event-type--default']
    ];
}

/**
 * Get CSS class for event type
 */
function getTypeClass(string $type): string {
    $typeStyles = getTypeStyles();
    // Try exact match first
    if (isset($typeStyles[$type])) {
        return $typeStyles[$type]['class'];
    }
    // Try partial match for compound types like "Stöld/inbrott"
    foreach ($typeStyles as $key => $style) {
        if (stripos($type, $key) !== false || stripos($key, $type) !== false) {
            return $style['class'];
        }
    }
    return $typeStyles['default']['class'];
}

function formatRelativeTime(DateTimeImmutable $date, DateTimeImmutable $now): string {
    $diffSeconds = $now->getTimestamp() - $date->getTimestamp();
    if ($diffSeconds < 60) {
        return 'Just nu';
    }
    $diffMinutes = (int) floor($diffSeconds / 60);
    if ($diffMinutes < 60) {
        return $diffMinutes . ' min sedan';
    }
    $diffHours = (int) floor($diffMinutes / 60);
    if ($diffHours < 24) {
        return $diffHours . ' timmar sedan';
    }
    $diffDays = (int) floor($diffHours / 24);
    return $diffDays . ' dagar sedan';
}

function formatEventForUi(array $event): array {
    $now = new DateTimeImmutable('now');
    $eventTime = $event['event_time'] ?? $event['datetime'] ?? $now->format('c');
    try {
        $date = new DateTimeImmutable($eventTime);
    } catch (Exception $e) {
        $date = $now;
    }

    $type = $event['type'] ?? 'Okänd';
    $typeStyles = getTypeStyles();
    $style = $typeStyles[$type] ?? $typeStyles['default'];

    $updated = $event['last_updated'] ?? $event['publish_time'] ?? null;

    return [
        'id' => $event['id'] ?? null,
        'datetime' => $eventTime,
        'name' => $event['name'] ?? '',
        'summary' => $event['summary'] ?? '',
        'url' => $event['url'] ?? '',
        'type' => $type,
        'location' => $event['location']['name'] ?? '',
        'gps' => $event['location']['gps'] ?? '',
        'color' => $style['color'],
        'icon' => $style['icon'],
        'date' => [
            'day' => $date->format('d'),
            'month' => $date->format('M'),
            'time' => $date->format('H:i'),
            'relative' => formatRelativeTime($date, $now),
            'iso' => $date->format('c')
        ],
        'wasUpdated' => !empty($event['was_updated']),
        'updated' => $updated ? (new DateTimeImmutable($updated))->format('Y-m-d H:i') : ''
    ];
}

function getEventsForUi(array $filters, int $limit, int $offset = 0): array {
    $events = getEventsFromDb($filters, $limit, $offset);
    return array_map('formatEventForUi', $events);
}

function getFilterOptions(string $column): array {
    $pdo = getDatabase();
    $stmt = $pdo->query("SELECT DISTINCT {$column} AS value FROM events WHERE {$column} != '' ORDER BY {$column} ASC");
    return array_column($stmt->fetchAll(), 'value');
}

function getStatsSummary(): array {
    $pdo = getDatabase();
    $now = new DateTimeImmutable('now');
    $since24h = $now->modify('-24 hours')->format('c');
    $since7d = $now->modify('-7 days')->format('c');
    $since30d = $now->modify('-30 days')->format('c');

    // Exkludera alla sammanfattningar (Sammanfattning natt, etc.)
    $excludePattern = '%Sammanfattning%';

    // Senaste 24h (exkl. sammanfattningar)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event_time >= ? AND type NOT LIKE ?");
    $stmt->execute([$since24h, $excludePattern]);
    $last24h = (int) $stmt->fetchColumn();

    // Senaste 7 dagar (exkl. sammanfattningar)
    $stmt->execute([$since7d, $excludePattern]);
    $last7d = (int) $stmt->fetchColumn();

    // Senaste 30 dagar (exkl. sammanfattningar)
    $stmt->execute([$since30d, $excludePattern]);
    $last30d = (int) $stmt->fetchColumn();

    // Totalt antal (exkl. sammanfattningar)
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE type NOT LIKE ?");
    $totalStmt->execute([$excludePattern]);
    $total = (int) $totalStmt->fetchColumn();

    // Äldsta händelsen för att beräkna genomsnitt
    $oldestStmt = $pdo->prepare("SELECT MIN(event_time) FROM events WHERE type NOT LIKE ?");
    $oldestStmt->execute([$excludePattern]);
    $oldestDate = $oldestStmt->fetchColumn();

    $avgPerDay = 0;
    if ($oldestDate) {
        $oldest = new DateTimeImmutable($oldestDate);
        $daysDiff = max(1, (int) $now->diff($oldest)->days);
        $avgPerDay = round($total / $daysDiff, 1);
    }

    // Topp händelsetyper (exkl. sammanfattningar)
    $topTypesStmt = $pdo->prepare("SELECT type AS label, COUNT(*) AS total FROM events WHERE type NOT LIKE ? GROUP BY type ORDER BY total DESC LIMIT 8");
    $topTypesStmt->execute([$excludePattern]);
    $topTypes = $topTypesStmt->fetchAll();

    // Topp platser (exkl. sammanfattningar)
    $topLocationsStmt = $pdo->prepare("SELECT location_name AS label, COUNT(*) AS total FROM events WHERE type NOT LIKE ? GROUP BY location_name ORDER BY total DESC LIMIT 8");
    $topLocationsStmt->execute([$excludePattern]);
    $topLocations = $topLocationsStmt->fetchAll();

    // Per timme senaste 24h (exkl. sammanfattningar)
    $hourStmt = $pdo->prepare("SELECT strftime('%H', event_time) AS hour, COUNT(*) AS total FROM events WHERE event_time >= ? AND type NOT LIKE ? GROUP BY hour ORDER BY hour");
    $hourStmt->execute([$since24h, $excludePattern]);
    $hourly = array_fill(0, 24, 0);
    foreach ($hourStmt->fetchAll() as $row) {
        $hourly[(int) $row['hour']] = (int) $row['total'];
    }

    // Per veckodag senaste 30 dagarna (exkl. sammanfattningar)
    // SQLite: %w = weekday (0=Sunday, 1=Monday, ...)
    $weekdayStmt = $pdo->prepare("SELECT strftime('%w', event_time) AS weekday, COUNT(*) AS total FROM events WHERE event_time >= ? AND type NOT LIKE ? GROUP BY weekday ORDER BY weekday");
    $weekdayStmt->execute([$since30d, $excludePattern]);
    $weekdayData = array_fill(0, 7, 0);
    foreach ($weekdayStmt->fetchAll() as $row) {
        $weekdayData[(int) $row['weekday']] = (int) $row['total'];
    }
    // Konvertera till måndag-söndag ordning (svenskt)
    $weekdays = [
        $weekdayData[1], // Måndag
        $weekdayData[2], // Tisdag
        $weekdayData[3], // Onsdag
        $weekdayData[4], // Torsdag
        $weekdayData[5], // Fredag
        $weekdayData[6], // Lördag
        $weekdayData[0], // Söndag
    ];

    // Händelser per dag senaste 7 dagarna (för trendgraf)
    $dailyStmt = $pdo->prepare("SELECT date(event_time) AS day, COUNT(*) AS total FROM events WHERE event_time >= ? AND type NOT LIKE ? GROUP BY day ORDER BY day");
    $dailyStmt->execute([$since7d, $excludePattern]);
    $dailyData = [];
    foreach ($dailyStmt->fetchAll() as $row) {
        $dailyData[$row['day']] = (int) $row['total'];
    }
    // Fyll i alla 7 dagar
    $daily = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = $now->modify("-{$i} days")->format('Y-m-d');
        $daily[] = [
            'date' => $date,
            'day' => (new DateTimeImmutable($date))->format('D'),
            'count' => $dailyData[$date] ?? 0
        ];
    }

    return [
        'total' => $total,
        'last24h' => $last24h,
        'last7d' => $last7d,
        'last30d' => $last30d,
        'avgPerDay' => $avgPerDay,
        'topTypes' => $topTypes,
        'topLocations' => $topLocations,
        'hourly' => $hourly,
        'weekdays' => $weekdays,
        'daily' => $daily
    ];
}

function fetchDetailsText(string $url): ?string {
    $absoluteUrl = str_starts_with($url, 'http') ? $url : 'https://polisen.se' . $url;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: " . USER_AGENT . "\r\n",
            'timeout' => POLICE_API_TIMEOUT
        ]
    ]);
    $html = @file_get_contents($absoluteUrl, false, $context);
    if ($html === false) {
        return null;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//article//p");
    if (!$nodes || $nodes->length === 0) {
        $nodes = $xpath->query("//main//p");
    }

    $text = [];
    foreach ($nodes as $node) {
        $content = trim($node->textContent);
        if ($content) {
            $text[] = $content;
        }
    }

    if (!$text) {
        return null;
    }

    return implode("\n\n", array_slice($text, 0, 4));
}

/**
 * Get cached VMA data if still valid
 */
function getVmaCache(): ?array {
    if (!file_exists(VMA_CACHE_FILE)) {
        return null;
    }

    $cacheData = @file_get_contents(VMA_CACHE_FILE);
    if ($cacheData === false) {
        return null;
    }

    $cache = json_decode($cacheData, true);
    if (!is_array($cache) || !isset($cache['timestamp'], $cache['data'])) {
        return null;
    }

    // Check if cache is still valid
    if (time() - $cache['timestamp'] > VMA_CACHE_TTL) {
        return null;
    }

    return $cache['data'];
}

/**
 * Save VMA data to cache
 */
function saveVmaCache(array $data): void {
    $cache = [
        'timestamp' => time(),
        'data' => $data
    ];
    @file_put_contents(VMA_CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE));
}

/**
 * Parse ATOM feed entry into alert array
 */
function parseAtomEntry(SimpleXMLElement $entry, array $namespaces): ?array {
    // Get the entry ID
    $id = (string) $entry->id;
    if (empty($id)) {
        return null;
    }

    // Basic ATOM fields
    $title = (string) $entry->title;
    $summary = (string) $entry->summary;
    $content = (string) $entry->content;
    $published = (string) $entry->published;
    $updated = (string) $entry->updated;

    // Get link to web page
    $web = '';
    foreach ($entry->link as $link) {
        $rel = (string) $link['rel'];
        if ($rel === 'alternate' || empty($rel)) {
            $web = (string) $link['href'];
            break;
        }
    }

    // Try to get CAP (Common Alerting Protocol) data if available
    $cap = null;
    if (isset($namespaces['cap'])) {
        $cap = $entry->children($namespaces['cap']);
    }

    // Extract severity, urgency, certainty from CAP or content
    $severity = 'Unknown';
    $urgency = 'Unknown';
    $certainty = 'Unknown';
    $msgType = 'Alert';
    $instruction = '';
    $areas = [];
    $expires = null;

    if ($cap && count($cap) > 0) {
        $severity = (string) ($cap->severity ?? 'Unknown');
        $urgency = (string) ($cap->urgency ?? 'Unknown');
        $certainty = (string) ($cap->certainty ?? 'Unknown');
        $msgType = (string) ($cap->msgType ?? 'Alert');
        $instruction = (string) ($cap->instruction ?? '');
        $expires = (string) ($cap->expires ?? '');

        // Get areas from CAP
        foreach ($cap->area as $area) {
            $areaDesc = (string) ($area->areaDesc ?? '');
            if ($areaDesc) {
                $areas[] = $areaDesc;
            }
        }
    }

    // Parse areas from title or content if not found in CAP
    if (empty($areas)) {
        // Try to extract area from title (often format: "VMA: Area - Description")
        if (preg_match('/^VMA:\s*([^-–]+)\s*[-–]/u', $title, $matches)) {
            $areas[] = trim($matches[1]);
        }
    }

    // Use published or updated as sent time
    $sentAt = $published ?: $updated;

    return [
        'identifier' => $id,
        'headline' => $title,
        'description' => $content ?: $summary,
        'instruction' => $instruction,
        'severity' => $severity,
        'urgency' => $urgency,
        'certainty' => $certainty,
        'msgType' => $msgType,
        'areas' => $areas,
        'sent' => $sentAt,
        'expires' => $expires ?: null,
        'web' => $web
    ];
}

/**
 * Fetch VMA alerts from Sveriges Radio ATOM feed
 * Returns both current and recent historical alerts
 */
function fetchVmaAlerts(bool $forceRefresh = false): array {
    $result = [
        'success' => false,
        'current' => [],
        'recent' => [],
        'error' => null
    ];

    // Check cache first (unless force refresh)
    if (!$forceRefresh) {
        $cached = getVmaCache();
        if ($cached !== null) {
            return $cached;
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => VMA_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => VMA_API_TIMEOUT,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/atom+xml, application/xml, text/xml']
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        $result['error'] = $error ?: 'HTTP error ' . $status;
        return $result;
    }

    // Parse ATOM XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);
    if ($xml === false) {
        $result['error'] = 'Invalid XML response';
        return $result;
    }

    // Get namespaces used in the feed
    $namespaces = $xml->getNamespaces(true);

    $result['success'] = true;
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));

    // Process each entry in the feed
    foreach ($xml->entry as $entry) {
        $alertData = parseAtomEntry($entry, $namespaces);
        if ($alertData) {
            $formatted = formatVmaAlert($alertData, $now);
            if ($formatted) {
                if ($formatted['isActive']) {
                    $result['current'][] = $formatted;
                } else {
                    $result['recent'][] = $formatted;
                }
            }
        }
    }

    // Sort recent alerts by sent time (newest first)
    usort($result['recent'], function($a, $b) {
        return strtotime($b['sentAt']) - strtotime($a['sentAt']);
    });

    // Limit recent alerts to last 50 (increased since feed has all events)
    $result['recent'] = array_slice($result['recent'], 0, 50);

    // Save to cache
    saveVmaCache($result);

    return $result;
}

/**
 * Format a VMA alert for display
 */
function formatVmaAlert(array $alert, DateTimeImmutable $now): ?array {
    $identifier = $alert['identifier'] ?? $alert['id'] ?? null;
    if (!$identifier) {
        return null;
    }

    // Parse timestamps
    $sentAt = $alert['sent'] ?? $alert['sentAt'] ?? null;
    $expiresAt = $alert['expires'] ?? $alert['expiresAt'] ?? null;

    // Determine if alert is active
    $isActive = true;
    if ($expiresAt) {
        try {
            $expiresDate = new DateTimeImmutable($expiresAt);
            $isActive = $expiresDate > $now;
        } catch (Exception $e) {
            // Keep as active if date parsing fails
        }
    }

    // Get info section (first info element typically)
    $info = $alert['info'][0] ?? $alert['info'] ?? $alert;

    // Extract fields
    $headline = $info['headline'] ?? $alert['headline'] ?? 'VMA';
    $description = $info['description'] ?? $alert['description'] ?? '';
    $instruction = $info['instruction'] ?? $alert['instruction'] ?? '';
    $severity = $info['severity'] ?? $alert['severity'] ?? 'Unknown';
    $urgency = $info['urgency'] ?? $alert['urgency'] ?? 'Unknown';
    $certainty = $info['certainty'] ?? $alert['certainty'] ?? 'Unknown';
    $msgType = $alert['msgType'] ?? $alert['messageType'] ?? 'Alert';
    $web = $info['web'] ?? $alert['web'] ?? '';

    // Get areas
    $areas = [];
    $areaData = $info['area'] ?? $alert['areas'] ?? [];
    if (!is_array($areaData)) {
        $areaData = [$areaData];
    }
    foreach ($areaData as $area) {
        if (is_array($area)) {
            $areaName = $area['areaDesc'] ?? $area['description'] ?? $area['name'] ?? '';
        } else {
            $areaName = (string) $area;
        }
        if ($areaName) {
            $areas[] = $areaName;
        }
    }

    // Parse sent time for display
    $sentDate = null;
    $relativeTime = 'Okänd tid';
    if ($sentAt) {
        try {
            $sentDate = new DateTimeImmutable($sentAt);
            $relativeTime = formatRelativeTime($sentDate, $now);
        } catch (Exception $e) {
            // Keep default
        }
    }

    // Map severity to display values
    $severityMap = [
        'Extreme' => ['label' => 'Extrem', 'class' => 'vma-severity--extreme'],
        'Severe' => ['label' => 'Allvarlig', 'class' => 'vma-severity--severe'],
        'Moderate' => ['label' => 'Måttlig', 'class' => 'vma-severity--moderate'],
        'Minor' => ['label' => 'Mindre', 'class' => 'vma-severity--minor'],
        'Unknown' => ['label' => 'Okänd', 'class' => 'vma-severity--unknown']
    ];
    $severityInfo = $severityMap[$severity] ?? $severityMap['Unknown'];

    // Map message type
    $msgTypeMap = [
        'Alert' => 'Varning',
        'Update' => 'Uppdatering',
        'Cancel' => 'Avslutad',
        'Ack' => 'Bekräftelse',
        'Error' => 'Fel'
    ];
    $msgTypeLabel = $msgTypeMap[$msgType] ?? $msgType;

    return [
        'id' => $identifier,
        'headline' => $headline,
        'description' => $description,
        'instruction' => $instruction,
        'severity' => $severity,
        'severityLabel' => $severityInfo['label'],
        'severityClass' => $severityInfo['class'],
        'urgency' => $urgency,
        'certainty' => $certainty,
        'msgType' => $msgType,
        'msgTypeLabel' => $msgTypeLabel,
        'areas' => $areas,
        'areaText' => implode(', ', $areas) ?: 'Hela Sverige',
        'sentAt' => $sentAt,
        'sentDate' => $sentDate ? $sentDate->format('Y-m-d H:i') : '',
        'relativeTime' => $relativeTime,
        'expiresAt' => $expiresAt,
        'isActive' => $isActive,
        'web' => $web
    ];
}

// ============================================================================
// REQUEST HANDLING
// ============================================================================

$refreshStatus = refreshEventsIfNeeded();

$filters = [
    'location' => isset($_GET['location']) ? sanitizeLocation((string) $_GET['location']) : '',
    'type' => isset($_GET['type']) ? sanitizeType((string) $_GET['type']) : '',
    'search' => isset($_GET['search']) ? sanitizeSearch((string) $_GET['search']) : ''
];

$currentView = $_GET['view'] ?? 'list';
if (!in_array($currentView, ALLOWED_VIEWS, true)) {
    $currentView = 'list';
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['ajax'] === 'events') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * EVENTS_PER_PAGE;
        $events = getEventsForUi($filters, EVENTS_PER_PAGE, $offset);
        $total = countEventsInDb($filters);
        echo json_encode([
            'events' => $events,
            'hasMore' => ($offset + EVENTS_PER_PAGE) < $total
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_GET['ajax'] === 'details' && isset($_GET['url'])) {
        $details = fetchDetailsText(sanitizeInput((string) $_GET['url'], 500));
        echo json_encode([
            'success' => (bool) $details,
            'details' => ['content' => $details]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_GET['ajax'] === 'vma') {
        $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
        $vmaData = fetchVmaAlerts($forceRefresh);
        echo json_encode($vmaData, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['error' => 'Invalid endpoint'], JSON_UNESCAPED_UNICODE);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * EVENTS_PER_PAGE;
$events = getEventsForUi($filters, EVENTS_PER_PAGE, $offset);
$totalEvents = countEventsInDb($filters);
$hasMore = ($offset + EVENTS_PER_PAGE) < $totalEvents;
$mapEvents = getEventsForUi($filters, 500, 0);

$locations = getFilterOptions('location_name');
$types = getFilterOptions('type');
$stats = getStatsSummary();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '/') {
    $basePath = '';
}

?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sambandscentralen</title>
    <link rel="manifest" href="<?= esc($basePath) ?>/manifest.json">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='0.9em' font-size='90'>👮</text></svg>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="<?= esc($basePath) ?>/css/styles.css?v=<?= esc(ASSET_VERSION) ?>">
</head>
<body class="view-<?= esc($currentView) ?>">
<div class="container">
    <header>
        <div class="header-content">
            <a class="logo" href="<?= esc($basePath) ?>/">
                <div class="logo-icon">👮</div>
                <div class="logo-text">
                    <h1>Sambandscentralen</h1>
                    <p>Polisens händelsenotiser i realtid</p>
                </div>
            </a>
            <div class="header-controls">
                <div class="view-toggle">
                    <button type="button" data-view="list" class="<?= $currentView === 'list' ? 'active' : '' ?>">📋 <span class="label">Lista</span></button>
                    <button type="button" data-view="map" class="<?= $currentView === 'map' ? 'active' : '' ?>">🗺️ <span class="label">Karta</span></button>
                    <button type="button" data-view="stats" class="<?= $currentView === 'stats' ? 'active' : '' ?>">📊 <span class="label">Statistik</span></button>
                    <button type="button" data-view="vma" class="<?= $currentView === 'vma' ? 'active' : '' ?>">⚠️ <span class="label">VMA</span></button>
                </div>
                <div class="live-indicator"><span class="live-dot"></span> Live</div>
            </div>
        </div>
    </header>

    <section class="filters-section">
        <div class="search-bar">
            <form class="search-form" method="get">
                <input type="hidden" id="viewInput" name="view" value="<?= esc($currentView) ?>">
                <div class="search-input-wrapper">
                    <input class="search-input" id="searchInput" type="search" name="search" placeholder="Sök händelser..." value="<?= esc($filters['search']) ?>">
                </div>
                <select class="filter-select" id="locationSelect" name="location">
                    <option value="">Alla platser</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= esc($location) ?>" <?= $filters['location'] === $location ? 'selected' : '' ?>><?= esc($location) ?></option>
                    <?php endforeach; ?>
                    <option value="__custom__">Annan plats...</option>
                </select>
                <div class="custom-location-wrapper" id="customLocationWrapper" style="display:none;">
                    <input class="filter-input" id="customLocationInput" type="text" name="location" placeholder="Skriv plats" value="" data-initial-value="<?= esc($filters['location']) ?>">
                    <button type="button" class="custom-location-cancel" id="customLocationCancel">×</button>
                </div>
                <select class="filter-select" name="type">
                    <option value="">Alla typer</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= esc($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= esc($type) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">Filtrera</button>
            </form>
        </div>
        <?php if ($filters['location'] || $filters['type'] || $filters['search']): ?>
            <div class="active-filters">
                <?php if ($filters['location']): ?>
                    <span class="filter-tag">📍 <?= esc($filters['location']) ?> <a href="?view=<?= esc($currentView) ?>&type=<?= esc($filters['type']) ?>&search=<?= esc($filters['search']) ?>">×</a></span>
                <?php endif; ?>
                <?php if ($filters['type']): ?>
                    <span class="filter-tag">🏷️ <?= esc($filters['type']) ?> <a href="?view=<?= esc($currentView) ?>&location=<?= esc($filters['location']) ?>&search=<?= esc($filters['search']) ?>">×</a></span>
                <?php endif; ?>
                <?php if ($filters['search']): ?>
                    <span class="filter-tag">🔍 <?= esc($filters['search']) ?> <a href="?view=<?= esc($currentView) ?>&location=<?= esc($filters['location']) ?>&type=<?= esc($filters['type']) ?>">×</a></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <main class="main-content">
        <div class="content-area">
            <section id="eventsGrid" class="events-grid">
                <?php if (!$events): ?>
                    <div class="press-empty">
                        <div class="press-empty-icon">📭</div>
                        <h3>Inga händelser</h3>
                        <p>Inga händelser hittades för dina filter.</p>
                    </div>
                <?php endif; ?>
                <?php foreach ($events as $event): ?>
                    <?php $typeClass = getTypeClass($event['type']); ?>
                    <article class="event-card" data-url="<?= esc($event['url'] ?? '') ?>">
                        <div class="event-card-header" tabindex="0" role="button" aria-expanded="false" aria-label="Expandera händelse: <?= esc($event['type']) ?> i <?= esc($event['location']) ?>">
                            <div class="event-header-content">
                                <div class="event-meta-row">
                                    <span class="event-datetime"><?= esc($event['date']['day']) ?> <?= esc($event['date']['month']) ?> <?= esc($event['date']['time']) ?></span>
                                    <span class="meta-separator">•</span>
                                    <span class="event-relative"><?= esc($event['date']['relative']) ?></span>
                                    <?php if (!empty($event['url'])): ?>
                                        <span class="meta-separator">•</span>
                                        <a class="event-source" href="https://polisen.se<?= esc($event['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer" onclick="event.stopPropagation()">
                                            🔗 polisen.se
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($event['wasUpdated'] && $event['updated']): ?>
                                        <span class="updated-indicator" title="Uppdaterad <?= esc($event['updated']) ?>">✎ uppdaterad</span>
                                    <?php endif; ?>
                                </div>
                                <div class="event-title-group">
                                    <a href="?type=<?= esc($event['type']) ?>&view=<?= esc($currentView) ?>" class="event-type <?= esc($typeClass) ?>" onclick="event.stopPropagation()">
                                        <?= esc($event['icon']) ?> <?= esc($event['type']) ?>
                                    </a>
                                    <a href="?location=<?= esc($event['location']) ?>&view=<?= esc($currentView) ?>" class="event-location-link" onclick="event.stopPropagation()"><?= esc($event['location']) ?></a>
                                </div>
                                <p class="event-summary"><?= esc($event['summary']) ?></p>
                                <div class="event-header-actions">
                                    <button type="button" class="expand-details-btn">📖 Läs mer</button>
                                    <?php if (!empty($event['gps'])): ?>
                                        <?php
                                        $gpsParts = explode(',', $event['gps']);
                                        $latStr = isset($gpsParts[0]) ? trim($gpsParts[0]) : '';
                                        $lngStr = isset($gpsParts[1]) ? trim($gpsParts[1]) : '';
                                        if ($latStr !== '' && is_numeric($latStr) && $lngStr !== '' && is_numeric($lngStr)):
                                            $lat = (float) $latStr;
                                            $lng = (float) $lngStr;
                                        ?>
                                        <button type="button" class="show-map-link" data-lat="<?= esc((string)$lat) ?>" data-lng="<?= esc((string)$lng) ?>" data-location="<?= esc($event['location']) ?>">🗺️ Visa på karta</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="accordion-chevron">
                            </span>
                        </div>
                        <div class="event-card-body">
                            <div class="event-details"></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="load-more-container">
                <button id="loadMoreBtn" class="load-more-btn" type="button">
                    Ladda fler
                </button>
            </div>

            <div id="mapContainer" class="map-container">
                <div id="map" style="height:100%;"></div>
            </div>

        </div>

        <aside id="statsSidebar" class="stats-sidebar">
            <div class="stats-grid">
                <!-- Nyckeltal rad -->
                <div class="stats-metrics">
                    <div class="metric">
                        <span class="metric-value"><?= esc((string) $stats['total']) ?></span>
                        <span class="metric-label">Totalt</span>
                    </div>
                    <div class="metric">
                        <span class="metric-value"><?= esc((string) $stats['last24h']) ?></span>
                        <span class="metric-label">24h</span>
                    </div>
                    <div class="metric">
                        <span class="metric-value"><?= esc((string) $stats['last7d']) ?></span>
                        <span class="metric-label">7 dagar</span>
                    </div>
                    <div class="metric">
                        <span class="metric-value"><?= esc((string) $stats['last30d']) ?></span>
                        <span class="metric-label">30 dagar</span>
                    </div>
                    <div class="metric metric-avg">
                        <span class="metric-value">~<?= esc((string) $stats['avgPerDay']) ?></span>
                        <span class="metric-label">per dag</span>
                    </div>
                </div>

                <!-- Trend 7 dagar -->
                <div class="stats-card">
                    <h3>Senaste 7 dagarna</h3>
                    <div class="trend-chart">
                        <?php
                        $maxDaily = max(array_column($stats['daily'], 'count')) ?: 1;
                        foreach ($stats['daily'] as $day):
                            $pct = ($day['count'] / $maxDaily) * 100;
                        ?>
                            <div class="trend-col">
                                <div class="trend-bar-wrap">
                                    <div class="trend-bar" style="height: <?= $pct ?>%;" title="<?= esc($day['date']) ?>"></div>
                                </div>
                                <span class="trend-val"><?= esc((string) $day['count']) ?></span>
                                <span class="trend-day"><?= esc(substr($day['day'], 0, 2)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Rad med två diagram -->
                <div class="stats-row">
                    <div class="stats-card">
                        <h3>Per veckodag</h3>
                        <div class="bar-chart bar-chart-weekday">
                            <?php
                            $weekdayNames = ['M', 'Ti', 'O', 'To', 'F', 'L', 'S'];
                            $maxWeekday = max($stats['weekdays']) ?: 1;
                            foreach ($stats['weekdays'] as $i => $count):
                                $pct = ($count / $maxWeekday) * 100;
                            ?>
                                <div class="bar-col">
                                    <div class="bar-wrap"><div class="bar" style="height: <?= $pct ?>%;"></div></div>
                                    <span class="bar-label"><?= esc($weekdayNames[$i]) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="stats-card">
                        <h3>Per timme</h3>
                        <div class="bar-chart bar-chart-hourly">
                            <?php
                            $maxHourly = max($stats['hourly']) ?: 1;
                            foreach ($stats['hourly'] as $hour => $count):
                                $pct = ($count / $maxHourly) * 100;
                            ?>
                                <div class="bar-col bar-col-hour" title="<?= sprintf('%02d', $hour) ?>:00: <?= esc((string) $count) ?>">
                                    <div class="bar-wrap"><div class="bar" style="height: <?= $pct ?>%;"></div></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="hour-axis"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
                    </div>
                </div>

                <!-- Rad med topp-listor -->
                <div class="stats-row">
                    <div class="stats-card">
                        <h3>Vanligaste händelser</h3>
                        <div class="top-list">
                            <?php foreach ($stats['topTypes'] as $row):
                                $pct = $stats['total'] > 0 ? round(($row['total'] / $stats['total']) * 100) : 0;
                            ?>
                                <div class="top-item">
                                    <span class="top-name"><?= esc($row['label']) ?></span>
                                    <div class="top-bar-wrap">
                                        <div class="top-bar" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                    <span class="top-count"><?= esc((string) $row['total']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="stats-card">
                        <h3>Vanligaste platser</h3>
                        <div class="top-list">
                            <?php foreach ($stats['topLocations'] as $row):
                                $pct = $stats['total'] > 0 ? round(($row['total'] / $stats['total']) * 100) : 0;
                            ?>
                                <div class="top-item">
                                    <span class="top-name"><?= esc($row['label']) ?></span>
                                    <div class="top-bar-wrap">
                                        <div class="top-bar" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                    <span class="top-count"><?= esc((string) $row['total']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <section id="vmaContainer" class="vma-container">
            <div class="vma-header">
                <div class="vma-title">
                    <h2>⚠️ Viktigt Meddelande till Allmänheten</h2>
                    <p>Aktuella och senaste VMA från Sveriges Radio</p>
                </div>
                <button type="button" id="vmaRefreshBtn" class="btn btn-secondary">🔄 Uppdatera</button>
            </div>

            <div id="vmaContent" class="vma-content">
                <div class="vma-loading">
                    <div class="spinner"></div>
                    <p>Hämtar VMA-meddelanden...</p>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="footer-status">
            <div class="status-counts">
                <span class="count-item">Lagrade händelser: <span class="count-value" id="storedCount"><?= esc((string) $stats['total']) ?></span></span>
                <span class="count-item">Visar: <span class="count-value" id="shownCount"><?= esc((string) count($events)) ?></span> av <span class="count-value" id="totalFilteredCount"><?= esc((string) $stats['total']) ?></span></span>
            </div>
        </div>
        <nav class="footer-links">
            <a href="https://polisen.se" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">Polisen</a>
            <a href="https://www.domstol.se" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">Sveriges Domstolar</a>
            <a href="https://www.aklagare.se" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">Åklagarmyndigheten</a>
            <a href="https://www.msb.se" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">MSB</a>
            <a href="https://www.krisinformation.se" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">Krisinformation</a>
            <a href="https://www.svt.se/nyheter/vma" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">VMA</a>
        </nav>
    </footer>
</div>

<button id="scrollTop" class="scroll-top" type="button" aria-label="Scrolla till toppen"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>

<div id="installPrompt" class="install-prompt">
    <h4>Installera Sambandscentralen</h4>
    <p>Lägg till appen på hemskärmen för snabb åtkomst.</p>
    <div class="install-prompt-buttons">
        <button id="installBtn" class="install-btn" type="button">Installera</button>
        <button id="dismissInstall" class="dismiss-btn" type="button">Inte nu</button>
    </div>
</div>

<div id="mapModalOverlay" class="map-modal-overlay">
    <div class="map-modal">
        <div class="map-modal-header">
            <h3 id="mapModalTitle">📍 Plats</h3>
            <button id="mapModalClose" class="map-modal-close" type="button">✕</button>
        </div>
        <div class="map-modal-body">
            <div id="modalMap" style="height:100%;"></div>
        </div>
        <div class="map-modal-footer">
            <span id="mapModalCoords" class="coords"></span>
            <a id="mapModalGoogleLink" href="#" target="_blank" rel="noopener noreferrer">Google Maps</a>
            <a id="mapModalAppleLink" href="#" target="_blank" rel="noopener noreferrer">Apple Maps</a>
        </div>
    </div>
</div>

<script>
    window.CONFIG = {
        currentView: <?= json_encode($currentView, JSON_UNESCAPED_UNICODE) ?>,
        basePath: <?= json_encode($basePath, JSON_UNESCAPED_UNICODE) ?>,
        hasMore: <?= json_encode($hasMore) ?>,
        filters: {
            location: <?= json_encode($filters['location'], JSON_UNESCAPED_UNICODE) ?>,
            type: <?= json_encode($filters['type'], JSON_UNESCAPED_UNICODE) ?>,
            search: <?= json_encode($filters['search'], JSON_UNESCAPED_UNICODE) ?>
        }
    };
    window.eventsData = <?= json_encode($mapEvents, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="<?= esc($basePath) ?>/js/app.js?v=<?= esc(ASSET_VERSION) ?>"></script>
</body>
</html>
