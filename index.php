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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://*.basemaps.cartocdn.com; connect-src 'self'");

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
define('ASSET_VERSION', '5.7.0');    // Bump this to bust browser cache
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
        'Inbrott' => ['icon' => '🔓', 'color' => '#f97316'],
        'Brand' => ['icon' => '🔥', 'color' => '#ef4444'],
        'Rån' => ['icon' => '💰', 'color' => '#f59e0b'],
        'Trafikolycka' => ['icon' => '🚗', 'color' => '#3b82f6'],
        'Misshandel' => ['icon' => '👊', 'color' => '#ef4444'],
        'Skadegörelse' => ['icon' => '🔨', 'color' => '#f59e0b'],
        'Bedrägeri' => ['icon' => '🕵️', 'color' => '#8b5cf6'],
        'Narkotikabrott' => ['icon' => '💊', 'color' => '#10b981'],
        'Ofredande' => ['icon' => '🚨', 'color' => '#f43f5e'],
        'Sammanfattning' => ['icon' => '📊', 'color' => '#22c55e'],
        'default' => ['icon' => '📌', 'color' => '#fcd34d']
    ];
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

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event_time >= ?");
    $stmt->execute([$since24h]);
    $last24h = (int) $stmt->fetchColumn();

    $stmt->execute([$since7d]);
    $last7d = (int) $stmt->fetchColumn();

    $total = (int) $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();

    $topTypes = $pdo->query("SELECT type AS label, COUNT(*) AS total FROM events GROUP BY type ORDER BY total DESC LIMIT 5")->fetchAll();
    $topLocations = $pdo->query("SELECT location_name AS label, COUNT(*) AS total FROM events GROUP BY location_name ORDER BY total DESC LIMIT 5")->fetchAll();

    $hourStmt = $pdo->prepare("SELECT strftime('%H', event_time) AS hour, COUNT(*) AS total FROM events WHERE event_time >= ? GROUP BY hour ORDER BY hour");
    $hourStmt->execute([$since24h]);
    $hourly = array_fill(0, 24, 0);
    foreach ($hourStmt->fetchAll() as $row) {
        $hourly[(int) $row['hour']] = (int) $row['total'];
    }

    return [
        'total' => $total,
        'last24h' => $last24h,
        'last7d' => $last7d,
        'topTypes' => $topTypes,
        'topLocations' => $topLocations,
        'hourly' => $hourly
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

    if ($_GET['ajax'] === 'press') {
        echo json_encode([
            'items' => [],
            'hasMore' => false
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_GET['ajax'] === 'pressdetails' && isset($_GET['url'])) {
        $details = fetchDetailsText(sanitizeInput((string) $_GET['url'], 500));
        echo json_encode([
            'success' => (bool) $details,
            'details' => ['content' => $details]
        ], JSON_UNESCAPED_UNICODE);
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
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='0.9em' font-size='90'>📻</text></svg>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="<?= esc($basePath) ?>/css/styles.css?v=<?= esc(ASSET_VERSION) ?>">
</head>
<body class="view-<?= esc($currentView) ?>">
<div class="container">
    <header>
        <div class="header-content">
            <a class="logo" href="<?= esc($basePath) ?>/">
                <div class="logo-icon">📻</div>
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
                    <button type="button" data-view="press" class="<?= $currentView === 'press' ? 'active' : '' ?>">📰 <span class="label">Press</span></button>
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
                    <input class="filter-input" id="customLocationInput" type="text" name="location" placeholder="Skriv plats" value="<?= esc($filters['location']) ?>">
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
                    <article class="event-card">
                        <div class="event-card-inner">
                            <div class="event-date">
                                <div class="day"><?= esc($event['date']['day']) ?></div>
                                <div class="month"><?= esc($event['date']['month']) ?></div>
                                <div class="time"><?= esc($event['date']['time']) ?></div>
                                <div class="relative"><?= esc($event['date']['relative']) ?></div>
                                <?php if ($event['wasUpdated'] && $event['updated']): ?>
                                    <div class="updated-indicator" title="Uppdaterad <?= esc($event['updated']) ?>">uppdaterad <?= esc($event['updated']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="event-content">
                                <div class="event-header">
                                    <div class="event-title-group">
                                        <a href="?type=<?= esc($event['type']) ?>&view=<?= esc($currentView) ?>" class="event-type" style="background:<?= esc($event['color']) ?>20;color:<?= esc($event['color']) ?>">
                                            <?= esc($event['icon']) ?> <?= esc($event['type']) ?>
                                        </a>
                                        <a href="?location=<?= esc($event['location']) ?>&view=<?= esc($currentView) ?>" class="event-location-link"><?= esc($event['location']) ?></a>
                                    </div>
                                </div>
                                <p class="event-summary"><?= esc($event['summary']) ?></p>
                                <div class="event-meta">
                                    <?php if (!empty($event['url'])): ?>
                                        <button type="button" class="show-details-btn" data-url="<?= esc($event['url']) ?>">📖 Visa detaljer</button>
                                    <?php endif; ?>
                                    <?php if (!empty($event['gps'])): ?>
                                        <?php [$lat, $lng] = array_map('trim', explode(',', $event['gps'] . ',')); ?>
                                        <button type="button" class="show-map-btn" data-lat="<?= esc($lat) ?>" data-lng="<?= esc($lng) ?>" data-location="<?= esc($event['location']) ?>">🗺️ Visa på karta</button>
                                    <?php endif; ?>
                                    <?php if (!empty($event['url'])): ?>
                                        <a class="read-more-link" href="https://polisen.se<?= esc($event['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">
                                            <span>🔗</span> polisen.se
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="event-details"></div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div id="loadingMore" class="press-loading" style="display:none;">
                <div class="spinner"></div>
                <p>Laddar fler händelser...</p>
            </div>

            <div id="mapContainer" class="map-container">
                <div id="map" style="height:100%;"></div>
            </div>

            <section id="pressSection" class="press-section">
                <div class="press-header">
                    <h2>Pressmeddelanden</h2>
                    <p>Senaste pressmeddelanden från Polisen</p>
                </div>
                <div class="press-filters">
                    <div class="press-search-wrapper">
                        <input type="search" id="pressSearch" class="press-search" placeholder="Sök pressmeddelanden...">
                    </div>
                    <select id="pressRegionSelect" class="press-region-select">
                        <option value="">Alla regioner</option>
                        <option value="bergslagen">Bergslagen</option>
                        <option value="mitt">Mitt</option>
                        <option value="nord">Nord</option>
                        <option value="stockholm">Stockholm</option>
                        <option value="syd">Syd</option>
                        <option value="vast">Väst</option>
                        <option value="ost">Öst</option>
                    </select>
                </div>
                <div id="pressGrid" class="press-grid"></div>
                <div id="pressLoadMore" class="press-load-more">
                    <button type="button" id="pressLoadMoreBtn" class="btn btn-secondary">Ladda fler</button>
                </div>
            </section>
        </div>

        <aside id="statsSidebar" class="stats-sidebar">
            <div class="stats-card">
                <h3>📊 Översikt</h3>
                <div class="stat-number"><?= esc((string) $stats['total']) ?></div>
                <div class="stat-label">Totalt antal händelser</div>
            </div>
            <div class="stats-card">
                <h3>⏱️ Senaste 24h</h3>
                <div class="stat-number"><?= esc((string) $stats['last24h']) ?></div>
                <div class="stat-label">Händelser senaste dygnet</div>
            </div>
            <div class="stats-card">
                <h3>📅 Senaste 7 dagar</h3>
                <div class="stat-number"><?= esc((string) $stats['last7d']) ?></div>
                <div class="stat-label">Händelser senaste veckan</div>
            </div>
            <div class="stats-card">
                <h3>🏷️ Vanligaste typer</h3>
                <?php foreach ($stats['topTypes'] as $row): ?>
                    <div class="stat-row">
                        <div class="stat-row-label"><?= esc($row['label']) ?></div>
                        <div class="stat-row-value"><?= esc((string) $row['total']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="stats-card">
                <h3>📍 Vanliga platser</h3>
                <?php foreach ($stats['topLocations'] as $row): ?>
                    <div class="stat-row">
                        <div class="stat-row-label"><?= esc($row['label']) ?></div>
                        <div class="stat-row-value"><?= esc((string) $row['total']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="stats-card">
                <h3>🕒 Per timme (24h)</h3>
                <div class="hour-chart">
                    <?php foreach ($stats['hourly'] as $count): ?>
                        <div class="hour-bar" style="height: <?= 2 + ($count * 4) ?>px;"></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>
    </main>

    <footer>
        <p>Data från <a href="https://polisen.se" target="_blank" rel="noopener noreferrer">Polisen</a>. <?= esc((string) $totalEvents) ?> händelser i arkivet.</p>
        <p class="api-status <?= $refreshStatus['success'] ? 'online' : 'offline' ?>">
            API-status: <?= $refreshStatus['success'] ? 'Online' : 'Offline' ?>
        </p>
    </footer>
</div>

<button id="scrollTop" class="scroll-top" type="button">⬆️</button>

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
