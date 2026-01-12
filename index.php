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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://*.basemaps.cartocdn.com https://*.tile.openstreetmap.org; connect-src 'self'");

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
 * The Police API datetime often represents publish/update time, not event time
 * This function attempts to extract the real event time from the summary
 */
function extractEventTime(array $event): ?string {
    $summary = $event['summary'] ?? '';
    $name = $event['name'] ?? '';
    $apiDatetime = $event['datetime'] ?? null;
    $type = $event['type'] ?? '';

    // For summaries, try to extract the time period they cover
    if (stripos($type, 'Sammanfattning') !== false || stripos($name, 'Sammanfattning') !== false) {
        // Look for patterns like "kl 06-14" or "06.00-14.00" or "morgon" etc.
        // Summaries should be sorted to the START of their period, not when published
        if (preg_match('/kl\.?\s*(\d{1,2})[:\.]?(\d{2})?\s*[-–]\s*(\d{1,2})/i', $summary, $m)) {
            // Found a time range, use the start time
            $startHour = intval($m[1]);
            if ($apiDatetime) {
                try {
                    $date = new DateTime($apiDatetime);
                    $date->setTime($startHour, 0, 0);
                    return $date->format('c');
                } catch (Exception $e) {}
            }
        }
        // For daily summaries without specific times, set to start of day
        if (preg_match('/(dygn|dag|natt|kväll|morgon)/i', $summary)) {
            if ($apiDatetime) {
                try {
                    $date = new DateTime($apiDatetime);
                    // If it mentions "natt" or "kväll", might be previous day
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

    // For regular events, look for specific time mentions in summary
    // E.g., "Klockan 14.30 larmades polisen..."
    if (preg_match('/[Kk]l(?:ockan)?\.?\s*(\d{1,2})[:\.](\d{2})/', $summary, $m)) {
        $hour = intval($m[1]);
        $minute = intval($m[2]);
        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
            if ($apiDatetime) {
                try {
                    $date = new DateTime($apiDatetime);
                    // If the mentioned time is later than the API datetime, it might be from yesterday
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

    // Default: use the API datetime as event_time
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
 * Returns: 'new' if new event, 'updated' if content changed, 'unchanged' if no change
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

    // Sort by event_time (when the event occurred) so newest events appear first
    $query .= " ORDER BY event_time DESC LIMIT ? OFFSET ?";
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
 * Get all unique locations with event counts
 */
function getLocationsFromDb(): array {
    $pdo = getDatabase();
    $stmt = $pdo->query("
        SELECT location_name as name, COUNT(*) as count
        FROM events
        GROUP BY location_name
        ORDER BY count DESC
    ");
    return $stmt->fetchAll();
}

/**
 * Get all unique event types with counts
 */
function getTypesFromDb(): array {
    $pdo = getDatabase();
    $stmt = $pdo->query("
        SELECT type, COUNT(*) as count
        FROM events
        GROUP BY type
        ORDER BY count DESC
    ");
    return $stmt->fetchAll();
}

/**
 * Get database statistics
 */
function getDatabaseStats(): array {
    $pdo = getDatabase();

    // Total events
    $total = $pdo->query("SELECT COUNT(*) as count FROM events")->fetch()['count'];

    // Location count
    $locationCount = $pdo->query("SELECT COUNT(DISTINCT location_name) as count FROM events")->fetch()['count'];

    // Date range
    $dateRange = $pdo->query("SELECT MIN(datetime) as oldest, MAX(datetime) as newest FROM events")->fetch();

    // Database file size
    $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;

    // Last fetch
    $lastFetch = $pdo->query("
        SELECT fetched_at, events_new FROM fetch_log
        WHERE success = 1 ORDER BY fetched_at DESC LIMIT 1
    ")->fetch();

    return [
        'total_events' => $total,
        'unique_locations' => $locationCount,
        'date_range' => [
            'oldest' => $dateRange['oldest'] ?? null,
            'newest' => $dateRange['newest'] ?? null
        ],
        'database_size_mb' => round($dbSize / (1024 * 1024), 2),
        'last_fetch' => $lastFetch ? [
            'at' => $lastFetch['fetched_at'],
            'new_events' => $lastFetch['events_new']
        ] : null
    ];
}

/**
 * Get extended database statistics for status page
 */
function getExtendedStats(): array {
    $pdo = getDatabase();

    // Basic stats
    $total = $pdo->query("SELECT COUNT(*) as count FROM events")->fetch()['count'];
    $locationCount = $pdo->query("SELECT COUNT(DISTINCT location_name) as count FROM events")->fetch()['count'];
    $typeCount = $pdo->query("SELECT COUNT(DISTINCT type) as count FROM events")->fetch()['count'];

    // Date range
    $dateRange = $pdo->query("SELECT MIN(datetime) as oldest, MAX(datetime) as newest FROM events")->fetch();

    // Time-based stats
    $last24h = $pdo->query("SELECT COUNT(*) as count FROM events WHERE datetime >= datetime('now', '-1 day')")->fetch()['count'];
    $last7days = $pdo->query("SELECT COUNT(*) as count FROM events WHERE datetime >= datetime('now', '-7 days')")->fetch()['count'];
    $last30days = $pdo->query("SELECT COUNT(*) as count FROM events WHERE datetime >= datetime('now', '-30 days')")->fetch()['count'];
    $last6months = $pdo->query("SELECT COUNT(*) as count FROM events WHERE datetime >= datetime('now', '-6 months')")->fetch()['count'];
    $last1year = $pdo->query("SELECT COUNT(*) as count FROM events WHERE datetime >= datetime('now', '-1 year')")->fetch()['count'];

    // Monthly breakdown (last 12 months)
    $monthlyStats = $pdo->query("
        SELECT strftime('%Y-%m', datetime) as month, COUNT(*) as count
        FROM events
        WHERE datetime >= datetime('now', '-12 months')
        GROUP BY strftime('%Y-%m', datetime)
        ORDER BY month DESC
    ")->fetchAll();

    // Weekly breakdown (last 8 weeks)
    $weeklyStats = $pdo->query("
        SELECT strftime('%Y-W%W', datetime) as week, COUNT(*) as count
        FROM events
        WHERE datetime >= datetime('now', '-8 weeks')
        GROUP BY strftime('%Y-W%W', datetime)
        ORDER BY week DESC
    ")->fetchAll();

    // Top locations
    $topLocations = $pdo->query("
        SELECT location_name, COUNT(*) as count
        FROM events
        GROUP BY location_name
        ORDER BY count DESC
        LIMIT 15
    ")->fetchAll();

    // Top types
    $topTypes = $pdo->query("
        SELECT type, COUNT(*) as count
        FROM events
        GROUP BY type
        ORDER BY count DESC
        LIMIT 15
    ")->fetchAll();

    // Fetch log stats
    $fetchStats = $pdo->query("
        SELECT
            COUNT(*) as total_fetches,
            SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
            SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed,
            SUM(events_new) as total_new_events,
            MAX(fetched_at) as last_fetch
        FROM fetch_log
    ")->fetch();

    // Fetch success rate (last 24 hours)
    $recentFetchStats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful
        FROM fetch_log
        WHERE fetched_at >= datetime('now', '-24 hours')
    ")->fetch();
    $fetchSuccessRate = $recentFetchStats['total'] > 0
        ? round(($recentFetchStats['successful'] / $recentFetchStats['total']) * 100, 1)
        : 100;

    // Recent fetches
    $recentFetches = $pdo->query("
        SELECT fetched_at, events_fetched, events_new, success, error_message
        FROM fetch_log
        ORDER BY id DESC
        LIMIT 20
    ")->fetchAll();

    // Data freshness - time since last event
    $dataFreshness = null;
    if ($dateRange['newest']) {
        $newestTime = strtotime($dateRange['newest']);
        $dataFreshness = time() - $newestTime;
    }

    // Average events per day (last 30 days)
    $avgPerDay = $last30days > 0 ? round($last30days / 30, 1) : 0;

    // Daily breakdown (last 14 days) for chart
    $dailyStats = $pdo->query("
        SELECT date(datetime) as day, COUNT(*) as count
        FROM events
        WHERE datetime >= datetime('now', '-14 days')
        GROUP BY date(datetime)
        ORDER BY day ASC
    ")->fetchAll();

    // Hourly distribution for today
    $hourlyToday = $pdo->query("
        SELECT strftime('%H', datetime) as hour, COUNT(*) as count
        FROM events
        WHERE date(datetime) = date('now')
        GROUP BY strftime('%H', datetime)
        ORDER BY hour ASC
    ")->fetchAll();

    // Database integrity check
    $integrityCheck = $pdo->query("PRAGMA integrity_check")->fetch();
    $dbIntegrity = ($integrityCheck[0] ?? $integrityCheck['integrity_check'] ?? 'ok') === 'ok';

    // Recent errors (last 7 days)
    $recentErrors = $pdo->query("
        SELECT COUNT(*) as count FROM fetch_log
        WHERE success = 0 AND fetched_at >= datetime('now', '-7 days')
    ")->fetch()['count'];

    // Calculate health score (0-100)
    $healthScore = 100;
    if (!$dbIntegrity) $healthScore -= 50;
    if ($fetchSuccessRate < 90) $healthScore -= (90 - $fetchSuccessRate);
    if ($dataFreshness && $dataFreshness > 3600) $healthScore -= min(20, floor($dataFreshness / 3600));
    if ($recentErrors > 5) $healthScore -= min(20, $recentErrors * 2);
    $healthScore = max(0, min(100, $healthScore));

    // Database file size
    $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
    $walSize = file_exists(DB_PATH . '-wal') ? filesize(DB_PATH . '-wal') : 0;

    // Backup info
    $backups = [];
    if (is_dir(BACKUP_DIR)) {
        $files = glob(BACKUP_DIR . '/*.db');
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'created' => date('c', filemtime($file))
            ];
        }
        usort($backups, fn($a, $b) => strcmp($b['created'], $a['created']));
    }

    return [
        'total_events' => $total,
        'unique_locations' => $locationCount,
        'unique_types' => $typeCount,
        'date_range' => [
            'oldest' => $dateRange['oldest'],
            'newest' => $dateRange['newest']
        ],
        'time_stats' => [
            'last_24h' => $last24h,
            'last_7_days' => $last7days,
            'last_30_days' => $last30days,
            'last_6_months' => $last6months,
            'last_1_year' => $last1year
        ],
        'monthly' => $monthlyStats,
        'weekly' => $weeklyStats,
        'daily' => $dailyStats,
        'hourly_today' => $hourlyToday,
        'top_locations' => $topLocations,
        'top_types' => $topTypes,
        'fetch_stats' => $fetchStats,
        'fetch_success_rate' => $fetchSuccessRate,
        'recent_fetches' => $recentFetches,
        'database' => [
            'size_bytes' => $dbSize,
            'size_mb' => round($dbSize / (1024 * 1024), 2),
            'wal_size_bytes' => $walSize,
            'path' => DB_PATH,
            'integrity_ok' => $dbIntegrity
        ],
        'health' => [
            'score' => $healthScore,
            'data_freshness_seconds' => $dataFreshness,
            'recent_errors' => $recentErrors,
            'avg_events_per_day' => $avgPerDay
        ],
        'backups' => $backups,
        'server_time' => date('c'),
        'php_version' => PHP_VERSION
    ];
}

/**
 * Create a database backup
 */
function createBackup(): array {
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0755, true);
    }

    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = BACKUP_DIR . "/events_backup_{$timestamp}.db";

    try {
        // Use SQLite backup command via PHP
        $pdo = getDatabase();

        // Checkpoint WAL to ensure all data is in main db
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        // Copy the database file
        if (copy(DB_PATH, $backupFile)) {
            $size = filesize($backupFile);

            // Log the backup
            $stmt = $pdo->prepare("
                INSERT INTO backup_log (backup_at, filename, size_bytes, success, error_message)
                VALUES (?, ?, ?, 1, NULL)
            ");
            $stmt->execute([date('c'), basename($backupFile), $size]);

            // Clean old backups (keep last 10)
            $backups = glob(BACKUP_DIR . '/*.db');
            if (count($backups) > 10) {
                usort($backups, fn($a, $b) => filemtime($b) - filemtime($a));
                foreach (array_slice($backups, 10) as $oldBackup) {
                    @unlink($oldBackup);
                }
            }

            return [
                'success' => true,
                'filename' => basename($backupFile),
                'size' => $size,
                'path' => $backupFile
            ];
        } else {
            throw new Exception('Failed to copy database file');
        }
    } catch (Exception $e) {
        // Log failed backup
        try {
            $pdo = getDatabase();
            $stmt = $pdo->prepare("
                INSERT INTO backup_log (backup_at, filename, size_bytes, success, error_message)
                VALUES (?, ?, 0, 0, ?)
            ");
            $stmt->execute([date('c'), '', $e->getMessage()]);
        } catch (Exception $logError) {
            // Ignore logging errors
        }

        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check if weekly backup is needed and create one
 */
function checkWeeklyBackup(): void {
    $pdo = getDatabase();

    // Check last successful backup
    $lastBackup = $pdo->query("
        SELECT backup_at FROM backup_log
        WHERE success = 1
        ORDER BY id DESC LIMIT 1
    ")->fetch();

    $needsBackup = true;
    if ($lastBackup) {
        $lastBackupTime = strtotime($lastBackup['backup_at']);
        $weekAgo = strtotime('-7 days');
        $needsBackup = $lastBackupTime < $weekAgo;
    }

    if ($needsBackup) {
        createBackup();
    }
}

// ============================================================================
// FETCHING FUNCTIONS
// ============================================================================

/**
 * Fetch events from Police API with retry logic
 * Returns array with 'data' on success or 'error' on failure
 */
function fetchFromPoliceApi(): array {
    $lastError = '';

    for ($attempt = 1; $attempt <= MAX_FETCH_RETRIES; $attempt++) {
        $context = stream_context_create([
            'http' => [
                'timeout' => POLICE_API_TIMEOUT,
                'header' => "User-Agent: " . USER_AGENT . "\r\nAccept: application/json",
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);

        $response = @file_get_contents(POLICE_API_URL, false, $context);

        // Check HTTP response code
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }

        if ($response === false) {
            $lastError = "Connection failed (attempt $attempt/" . MAX_FETCH_RETRIES . ")";
            if ($attempt < MAX_FETCH_RETRIES) {
                usleep(500000 * $attempt); // 0.5s, 1s, 1.5s backoff
                continue;
            }
            return ['error' => $lastError, 'http_code' => 0];
        }

        if ($httpCode >= 400) {
            $lastError = "HTTP error $httpCode (attempt $attempt/" . MAX_FETCH_RETRIES . ")";
            if ($attempt < MAX_FETCH_RETRIES && $httpCode >= 500) {
                usleep(500000 * $attempt);
                continue;
            }
            return ['error' => $lastError, 'http_code' => $httpCode];
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            $lastError = "Invalid JSON response (attempt $attempt/" . MAX_FETCH_RETRIES . ")";
            if ($attempt < MAX_FETCH_RETRIES) {
                usleep(500000 * $attempt);
                continue;
            }
            return ['error' => $lastError, 'http_code' => $httpCode];
        }

        return ['data' => $data, 'http_code' => $httpCode];
    }

    return ['error' => $lastError ?: 'Unknown error', 'http_code' => 0];
}

/**
 * Fetch events from Police API and store in database
 */
function fetchAndStoreEvents(): array {
    $pdo = getDatabase();
    $result = fetchFromPoliceApi();

    if (isset($result['error'])) {
        $errorMsg = $result['error'] . ' (HTTP ' . ($result['http_code'] ?? 0) . ')';
        logFetch($pdo, 0, 0, false, $errorMsg);
        return ['success' => false, 'error' => $errorMsg];
    }

    $events = $result['data'];
    $newCount = 0;
    $updatedCount = 0;
    $pdo->beginTransaction();

    try {
        foreach ($events as $event) {
            // Validate required fields
            if (!isset($event['id'], $event['datetime'], $event['name'], $event['type'], $event['location'])) {
                continue;
            }

            $result = insertEvent($pdo, $event);
            if ($result === 'new') {
                $newCount++;
            } elseif ($result === 'updated') {
                $updatedCount++;
            }
        }

        $pdo->commit();
        logFetch($pdo, count($events), $newCount, true);

        return [
            'success' => true,
            'events_fetched' => count($events),
            'events_new' => $newCount,
            'events_updated' => $updatedCount
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        logFetch($pdo, count($events), 0, false, $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get last fetch time from database
 */
function getLastFetchTime(): ?int {
    try {
        $pdo = getDatabase();
        $row = $pdo->query("
            SELECT fetched_at FROM fetch_log
            WHERE success = 1
            ORDER BY id DESC LIMIT 1
        ")->fetch();

        if ($row) {
            return strtotime($row['fetched_at']);
        }
    } catch (Exception $e) {
        // Database might not exist yet
    }

    return null;
}

/**
 * Check if we need to fetch new data
 */
function needsFetch(): bool {
    $lastFetch = getLastFetchTime();

    if ($lastFetch === null) {
        return true; // Never fetched
    }

    return (time() - $lastFetch) >= CACHE_TIME;
}

/**
 * Ensure we have data - fetch if needed
 */
function ensureData(): void {
    // Use lock file to prevent concurrent fetches
    $lockFile = DATA_DIR . '/fetch.lock';

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    // Check if lock exists and is recent (within 60 seconds)
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) {
        return; // Another process is fetching
    }

    if (!needsFetch()) {
        return; // Data is fresh
    }

    // Create lock
    file_put_contents($lockFile, time());

    try {
        fetchAndStoreEvents();
        // Check if weekly backup is needed
        checkWeeklyBackup();
    } finally {
        @unlink($lockFile);
    }
}

// ============================================================================
// EVENT PROCESSING FUNCTIONS
// ============================================================================

/**
 * Fetch police events - from database with auto-refresh
 */
function fetchPoliceEvents($filters = []) {
    // Ensure we have data
    ensureData();

    // Get from database
    return getEventsFromDb($filters);
}

function getDetailCacheFilePath($eventUrl) {
    return sys_get_temp_dir() . '/police_event_detail_' . md5($eventUrl) . '.json';
}

function fetchEventDetails($eventUrl) {
    if (empty($eventUrl)) return null;

    // Validate URL format - must be a relative path starting with /
    if (!preg_match('#^/[a-zA-Z0-9/_\-\.]+$#', $eventUrl)) {
        return null;
    }

    // Check cache first (cache details for 1 hour)
    $cacheFile = getDetailCacheFilePath($eventUrl);
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached !== null) return $cached;
    }

    $fullUrl = 'https://polisen.se' . $eventUrl;

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: " . USER_AGENT . "\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: sv-SE,sv;q=0.9,en;q=0.8"
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $html = @file_get_contents($fullUrl, false, $context);
    if ($html === false) return null;

    $details = [];

    // Pattern 1: Look for the main event body content
    if (preg_match('/<div[^>]*class="[^"]*(?:text-body|body-content|article-body|event-body|hpt-body)[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
        $content = $matches[1];
        $content = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $content);
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = preg_replace('/\s+/', ' ', $content);
        $details['content'] = trim($content);
    }

    // Pattern 2: Look for main content area
    if (empty($details['content'])) {
        if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $matches)) {
            if (preg_match_all('/<p[^>]*class="[^"]*(?:ingress|preamble|lead|body|content)[^"]*"[^>]*>(.*?)<\/p>/is', $matches[1], $pMatches)) {
                $paragraphs = array_map(function($p) {
                    $text = strip_tags($p);
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                    return trim(preg_replace('/\s+/', ' ', $text));
                }, $pMatches[1]);
                $paragraphs = array_filter($paragraphs, fn($p) => strlen($p) > 15);
                if (!empty($paragraphs)) {
                    $details['content'] = implode("\n\n", $paragraphs);
                }
            }
        }
    }

    // Pattern 3: Extract from article element
    if (empty($details['content'])) {
        if (preg_match('/<article[^>]*>(.*?)<\/article>/is', $html, $matches)) {
            if (preg_match_all('/<p(?:\s[^>]*)?>([^<]{20,})<\/p>/is', $matches[1], $pMatches)) {
                $paragraphs = array_map(function($p) {
                    $text = strip_tags($p);
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                    return trim(preg_replace('/\s+/', ' ', $text));
                }, $pMatches[1]);
                $paragraphs = array_filter($paragraphs, function($p) {
                    return strlen($p) > 20 && !preg_match('/^(Dela|Skriv ut|Tipsa|Läs mer|Tillbaka)/i', $p);
                });
                if (!empty($paragraphs)) {
                    $details['content'] = implode("\n\n", $paragraphs);
                }
            }
        }
    }

    // Pattern 4: Last resort
    if (empty($details['content'])) {
        if (preg_match_all('/<(?:p|div)[^>]*>([^<]{50,})<\/(?:p|div)>/is', $html, $matches)) {
            $blocks = array_map(function($text) {
                $text = strip_tags($text);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                return trim(preg_replace('/\s+/', ' ', $text));
            }, $matches[1]);
            $blocks = array_filter($blocks, function($text) {
                return strlen($text) > 50 &&
                       strlen($text) < 2000 &&
                       !preg_match('/^(Copyright|Polisen|Kontakt|Dela|cookie)/i', $text) &&
                       preg_match('/[a-zåäö]{3,}/i', $text);
            });
            if (!empty($blocks)) {
                $details['content'] = implode("\n\n", array_slice(array_values($blocks), 0, 5));
            }
        }
    }

    if (!empty($details['content']) && strlen($details['content']) > 30) {
        file_put_contents($cacheFile, json_encode($details));
        return $details;
    }

    return null;
}

function formatDate($dateString) {
    try { $date = new DateTime($dateString); }
    catch (Exception $e) { $date = new DateTime(); }

    $months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    $days = ['söndag', 'måndag', 'tisdag', 'onsdag', 'torsdag', 'fredag', 'lördag'];

    return [
        'day' => $date->format('d'),
        'month' => $months[(int)$date->format('n') - 1],
        'weekday' => $days[(int)$date->format('w')],
        'time' => $date->format('H:i'),
        'full' => $date->format('Y-m-d H:i'),
        'relative' => getRelativeTime($date),
        'iso' => $date->format('c')
    ];
}

function getRelativeTime($date) {
    $now = new DateTime();
    $diff = $now->diff($date);
    if ($diff->invert == 0) return 'Kommande';
    if ($diff->days == 0) {
        if ($diff->h == 0) return $diff->i <= 1 ? 'Just nu' : $diff->i . ' min sedan';
        return $diff->h == 1 ? '1 timme sedan' : $diff->h . ' timmar sedan';
    }
    if ($diff->days == 1) return 'Igår';
    if ($diff->days < 7) return $diff->days . ' dagar sedan';
    return $date->format('d M');
}

function getEventIcon($type) {
    $icons = [
        'Trafikolycka' => '🚗', 'Misshandel' => '👊', 'Stöld' => '🔓', 'Inbrott' => '🏠',
        'Brand' => '🔥', 'Rån' => '💰', 'Skottlossning' => '🔫', 'Knivlagen' => '🔪',
        'Narkotikabrott' => '💊', 'Bedrägeri' => '📝', 'Rattfylleri' => '🍺',
        'Ordningslagen' => '📢', 'Trafikhinder' => '🚧', 'Motorfordon' => '🏍️',
        'Polisinsats' => '🚔', 'Mord' => '💀', 'Olaga hot' => '⚡', 'Våld' => '🛑',
        'Försvunnen' => '🔍', 'Sammanfattning' => '📋', 'Larm' => '🔔', 'Detonation' => '💥',
        'Trafikkontroll' => '🚦', 'Djur' => '🐾', 'Fylleri' => '🍻',
    ];
    foreach ($icons as $keyword => $icon) {
        if (stripos($type, $keyword) !== false) return $icon;
    }
    return '📌';
}

function getEventColor($type) {
    $colors = [
        'Trafikolycka' => '#e67e22', 'Misshandel' => '#e74c3c', 'Stöld' => '#9b59b6',
        'Inbrott' => '#8e44ad', 'Brand' => '#d35400', 'Rån' => '#c0392b',
        'Skottlossning' => '#c0392b', 'Mord' => '#7b241c', 'Sammanfattning' => '#2980b9',
        'Trafikkontroll' => '#27ae60', 'Rattfylleri' => '#f39c12', 'Försvunnen' => '#16a085',
    ];
    foreach ($colors as $keyword => $color) {
        if (stripos($type, $keyword) !== false) return $color;
    }
    return '#3498db';
}

function calculateStats($events) {
    if (!is_array($events) || isset($events['error'])) return null;

    $stats = ['total' => count($events), 'byType' => [], 'byLocation' => [],
              'byHour' => array_fill(0, 24, 0), 'byDay' => [], 'last24h' => 0, 'last7days' => 0, 'last6months' => 0, 'last1year' => 0];

    $now = new DateTime();
    $yesterday = (clone $now)->modify('-24 hours');
    $lastWeek = (clone $now)->modify('-7 days');
    $sixMonthsAgo = (clone $now)->modify('-6 months');
    $oneYearAgo = (clone $now)->modify('-1 year');

    foreach ($events as $event) {
        $type = $event['type'] ?? 'Okänd';
        $location = $event['location']['name'] ?? 'Okänd';
        $stats['byType'][$type] = ($stats['byType'][$type] ?? 0) + 1;
        $stats['byLocation'][$location] = ($stats['byLocation'][$location] ?? 0) + 1;

        try {
            // Use event_time for statistics (when event occurred), falling back to datetime
            $eventTimeStr = $event['event_time'] ?? $event['datetime'] ?? null;
            if (!$eventTimeStr) continue;
            $eventDate = new DateTime($eventTimeStr);
            $stats['byHour'][(int)$eventDate->format('H')]++;
            $dayKey = $eventDate->format('Y-m-d');
            $stats['byDay'][$dayKey] = ($stats['byDay'][$dayKey] ?? 0) + 1;
            if ($eventDate >= $yesterday) $stats['last24h']++;
            if ($eventDate >= $lastWeek) $stats['last7days']++;
            if ($eventDate >= $sixMonthsAgo) $stats['last6months']++;
            if ($eventDate >= $oneYearAgo) $stats['last1year']++;
        } catch (Exception $e) {}
    }

    arsort($stats['byType']); arsort($stats['byLocation']); krsort($stats['byDay']);
    $stats['byType'] = array_slice($stats['byType'], 0, 10, true);
    $stats['byLocation'] = array_slice($stats['byLocation'], 0, 10, true);
    $stats['byDay'] = array_slice($stats['byDay'], 0, 7, true);
    return $stats;
}

// Press releases RSS feeds configuration
define('PRESS_CACHE_TIME', 600); // 10 minutes cache for press releases

function getPressRegions() {
    return [
        'blekinge' => 'Blekinge',
        'dalarna' => 'Dalarna',
        'gotland' => 'Gotland',
        'gavleborg' => 'Gävleborg',
        'halland' => 'Halland',
        'jamtland' => 'Jämtland',
        'jonkopings-lan' => 'Jönköpings län',
        'kalmar-lan' => 'Kalmar län',
        'kronoberg' => 'Kronoberg',
        'norrbotten' => 'Norrbotten',
        'skane' => 'Skåne',
        'stockholms-lan' => 'Stockholms län',
        'sodermanland' => 'Södermanland',
        'uppsala-lan' => 'Uppsala län',
        'varmland' => 'Värmland',
        'vasterbotten' => 'Västerbotten',
        'vasternorrland' => 'Västernorrland',
        'vastmanland' => 'Västmanland',
        'vastra-gotaland' => 'Västra Götaland',
        'orebro-lan' => 'Örebro län',
        'ostergotland' => 'Östergötland'
    ];
}

function getPressCacheFilePath($region = 'all') {
    return sys_get_temp_dir() . '/police_press_' . md5($region) . '.json';
}

function fetchPressReleases($regionFilter = null) {
    $cacheKey = $regionFilter ?: 'all';
    $cacheFile = getPressCacheFilePath($cacheKey);

    // Check cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < PRESS_CACHE_TIME) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached !== null) return $cached;
    }

    $regions = getPressRegions();
    $allItems = [];

    $regionsToFetch = $regionFilter ? [$regionFilter => $regions[$regionFilter] ?? $regionFilter] : $regions;

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: " . USER_AGENT . "\r\nAccept: application/rss+xml, application/xml, text/xml"
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);

    foreach ($regionsToFetch as $slug => $name) {
        $url = "https://polisen.se/aktuellt/rss/{$slug}/press-rss---{$slug}/";
        $xml = @file_get_contents($url, false, $context);

        if ($xml === false) continue;

        libxml_use_internal_errors(true);
        $feed = @simplexml_load_string($xml);
        libxml_clear_errors();

        if ($feed === false || !isset($feed->channel->item)) continue;

        foreach ($feed->channel->item as $item) {
            $pubDate = (string)$item->pubDate;
            $timestamp = strtotime($pubDate);

            $allItems[] = [
                'title' => (string)$item->title,
                'description' => strip_tags((string)$item->description),
                'link' => (string)$item->link,
                'pubDate' => $pubDate,
                'timestamp' => $timestamp,
                'region' => $name,
                'regionSlug' => $slug
            ];
        }
    }

    usort($allItems, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    file_put_contents($cacheFile, json_encode($allItems));

    return $allItems;
}

function formatPressDate($timestamp) {
    $date = new DateTime('@' . $timestamp);
    $date->setTimezone(new DateTimeZone('Europe/Stockholm'));

    $months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    $days = ['söndag', 'måndag', 'tisdag', 'onsdag', 'torsdag', 'fredag', 'lördag'];

    $now = new DateTime();
    $diff = $now->diff($date);

    if ($diff->invert == 0) {
        $relative = 'Kommande';
    } elseif ($diff->days == 0) {
        if ($diff->h == 0) {
            $relative = $diff->i <= 1 ? 'Just nu' : $diff->i . ' min sedan';
        } else {
            $relative = $diff->h == 1 ? '1 timme sedan' : $diff->h . ' timmar sedan';
        }
    } elseif ($diff->days == 1) {
        $relative = 'Igår';
    } elseif ($diff->days < 7) {
        $relative = $diff->days . ' dagar sedan';
    } else {
        $relative = $date->format('d M');
    }

    return [
        'day' => $date->format('d'),
        'month' => $months[(int)$date->format('n') - 1],
        'weekday' => $days[(int)$date->format('w')],
        'time' => $date->format('H:i'),
        'full' => $date->format('Y-m-d H:i'),
        'relative' => $relative,
        'iso' => $date->format('c')
    ];
}

/**
 * Send HTTP cache headers for API responses
 */
function sendCacheHeaders($maxAge = 60, $staleWhileRevalidate = 120) {
    header('Cache-Control: public, max-age=' . $maxAge . ', stale-while-revalidate=' . $staleWhileRevalidate);
    header('Vary: Accept-Encoding');
}

// ============================================================================
// AJAX ENDPOINTS
// ============================================================================

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['ajax'] === 'events') {
        sendCacheHeaders(60, 300);

        $page = max(1, min(1000, intval($_GET['page'] ?? 1))); // Limit max page
        $filters = [];
        if (!empty($_GET['location'])) $filters['location'] = sanitizeLocation($_GET['location']);
        if (!empty($_GET['type'])) $filters['type'] = sanitizeType($_GET['type']);

        $allEvents = fetchPoliceEvents($filters);
        if (isset($allEvents['error'])) { echo json_encode(['error' => $allEvents['error']]); exit; }

        // Client-side type filtering as fallback
        $typeFilter = $filters['type'] ?? '';
        if ($typeFilter && is_array($allEvents)) {
            $allEvents = array_values(array_filter($allEvents, function($e) use ($typeFilter) {
                return isset($e['type']) && $e['type'] === $typeFilter;
            }));
        }

        $searchFilter = sanitizeSearch($_GET['search'] ?? '');
        if ($searchFilter) {
            $allEvents = array_values(array_filter($allEvents, function($e) use ($searchFilter) {
                return mb_stripos($e['name'] ?? '', $searchFilter) !== false ||
                       mb_stripos($e['summary'] ?? '', $searchFilter) !== false ||
                       mb_stripos($e['location']['name'] ?? '', $searchFilter) !== false;
            }));
        }

        $total = count($allEvents);
        $totalPages = ceil($total / EVENTS_PER_PAGE);
        $events = array_slice($allEvents, ($page - 1) * EVENTS_PER_PAGE, EVENTS_PER_PAGE);

        $formatted = array_map(function($e) {
            // Use event_time for display (when event occurred), falling back to datetime
            $eventTime = $e['event_time'] ?? $e['datetime'] ?? date('Y-m-d H:i:s');
            $date = formatDate($eventTime);

            // Format the update time if the event was updated
            $updated = null;
            if (!empty($e['was_updated']) && !empty($e['last_updated'])) {
                $updatedDate = formatDate($e['last_updated']);
                $updated = $updatedDate['time']; // Just show time like "21:34"
            }

            return [
                'id' => $e['id'] ?? uniqid(), 'name' => $e['name'] ?? '', 'summary' => $e['summary'] ?? '',
                'type' => $e['type'] ?? 'Okänd', 'url' => $e['url'] ?? '',
                'location' => $e['location']['name'] ?? 'Okänd', 'gps' => $e['location']['gps'] ?? null,
                'date' => $date, 'icon' => getEventIcon($e['type'] ?? ''), 'color' => getEventColor($e['type'] ?? ''),
                'updated' => $updated, 'wasUpdated' => !empty($e['was_updated']),
            ];
        }, $events);

        echo json_encode(['events' => $formatted, 'page' => $page, 'totalPages' => $totalPages,
                          'total' => $total, 'hasMore' => $page < $totalPages]);
        exit;
    }

    if ($_GET['ajax'] === 'stats') {
        sendCacheHeaders(120, 300);
        echo json_encode(calculateStats(fetchPoliceEvents()));
        exit;
    }

    if ($_GET['ajax'] === 'details') {
        sendCacheHeaders(3600, 7200);

        $eventUrl = $_GET['url'] ?? '';
        if (empty($eventUrl)) {
            echo json_encode(['error' => 'No URL provided']);
            exit;
        }
        $details = fetchEventDetails($eventUrl);
        if ($details) {
            echo json_encode(['success' => true, 'details' => $details]);
        } else {
            echo json_encode(['error' => 'Could not fetch details']);
        }
        exit;
    }

    if ($_GET['ajax'] === 'press') {
        sendCacheHeaders(120, 600);

        $page = max(1, min(500, intval($_GET['page'] ?? 1)));
        $regionFilter = !empty($_GET['region']) ? sanitizeInput($_GET['region'], 50) : null;
        // Validate region against allowed list
        if ($regionFilter && !array_key_exists($regionFilter, getPressRegions())) {
            $regionFilter = null;
        }
        $searchFilter = sanitizeSearch($_GET['search'] ?? '');

        $allPress = fetchPressReleases($regionFilter);

        if ($searchFilter) {
            $allPress = array_values(array_filter($allPress, function($item) use ($searchFilter) {
                return mb_stripos($item['title'], $searchFilter) !== false ||
                       mb_stripos($item['description'], $searchFilter) !== false ||
                       mb_stripos($item['region'], $searchFilter) !== false;
            }));
        }

        $total = count($allPress);
        $perPage = 20;
        $totalPages = ceil($total / $perPage);
        $items = array_slice($allPress, ($page - 1) * $perPage, $perPage);

        $formatted = array_map(function($item) {
            return [
                'title' => $item['title'],
                'description' => $item['description'],
                'link' => $item['link'],
                'region' => $item['region'],
                'regionSlug' => $item['regionSlug'],
                'date' => formatPressDate($item['timestamp'])
            ];
        }, $items);

        echo json_encode([
            'items' => $formatted,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'hasMore' => $page < $totalPages,
            'regions' => getPressRegions()
        ]);
        exit;
    }

    if ($_GET['ajax'] === 'pressdetails') {
        sendCacheHeaders(3600, 7200);

        $pressUrl = $_GET['url'] ?? '';
        if (empty($pressUrl)) {
            echo json_encode(['error' => 'No URL provided']);
            exit;
        }

        if (strpos($pressUrl, 'https://polisen.se/') !== 0) {
            echo json_encode(['error' => 'Invalid URL']);
            exit;
        }

        $cacheFile = sys_get_temp_dir() . '/police_press_detail_' . md5($pressUrl) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached !== null) {
                echo json_encode(['success' => true, 'details' => $cached]);
                exit;
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: " . USER_AGENT . "\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: sv-SE,sv;q=0.9,en;q=0.8"
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);

        $html = @file_get_contents($pressUrl, false, $context);
        if ($html === false) {
            echo json_encode(['error' => 'Could not fetch press release']);
            exit;
        }

        $details = ['content' => ''];

        if (preg_match('/<div[^>]*class="[^"]*(?:text-body|body-content|article-body|editorial-body|news-body|hpt-body)[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
            $content = $matches[1];
            $content = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $content);
            $content = strip_tags($content);
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
            $content = preg_replace('/\s+/', ' ', $content);
            $details['content'] = trim($content);
        }

        if (empty($details['content'])) {
            if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $pMatches)) {
                $paragraphs = [];
                foreach ($pMatches[1] as $p) {
                    $text = strip_tags($p);
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                    $text = trim(preg_replace('/\s+/', ' ', $text));
                    if (strlen($text) > 50) {
                        $paragraphs[] = $text;
                    }
                }
                if (!empty($paragraphs)) {
                    $details['content'] = implode("\n\n", array_slice($paragraphs, 0, 5));
                }
            }
        }

        if (!empty($details['content'])) {
            file_put_contents($cacheFile, json_encode($details));
            echo json_encode(['success' => true, 'details' => $details]);
        } else {
            echo json_encode(['error' => 'Could not extract content']);
        }
        exit;
    }

    if ($_GET['ajax'] === 'dbstats') {
        sendCacheHeaders(60, 300);
        echo json_encode(getDatabaseStats());
        exit;
    }

    if ($_GET['ajax'] === 'status') {
        // Hidden status endpoint - returns extended stats (requires admin key if set)
        if (!isAdminAuthorized()) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        header('Cache-Control: no-cache');
        echo json_encode(getExtendedStats(), JSON_PRETTY_PRINT);
        exit;
    }

    if ($_GET['ajax'] === 'backup') {
        // Trigger manual backup (requires admin key if set)
        if (!isAdminAuthorized()) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        header('Cache-Control: no-cache');
        $result = createBackup();
        echo json_encode($result);
        exit;
    }

    if ($_GET['ajax'] === 'refresh') {
        // Force refresh data from Police API (requires admin key)
        if (!isAdminAuthorized()) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        header('Cache-Control: no-cache');

        // Remove lock file if stuck
        $lockFile = DATA_DIR . '/fetch.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        // Force fetch
        $result = fetchAndStoreEvents();
        echo json_encode($result);
        exit;
    }
}

// ============================================================================
// BACKUP DOWNLOAD ENDPOINT
// ============================================================================

if (isset($_GET['download_backup'])) {
    // Requires admin key if set
    if (!isAdminAuthorized()) {
        http_response_code(403);
        echo 'Unauthorized';
        exit;
    }

    $filename = basename($_GET['download_backup']); // Sanitize filename
    $filepath = BACKUP_DIR . '/' . $filename;

    // Validate filename format and existence
    if (preg_match('/^events_backup_[\d\-_]+\.db$/', $filename) && file_exists($filepath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache');
        readfile($filepath);
        exit;
    } else {
        http_response_code(404);
        echo 'Backup not found';
        exit;
    }
}

// ============================================================================
// HIDDEN STATUS PAGE
// ============================================================================

if (isset($_GET['page']) && $_GET['page'] === 'status') {
    // Requires admin key if set
    if (!isAdminAuthorized()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Admin key required. Add ?key=YOUR_KEY to URL.</p></body></html>';
        exit;
    }
    $stats = getExtendedStats();
    $adminKey = ADMIN_KEY;

    // Helper function for formatting freshness
    function formatFreshness($seconds) {
        if ($seconds === null) return 'Okänd';
        if ($seconds < 60) return $seconds . ' sek';
        if ($seconds < 3600) return floor($seconds / 60) . ' min';
        if ($seconds < 86400) return floor($seconds / 3600) . ' tim';
        return floor($seconds / 86400) . ' dagar';
    }

    // Health status
    $healthColor = $stats['health']['score'] >= 80 ? '#10b981' : ($stats['health']['score'] >= 50 ? '#f59e0b' : '#ef4444');
    $healthStatus = $stats['health']['score'] >= 80 ? 'Utmärkt' : ($stats['health']['score'] >= 50 ? 'Varning' : 'Kritisk');
    ?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Status Dashboard - Sambandscentralen</title>
    <style>
        :root {
            --bg: #0a0f1a;
            --surface: #111827;
            --surface-hover: #1f2937;
            --border: rgba(255,255,255,0.08);
            --text: #f3f4f6;
            --text-secondary: #9ca3af;
            --muted: #6b7280;
            --accent: #fbbf24;
            --accent-dim: rgba(251,191,36,0.15);
            --success: #10b981;
            --success-dim: rgba(16,185,129,0.15);
            --warning: #f59e0b;
            --warning-dim: rgba(245,158,11,0.15);
            --danger: #ef4444;
            --danger-dim: rgba(239,68,68,0.15);
            --blue: #3b82f6;
            --blue-dim: rgba(59,130,246,0.15);
            --purple: #8b5cf6;
            --purple-dim: rgba(139,92,246,0.15);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .header-left h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-left p { color: var(--muted); font-size: 14px; margin-top: 4px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .back-link {
            color: var(--accent);
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--accent-dim);
            border-radius: 8px;
            transition: all 0.2s;
        }
        .back-link:hover { background: rgba(251,191,36,0.25); }

        /* Health Banner */
        .health-banner {
            background: linear-gradient(135deg, var(--surface) 0%, rgba(17,24,39,0.8) 100%);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 24px;
            align-items: center;
        }
        .health-score {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            position: relative;
        }
        .health-score::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 4px solid var(--border);
        }
        .health-score::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 4px solid transparent;
            border-top-color: currentColor;
            transform: rotate(-45deg);
        }
        .health-score .number { font-size: 32px; }
        .health-score .label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
        .health-details { display: flex; flex-wrap: wrap; gap: 24px; }
        .health-item { display: flex; flex-direction: column; gap: 4px; }
        .health-item .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .health-item .value { font-size: 18px; font-weight: 600; }
        .health-actions { display: flex; flex-direction: column; gap: 8px; }

        /* Status badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-success { background: var(--success-dim); color: var(--success); }
        .badge-warning { background: var(--warning-dim); color: var(--warning); }
        .badge-danger { background: var(--danger-dim); color: var(--danger); }
        .badge-info { background: var(--blue-dim); color: var(--blue); }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.2s;
        }
        .stat-card:hover { border-color: var(--accent); transform: translateY(-2px); }
        .stat-card .icon { font-size: 24px; margin-bottom: 8px; }
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: var(--accent);
            line-height: 1.2;
        }
        .stat-card .label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
        }
        .stat-card .sub { font-size: 12px; color: var(--text-secondary); margin-top: 4px; }

        /* Sections */
        .section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .section-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-header h2 {
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-body { padding: 20px 24px; }
        .section-body.no-padding { padding: 0; }

        /* Two column layout */
        .two-columns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); }
        th {
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(0,0,0,0.2);
        }
        td { font-size: 14px; }
        tr:hover td { background: var(--surface-hover); }
        tr:last-child td { border-bottom: none; }
        .mono { font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 12px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* Chart */
        .chart-container {
            height: 120px;
            display: flex;
            align-items: flex-end;
            gap: 4px;
            padding: 16px 0;
        }
        .chart-bar {
            flex: 1;
            background: linear-gradient(to top, var(--accent), var(--accent-dim));
            border-radius: 4px 4px 0 0;
            min-height: 4px;
            transition: all 0.3s;
            position: relative;
        }
        .chart-bar:hover { opacity: 0.8; }
        .chart-bar::after {
            content: attr(data-value);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            font-size: 10px;
            color: var(--muted);
            opacity: 0;
            transition: opacity 0.2s;
            white-space: nowrap;
        }
        .chart-bar:hover::after { opacity: 1; }
        .chart-labels {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: var(--muted);
            padding: 8px 0;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--accent); color: #000; }
        .btn-primary:hover { background: #fcd34d; }
        .btn-secondary { background: var(--surface-hover); color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover { border-color: var(--accent); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-icon { padding: 8px; }

        /* Search */
        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 12px;
        }
        .search-box input {
            flex: 1;
            background: none;
            border: none;
            color: var(--text);
            font-size: 14px;
            outline: none;
        }
        .search-box input::placeholder { color: var(--muted); }

        /* Footer */
        .footer {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            padding: 32px 0 16px;
        }

        /* Backup status */
        #backup-status {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 8px;
            display: none;
        }
        #backup-status.success { display: block; background: var(--success-dim); color: var(--success); }
        #backup-status.error { display: block; background: var(--danger-dim); color: var(--danger); }

        /* Responsive */
        @media (max-width: 768px) {
            .container { padding: 16px; }
            .health-banner { grid-template-columns: 1fr; text-align: center; }
            .health-details { justify-content: center; }
            .two-columns { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .stat-card .value { font-size: 24px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>📊 Status Dashboard</h1>
                <p>Sambandscentralen • <?= date('Y-m-d H:i:s') ?></p>
            </div>
            <div class="header-right">
                <button class="btn btn-primary" onclick="forceRefresh()">🔄 Uppdatera data</button>
                <a href="./" class="back-link">← Tillbaka</a>
            </div>
        </div>
        <div id="refresh-status" style="display:none; margin-bottom: 16px; padding: 12px 16px; border-radius: 8px; text-align: center;"></div>

        <!-- Health Banner -->
        <div class="health-banner">
            <div class="health-score" style="color: <?= $healthColor ?>">
                <span class="number"><?= $stats['health']['score'] ?></span>
                <span class="label">Hälsa</span>
            </div>
            <div class="health-details">
                <div class="health-item">
                    <span class="label">Status</span>
                    <span class="badge <?= $stats['health']['score'] >= 80 ? 'badge-success' : ($stats['health']['score'] >= 50 ? 'badge-warning' : 'badge-danger') ?>">
                        <?= $stats['health']['score'] >= 80 ? '✓' : ($stats['health']['score'] >= 50 ? '⚠' : '✗') ?> <?= $healthStatus ?>
                    </span>
                </div>
                <div class="health-item">
                    <span class="label">Data Freshness</span>
                    <span class="value"><?= formatFreshness($stats['health']['data_freshness_seconds']) ?></span>
                </div>
                <div class="health-item">
                    <span class="label">Fetch Rate (24h)</span>
                    <span class="value"><?= $stats['fetch_success_rate'] ?>%</span>
                </div>
                <div class="health-item">
                    <span class="label">Fel (7d)</span>
                    <span class="value <?= $stats['health']['recent_errors'] > 0 ? 'danger' : '' ?>"><?= $stats['health']['recent_errors'] ?></span>
                </div>
                <div class="health-item">
                    <span class="label">Databas</span>
                    <span class="badge <?= $stats['database']['integrity_ok'] ? 'badge-success' : 'badge-danger' ?>">
                        <?= $stats['database']['integrity_ok'] ? '✓ OK' : '✗ Korrupt' ?>
                    </span>
                </div>
                <div class="health-item">
                    <span class="label">Snitt/dag</span>
                    <span class="value"><?= $stats['health']['avg_events_per_day'] ?></span>
                </div>
            </div>
            <div class="health-actions">
                <button class="btn btn-primary" onclick="location.reload()">🔄 Uppdatera</button>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">📦</div>
                <div class="value"><?= number_format($stats['total_events'], 0, ' ', ' ') ?></div>
                <div class="label">Totalt</div>
                <div class="sub">sedan <?= $stats['date_range']['oldest'] ? date('Y-m-d', strtotime($stats['date_range']['oldest'])) : 'N/A' ?></div>
            </div>
            <div class="stat-card">
                <div class="icon">⏰</div>
                <div class="value"><?= $stats['time_stats']['last_24h'] ?></div>
                <div class="label">Senaste 24h</div>
            </div>
            <div class="stat-card">
                <div class="icon">📅</div>
                <div class="value"><?= number_format($stats['time_stats']['last_7_days'], 0, ' ', ' ') ?></div>
                <div class="label">Senaste 7 dagar</div>
            </div>
            <div class="stat-card">
                <div class="icon">📆</div>
                <div class="value"><?= number_format($stats['time_stats']['last_30_days'], 0, ' ', ' ') ?></div>
                <div class="label">Senaste 30 dagar</div>
            </div>
            <div class="stat-card">
                <div class="icon">📍</div>
                <div class="value"><?= $stats['unique_locations'] ?></div>
                <div class="label">Platser</div>
            </div>
            <div class="stat-card">
                <div class="icon">🏷️</div>
                <div class="value"><?= $stats['unique_types'] ?></div>
                <div class="label">Typer</div>
            </div>
            <div class="stat-card">
                <div class="icon">💾</div>
                <div class="value"><?= $stats['database']['size_mb'] ?></div>
                <div class="label">MB Databas</div>
                <div class="sub">WAL: <?= round($stats['database']['wal_size_bytes'] / 1024, 1) ?> KB</div>
            </div>
            <div class="stat-card">
                <div class="icon">🔄</div>
                <div class="value"><?= $stats['fetch_stats']['last_fetch'] ? date('H:i', strtotime($stats['fetch_stats']['last_fetch'])) : '--:--' ?></div>
                <div class="label">Senaste hämtning</div>
                <div class="sub"><?= $stats['fetch_stats']['last_fetch'] ? date('Y-m-d', strtotime($stats['fetch_stats']['last_fetch'])) : '' ?></div>
            </div>
        </div>

        <!-- Daily Chart -->
        <div class="section">
            <div class="section-header">
                <h2>📈 Händelser per dag (14 dagar)</h2>
            </div>
            <div class="section-body">
                <?php
                $maxDaily = max(array_column($stats['daily'], 'count') ?: [1]);
                ?>
                <div class="chart-container">
                    <?php foreach ($stats['daily'] as $d): ?>
                        <div class="chart-bar" style="height: <?= ($d['count'] / $maxDaily) * 100 ?>%" data-value="<?= $d['count'] ?> (<?= date('d/m', strtotime($d['day'])) ?>)"></div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-labels">
                    <span><?= !empty($stats['daily']) ? date('d M', strtotime($stats['daily'][0]['day'])) : '' ?></span>
                    <span>Idag</span>
                </div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="two-columns">
            <!-- Top Locations -->
            <div class="section">
                <div class="section-header">
                    <h2>📍 Topp platser</h2>
                </div>
                <div class="section-body no-padding">
                    <table>
                        <thead><tr><th>Plats</th><th class="text-right">Antal</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($stats['top_locations'], 0, 10) as $loc): ?>
                            <tr>
                                <td><?= htmlspecialchars($loc['location_name']) ?></td>
                                <td class="text-right mono"><?= number_format($loc['count'], 0, ' ', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Types -->
            <div class="section">
                <div class="section-header">
                    <h2>🏷️ Topp händelsetyper</h2>
                </div>
                <div class="section-body no-padding">
                    <table>
                        <thead><tr><th>Typ</th><th class="text-right">Antal</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($stats['top_types'], 0, 10) as $type): ?>
                            <tr>
                                <td><?= htmlspecialchars($type['type']) ?></td>
                                <td class="text-right mono"><?= number_format($type['count'], 0, ' ', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Monthly Stats -->
        <div class="two-columns">
            <div class="section">
                <div class="section-header">
                    <h2>📅 Månadsstatistik</h2>
                </div>
                <div class="section-body no-padding">
                    <table>
                        <thead><tr><th>Månad</th><th class="text-right">Händelser</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['monthly'] as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['month']) ?></td>
                                <td class="text-right mono"><?= number_format($m['count'], 0, ' ', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>📆 Veckostatistik</h2>
                </div>
                <div class="section-body no-padding">
                    <table>
                        <thead><tr><th>Vecka</th><th class="text-right">Händelser</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['weekly'] as $w): ?>
                            <tr>
                                <td><?= htmlspecialchars($w['week']) ?></td>
                                <td class="text-right mono"><?= number_format($w['count'], 0, ' ', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Fetches -->
        <div class="section">
            <div class="section-header">
                <h2>🔄 Senaste hämtningar</h2>
                <span class="badge badge-info"><?= $stats['fetch_stats']['total_fetches'] ?> totalt</span>
            </div>
            <div class="section-body no-padding">
                <table>
                    <thead>
                        <tr>
                            <th>Tidpunkt</th>
                            <th class="text-center">Hämtade</th>
                            <th class="text-center">Nya</th>
                            <th class="text-center">Status</th>
                            <th>Felmeddelande</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stats['recent_fetches'] as $f): ?>
                        <tr>
                            <td class="mono"><?= date('Y-m-d H:i:s', strtotime($f['fetched_at'])) ?></td>
                            <td class="text-center"><?= $f['events_fetched'] ?></td>
                            <td class="text-center"><?= $f['events_new'] ?></td>
                            <td class="text-center">
                                <span class="badge <?= $f['success'] ? 'badge-success' : 'badge-danger' ?>">
                                    <?= $f['success'] ? '✓ OK' : '✗ Fel' ?>
                                </span>
                            </td>
                            <td class="mono" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($f['error_message'] ?? '') ?>">
                                <?= htmlspecialchars($f['error_message'] ?? '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Backups -->
        <div class="section">
            <div class="section-header">
                <h2>💾 Säkerhetskopior</h2>
                <button class="btn btn-primary btn-sm" onclick="createBackup()">+ Skapa ny</button>
            </div>
            <div class="section-body">
                <div id="backup-status"></div>
                <?php if (empty($stats['backups'])): ?>
                    <p style="color: var(--muted); text-align: center; padding: 24px;">Inga säkerhetskopior ännu. Klicka "Skapa ny" för att skapa en.</p>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Filnamn</th><th class="text-right">Storlek</th><th>Skapad</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['backups'] as $backup): ?>
                            <tr>
                                <td class="mono"><?= htmlspecialchars($backup['filename']) ?></td>
                                <td class="text-right"><?= round($backup['size'] / (1024 * 1024), 2) ?> MB</td>
                                <td class="mono"><?= date('Y-m-d H:i', strtotime($backup['created'])) ?></td>
                                <td class="text-right">
                                    <a href="?download_backup=<?= urlencode($backup['filename']) ?>&key=<?= urlencode($adminKey) ?>" class="btn btn-secondary btn-sm">⬇️ Ladda ner</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- System Info -->
        <div class="section">
            <div class="section-header">
                <h2>🖥️ Systeminformation</h2>
            </div>
            <div class="section-body no-padding">
                <table>
                    <tr><td style="width: 200px;">PHP-version</td><td class="mono"><?= $stats['php_version'] ?></td></tr>
                    <tr><td>Servertid</td><td class="mono"><?= $stats['server_time'] ?></td></tr>
                    <tr>
                        <td>Databassökväg</td>
                        <td class="mono"><?php
                            $dbPath = $stats['database']['path'];
                            if (($pos = strpos($dbPath, 'public_html/')) !== false) {
                                $dbPath = substr($dbPath, $pos + strlen('public_html/'));
                            }
                            echo htmlspecialchars($dbPath);
                        ?></td>
                    </tr>
                    <tr><td>Databasintegritet</td><td><span class="badge <?= $stats['database']['integrity_ok'] ? 'badge-success' : 'badge-danger' ?>"><?= $stats['database']['integrity_ok'] ? '✓ OK' : '✗ Problem' ?></span></td></tr>
                    <tr><td>Totalt antal hämtningar</td><td class="mono"><?= number_format($stats['fetch_stats']['total_fetches'], 0, ' ', ' ') ?></td></tr>
                    <tr><td>Lyckade hämtningar</td><td class="mono"><?= number_format($stats['fetch_stats']['successful'], 0, ' ', ' ') ?></td></tr>
                    <tr><td>Misslyckade hämtningar</td><td class="mono"><?= number_format($stats['fetch_stats']['failed'] ?? 0, 0, ' ', ' ') ?></td></tr>
                    <tr><td>Nya händelser totalt</td><td class="mono"><?= number_format($stats['fetch_stats']['total_new_events'], 0, ' ', ' ') ?></td></tr>
                </table>
            </div>
        </div>

        <div class="footer">
            Sambandscentralen Status Dashboard • v<?= ASSET_VERSION ?>
        </div>
    </div>

    <script>
    const adminKey = '<?= htmlspecialchars($adminKey) ?>';

    async function createBackup() {
        const status = document.getElementById('backup-status');
        status.className = '';
        status.style.display = 'block';
        status.textContent = '⏳ Skapar säkerhetskopia...';

        try {
            const res = await fetch('?ajax=backup&key=' + encodeURIComponent(adminKey));
            const data = await res.json();

            if (data.success) {
                status.className = 'success';
                status.innerHTML = '✓ Säkerhetskopia skapad: <strong>' + data.filename + '</strong> (' + (data.size / 1024 / 1024).toFixed(2) + ' MB)';
                setTimeout(() => location.reload(), 2000);
            } else {
                status.className = 'error';
                status.textContent = '✗ Fel: ' + (data.error || 'Okänt fel');
            }
        } catch (err) {
            status.className = 'error';
            status.textContent = '✗ Nätverksfel: ' + err.message;
        }
    }

    async function forceRefresh() {
        const status = document.getElementById('refresh-status');
        status.style.display = 'block';
        status.style.background = 'var(--surface)';
        status.style.border = '1px solid var(--border)';
        status.style.color = 'var(--text)';
        status.textContent = '⏳ Hämtar data från polisen.se...';

        try {
            const res = await fetch('?ajax=refresh&key=' + encodeURIComponent(adminKey));
            const data = await res.json();

            if (data.success) {
                status.style.background = 'rgba(46, 204, 113, 0.1)';
                status.style.border = '1px solid var(--success)';
                status.style.color = 'var(--success)';
                status.innerHTML = '✓ Data uppdaterad! Hämtade <strong>' + data.events_fetched + '</strong> händelser (' + data.events_new + ' nya)';
                setTimeout(() => location.reload(), 2000);
            } else {
                status.style.background = 'rgba(231, 76, 60, 0.1)';
                status.style.border = '1px solid var(--danger)';
                status.style.color = 'var(--danger)';
                status.textContent = '✗ Fel: ' + (data.error || 'Okänt fel');
            }
        } catch (err) {
            status.style.background = 'rgba(231, 76, 60, 0.1)';
            status.style.border = '1px solid var(--danger)';
            status.style.color = 'var(--danger)';
            status.textContent = '✗ Nätverksfel: ' + err.message;
        }
    }

    // Auto-refresh every 5 minutes
    setTimeout(() => location.reload(), 300000);
    </script>
</body>
</html>
    <?php
    exit;
}

// ============================================================================
// MAIN PAGE
// ============================================================================

$locationFilter = sanitizeLocation($_GET['location'] ?? '');
$customLocation = sanitizeLocation($_GET['customLocation'] ?? '');
if ($customLocation) {
    $locationFilter = $customLocation;
} elseif ($locationFilter === '__custom__') {
    $locationFilter = '';
}
$typeFilter = sanitizeType($_GET['type'] ?? '');
$searchFilter = sanitizeSearch($_GET['search'] ?? '');
$currentView = sanitizeInput($_GET['view'] ?? 'list', 20);
// Validate view parameter (security)
if (!in_array($currentView, ALLOWED_VIEWS)) {
    $currentView = 'list';
}

$allEvents = fetchPoliceEvents();
$filters = [];
if ($locationFilter) $filters['location'] = $locationFilter;
if ($typeFilter) $filters['type'] = $typeFilter;

$events = !empty($filters) ? fetchPoliceEvents($filters) : $allEvents;

// Client-side type filtering as fallback
if ($typeFilter && is_array($events) && !isset($events['error'])) {
    $events = array_values(array_filter($events, function($e) use ($typeFilter) {
        return isset($e['type']) && $e['type'] === $typeFilter;
    }));
}

if ($searchFilter && is_array($events) && !isset($events['error'])) {
    $events = array_values(array_filter($events, function($e) use ($searchFilter) {
        return mb_stripos($e['name'] ?? '', $searchFilter) !== false ||
               mb_stripos($e['summary'] ?? '', $searchFilter) !== false ||
               mb_stripos($e['location']['name'] ?? '', $searchFilter) !== false;
    }));
}

// Get locations from database
$dbLocations = getLocationsFromDb();
$locations = array_column($dbLocations, 'name');
sort($locations);

// Extract types from events
$types = [];
if (is_array($allEvents) && !isset($allEvents['error'])) {
    foreach ($allEvents as $e) {
        if (isset($e['type']) && !in_array($e['type'], $types)) $types[] = $e['type'];
    }
    sort($types);
}

$eventCount = is_array($events) && !isset($events['error']) ? count($events) : 0;
$stats = calculateStats($allEvents);
$initialEvents = is_array($events) ? array_slice($events, 0, EVENTS_PER_PAGE) : [];
$hasMorePages = $eventCount > EVENTS_PER_PAGE;

// Get database stats for footer
$dbStats = getDatabaseStats();
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="description" content="Sambandscentralen - Aktuella händelsenotiser från Svenska Polisen i realtid">
    <meta name="theme-color" content="#0a1628" id="theme-color-meta">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Sambandscentralen">
    <?php
    $ogHost = $_SERVER['HTTP_HOST'] ?? 'sambandscentralen.se';
    $ogBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/');
    $ogCanonicalUrl = 'https://' . $ogHost . $ogBasePath . '/';
    ?>
    <meta property="og:title" content="Sambandscentralen">
    <meta property="og:description" content="Aktuella händelsenotiser från Svenska Polisen i realtid">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($ogCanonicalUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogCanonicalUrl) ?>og-image.php">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="Sambandscentralen">
    <meta property="og:locale" content="sv_SE">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Sambandscentralen">
    <meta name="twitter:description" content="Aktuella händelsenotiser från Svenska Polisen i realtid">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogCanonicalUrl) ?>og-image.php">

    <title>Sambandscentralen</title>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect fill='%23fcd34d' rx='20' width='100' height='100'/><text x='50' y='70' font-size='60' text-anchor='middle'>👮</text></svg>">
    <link rel="apple-touch-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect fill='%23fcd34d' rx='20' width='100' height='100'/><text x='50' y='70' font-size='60' text-anchor='middle'>👮</text></svg>">
    <link rel="manifest" href="manifest.json">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <link rel="stylesheet" href="css/styles.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="view-<?= htmlspecialchars($currentView) ?>">
    <div class="container">
        <header>
            <div class="header-content">
                <a href="./" class="logo" id="logoLink">
                    <div class="logo-icon">👮</div>
                    <div class="logo-text">
                        <h1>Sambandscentralen</h1>
                        <p>Polisens händelsenotiser i realtid</p>
                    </div>
                </a>

                <div class="header-controls">
                    <div class="live-indicator"><span class="live-dot"></span><span>Live</span></div>

                    <div class="view-toggle">
                        <button type="button" data-view="list" class="<?= $currentView === 'list' ? 'active' : '' ?>">📋 <span class="label">Händelser</span></button>
                        <button type="button" data-view="map" class="<?= $currentView === 'map' ? 'active' : '' ?>">🗺️ <span class="label">Karta</span></button>
                        <button type="button" data-view="stats" class="<?= $currentView === 'stats' ? 'active' : '' ?>">📊 <span class="label">Statistik</span></button>
                        <button type="button" data-view="press" class="<?= $currentView === 'press' ? 'active' : '' ?>">📰 <span class="label">Press</span></button>
                    </div>
                </div>
            </div>
        </header>

        <section class="filters-section">
            <div class="search-bar">
                <form class="search-form" method="GET" id="filterForm">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($currentView) ?>" id="viewInput">
                    <div class="search-input-wrapper">
                        <input type="search" name="search" class="search-input" placeholder="Sök händelser..." value="<?= htmlspecialchars($searchFilter) ?>" id="searchInput">
                    </div>
                    <select name="location" class="filter-select" id="locationSelect" <?= ($locationFilter && !in_array($locationFilter, $locations)) ? 'style="display:none"' : '' ?>>
                        <option value="">Alla platser</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= htmlspecialchars($loc) ?>" <?= $locationFilter === $loc ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">Annan plats...</option>
                    </select>
                    <div class="custom-location-wrapper" id="customLocationWrapper" <?= ($locationFilter && !in_array($locationFilter, $locations)) ? '' : 'style="display:none"' ?>>
                        <input type="text" name="customLocation" class="filter-input" id="customLocationInput" placeholder="Skriv platsnamn..." value="<?= ($locationFilter && !in_array($locationFilter, $locations)) ? htmlspecialchars($locationFilter) : '' ?>">
                        <button type="button" class="custom-location-cancel" id="customLocationCancel" title="Tillbaka till lista">×</button>
                    </div>
                    <select name="type" class="filter-select" id="typeSelect">
                        <option value="">Alla typer</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn">Sök</button>
                    <?php if ($locationFilter || $typeFilter || $searchFilter): ?>
                        <a href="?view=<?= $currentView ?>" class="btn btn-secondary">Rensa</a>
                    <?php endif; ?>
                </form>
            </div>
            <?php if ($locationFilter || $typeFilter || $searchFilter): ?>
                <div class="active-filters">
                    <?php if ($searchFilter): ?><span class="filter-tag">"<?= htmlspecialchars($searchFilter) ?>" <a href="?view=<?= $currentView ?>&<?= http_build_query(array_filter(['location' => $locationFilter, 'type' => $typeFilter])) ?>">×</a></span><?php endif; ?>
                    <?php if ($locationFilter): ?><span class="filter-tag">📍 <?= htmlspecialchars($locationFilter) ?> <a href="?view=<?= $currentView ?>&<?= http_build_query(array_filter(['search' => $searchFilter, 'type' => $typeFilter])) ?>">×</a></span><?php endif; ?>
                    <?php if ($typeFilter): ?><span class="filter-tag">🏷️ <?= htmlspecialchars($typeFilter) ?> <a href="?view=<?= $currentView ?>&<?= http_build_query(array_filter(['search' => $searchFilter, 'location' => $locationFilter])) ?>">×</a></span><?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="main-content">
            <main class="content-area" id="main-content">
                <div class="events-grid" id="eventsGrid" style="<?= $currentView !== 'list' ? 'display:none' : '' ?>">
                    <?php if (isset($events['error'])): ?>
                        <div class="empty-state"><div class="empty-state-icon">⚠️</div><h2>Kunde inte hämta data</h2><p><?= htmlspecialchars($events['error']) ?></p></div>
                    <?php elseif (empty($initialEvents)): ?>
                        <div class="empty-state"><div class="empty-state-icon">📭</div><h2>Inga händelser</h2><p><?php
                            if ($locationFilter && !in_array($locationFilter, $locations)) {
                                echo 'Inga händelser hittades för "' . htmlspecialchars($locationFilter) . '". Kontrollera stavningen eller prova ett annat platsnamn.';
                            } elseif ($locationFilter || $typeFilter || $searchFilter) {
                                echo 'Inga händelser matchar dina filter. Prova att ändra eller ta bort något filter.';
                            } else {
                                echo 'Inga händelser finns för tillfället.';
                            }
                        ?></p></div>
                    <?php else: ?>
                        <?php foreach ($initialEvents as $i => $event):
                            // Use event_time for display (when event occurred), falling back to datetime
                            $eventTime = $event['event_time'] ?? $event['datetime'] ?? date('Y-m-d H:i:s');
                            $date = formatDate($eventTime);
                            $type = $event['type'] ?? 'Okänd';
                            $color = getEventColor($type);
                            $icon = getEventIcon($type);
                            $location = $event['location']['name'] ?? 'Okänd';
                            $gps = $event['location']['gps'] ?? null;
                            $wasUpdated = !empty($event['was_updated']);
                            $updatedTime = $wasUpdated ? formatDate($event['last_updated'])['time'] : null;
                        ?>
                            <article class="event-card" style="animation-delay: <?= min($i * 0.02, 0.2) ?>s">
                                <div class="event-card-inner">
                                    <div class="event-date">
                                        <div class="day"><?= $date['day'] ?></div>
                                        <div class="month"><?= $date['month'] ?></div>
                                        <div class="time"><?= $date['time'] ?></div>
                                        <div class="relative"><?= $date['relative'] ?></div>
                                        <?php if ($wasUpdated): ?>
                                            <div class="updated-indicator" title="Uppdaterad <?= $updatedTime ?>">uppdaterad <?= $updatedTime ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="event-content">
                                        <div class="event-header">
                                            <div class="event-title-group">
                                                <a href="?type=<?= urlencode($type) ?>&view=<?= $currentView ?>" class="event-type" style="background: <?= $color ?>20; color: <?= $color ?>"><?= $icon ?> <?= htmlspecialchars($type) ?></a>
                                                <a href="?location=<?= urlencode($location) ?>&view=<?= $currentView ?>" class="event-location-link"><?= htmlspecialchars($location) ?></a>
                                            </div>
                                        </div>
                                        <p class="event-summary"><?= htmlspecialchars($event['summary'] ?? '') ?></p>
                                        <div class="event-meta">
                                            <?php if (!empty($event['url'])): ?>
                                                <button type="button" class="show-details-btn" data-url="<?= htmlspecialchars($event['url']) ?>">📖 Visa detaljer</button>
                                            <?php endif; ?>
                                            <?php if ($gps):
                                                $coords = explode(',', $gps);
                                                if (count($coords) === 2):
                                                    $lat = trim($coords[0]);
                                                    $lng = trim($coords[1]);
                                            ?>
                                                <button type="button" class="show-map-btn" data-lat="<?= $lat ?>" data-lng="<?= $lng ?>" data-location="<?= htmlspecialchars($event['location']['name'] ?? '') ?>">🗺️ Visa på karta</button>
                                            <?php endif; endif; ?>
                                            <?php if (!empty($event['url'])): ?>
                                                <a href="https://polisen.se<?= htmlspecialchars($event['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer" class="read-more-link"><span>🔗</span> polisen.se</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="event-details"></div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="loading-more" id="loadingMore" style="display: none;"><div class="spinner"></div></div>
                <div class="map-container <?= $currentView === 'map' ? 'active' : '' ?>" id="mapContainer"><div id="map"></div></div>
            </main>

            <aside class="stats-sidebar <?= $currentView === 'stats' ? 'active' : '' ?>" id="statsSidebar">
                <?php if ($stats): ?>
                <div class="stats-card">
                    <h3 style="text-align: center;">📊 Översikt</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; text-align: center;">
                        <div><div class="stat-number"><?= $stats['last24h'] ?></div><div class="stat-label">Senaste 24h</div></div>
                        <div><div class="stat-number"><?= $stats['last7days'] ?></div><div class="stat-label">Senaste 7 dagar</div></div>
                        <div><div class="stat-number"><?= number_format($stats['last6months'], 0, ',', ' ') ?></div><div class="stat-label">Senaste 6 mån</div></div>
                        <div><div class="stat-number"><?= number_format($stats['last1year'], 0, ',', ' ') ?></div><div class="stat-label">Senaste 1 år</div></div>
                    </div>
                </div>
                <div class="stats-card">
                    <h3>🏷️ Vanligaste typer</h3>
                    <?php $max = max($stats['byType'] ?: [1]); foreach ($stats['byType'] as $t => $c): ?>
                        <div class="stat-row"><span class="stat-row-label"><?= getEventIcon($t) ?> <?= htmlspecialchars($t) ?></span><span class="stat-row-value"><?= $c ?></span></div>
                        <div class="stat-bar"><div class="stat-bar-fill" style="width: <?= ($c / $max) * 100 ?>%"></div></div>
                    <?php endforeach; ?>
                </div>
                <div class="stats-card">
                    <h3>📍 Per plats</h3>
                    <?php $max = max($stats['byLocation'] ?: [1]); foreach (array_slice($stats['byLocation'], 0, 8, true) as $l => $c): ?>
                        <div class="stat-row"><span class="stat-row-label"><?= htmlspecialchars($l) ?></span><span class="stat-row-value"><?= $c ?></span></div>
                        <div class="stat-bar"><div class="stat-bar-fill" style="width: <?= ($c / $max) * 100 ?>%"></div></div>
                    <?php endforeach; ?>
                </div>
                <div class="stats-card">
                    <h3>🕐 Per timme</h3>
                    <div class="hour-chart">
                        <?php $max = max($stats['byHour'] ?: [1]); foreach ($stats['byHour'] as $h => $c): ?>
                            <div class="hour-bar" style="height: <?= max(($c / $max) * 100, 3) ?>%" title="<?= sprintf('%02d', $h) ?>:00 - <?= $c ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 9px; color: var(--text-muted);"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
                </div>
                <?php endif; ?>
            </aside>

            <section class="press-section <?= $currentView === 'press' ? 'active' : '' ?>" id="pressSection">
                <div class="press-header">
                    <h2>📰 Pressmeddelanden</h2>
                    <p>Samlade från alla polisregioner i Sverige</p>
                </div>
                <div class="press-filters">
                    <div class="press-search-wrapper">
                        <input type="search" class="press-search" id="pressSearch" placeholder="Sök pressmeddelanden...">
                    </div>
                    <select class="press-region-select" id="pressRegionSelect">
                        <option value="">Alla regioner</option>
                        <?php foreach (getPressRegions() as $slug => $name): ?>
                            <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="press-grid" id="pressGrid">
                    <div class="press-loading"><div class="spinner"></div><p>Laddar pressmeddelanden...</p></div>
                </div>
                <div class="press-load-more" id="pressLoadMore" style="display: none;">
                    <button class="btn" id="pressLoadMoreBtn">Ladda fler</button>
                </div>
            </section>
        </div>

        <footer>
            <p>
                <span class="api-status online">🟢 Lokal databas</span>
                <?php if ($dbStats['total_events'] > 0): ?>
                    • <strong><?= number_format($dbStats['total_events'], 0, ',', ' ') ?></strong> händelser i arkivet
                    <?php if ($dbStats['date_range']['oldest']): ?>
                        (sedan <?= date('Y-m-d', strtotime($dbStats['date_range']['oldest'])) ?>)
                    <?php endif; ?>
                <?php endif; ?>
                • Uppdateras var 10:e minut
                • v<?= ASSET_VERSION ?>
            </p>
        </footer>
    </div>

    <button class="scroll-top" id="scrollTop" aria-label="Till toppen">↑</button>

    <div class="install-prompt" id="installPrompt">
        <h4>📱 Installera Sambandscentralen</h4>
        <p>Lägg till på hemskärmen för snabb åtkomst.</p>
        <div class="install-prompt-buttons">
            <button class="install-btn" id="installBtn">Installera</button>
            <button class="dismiss-btn" id="dismissInstall">Inte nu</button>
        </div>
    </div>

    <div class="map-modal-overlay" id="mapModalOverlay">
        <div class="map-modal">
            <div class="map-modal-header">
                <h3 id="mapModalTitle">📍 Plats</h3>
                <button class="map-modal-close" id="mapModalClose" aria-label="Stäng">&times;</button>
            </div>
            <div class="map-modal-body">
                <div id="modalMap"></div>
            </div>
            <div class="map-modal-footer">
                <span class="coords" id="mapModalCoords"></span>
                <div style="display: flex; gap: 16px;">
                    <a id="mapModalGoogleLink" href="#" target="_blank" rel="noopener noreferrer">🗺️ Google Maps</a>
                    <a id="mapModalAppleLink" href="#" target="_blank" rel="noopener noreferrer">🍎 Apple Maps</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
    // Configuration (generated by PHP)
    window.CONFIG = {
        perPage: <?= EVENTS_PER_PAGE ?>,
        filters: {
            location: <?= json_encode($locationFilter) ?>,
            type: <?= json_encode($typeFilter) ?>,
            search: <?= json_encode($searchFilter) ?>
        },
        total: <?= $eventCount ?>,
        hasMore: <?= $hasMorePages ? 'true' : 'false' ?>,
        currentView: <?= json_encode($currentView) ?>,
        basePath: <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/')) ?>
    };
    window.eventsData = <?= json_encode(is_array($events) && !isset($events['error']) ? array_map(fn($e) => ['name' => $e['name'] ?? '', 'summary' => $e['summary'] ?? '', 'type' => $e['type'] ?? '', 'url' => $e['url'] ?? '', 'location' => $e['location']['name'] ?? '', 'gps' => $e['location']['gps'] ?? null, 'datetime' => $e['event_time'] ?? $e['datetime'] ?? '', 'icon' => getEventIcon($e['type'] ?? ''), 'color' => getEventColor($e['type'] ?? '')], $events) : []) ?>;
    </script>
    <script src="js/app.js?v=<?= ASSET_VERSION ?>" defer></script>
</body>
</html>
