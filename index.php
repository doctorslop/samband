<?php
/**
 * Sambandscentralen
 * Polisens händelsenotiser - Self-contained with local SQLite storage
 *
 * @version 5.0 - Integrated API (no external VPS required)
 */

date_default_timezone_set('Europe/Stockholm');

// Configuration
define('CACHE_TIME', 600);           // 10 minutes for events
define('STALE_CACHE_TIME', 1200);    // 20 minutes stale-while-revalidate window
define('EVENTS_PER_PAGE', 40);
define('ASSET_VERSION', '5.1.0');    // Bump this to bust browser cache
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
    ");
}

/**
 * Insert event if it doesn't exist
 * Returns true if new event was inserted
 */
function insertEvent(PDO $pdo, array $event): bool {
    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO events
        (id, datetime, name, summary, url, type, location_name, location_gps, raw_data, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $event['id'],
        $event['datetime'],
        $event['name'],
        $event['summary'] ?? '',
        $event['url'] ?? '',
        $event['type'],
        $event['location']['name'],
        $event['location']['gps'] ?? '',
        json_encode($event, JSON_UNESCAPED_UNICODE),
        date('c')
    ]);

    return $stmt->rowCount() > 0;
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
 */
function getEventsFromDb(array $filters = [], int $limit = 500, int $offset = 0): array {
    $pdo = getDatabase();

    $query = "SELECT raw_data FROM events WHERE 1=1";
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
        $query .= " AND datetime LIKE ?";
        $params[] = $filters['date'] . '%';
    }

    if (!empty($filters['from'])) {
        $query .= " AND datetime >= ?";
        $params[] = $filters['from'];
    }

    if (!empty($filters['to'])) {
        $query .= " AND datetime <= ?";
        $params[] = $filters['to'] . 'T23:59:59';
    }

    $query .= " ORDER BY datetime DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $events = [];
    while ($row = $stmt->fetch()) {
        $events[] = json_decode($row['raw_data'], true);
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
            SUM(events_new) as total_new_events,
            MAX(fetched_at) as last_fetch
        FROM fetch_log
    ")->fetch();

    // Recent fetches
    $recentFetches = $pdo->query("
        SELECT fetched_at, events_fetched, events_new, success, error_message
        FROM fetch_log
        ORDER BY id DESC
        LIMIT 20
    ")->fetchAll();

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
            'last_30_days' => $last30days
        ],
        'monthly' => $monthlyStats,
        'weekly' => $weeklyStats,
        'top_locations' => $topLocations,
        'top_types' => $topTypes,
        'fetch_stats' => $fetchStats,
        'recent_fetches' => $recentFetches,
        'database' => [
            'size_bytes' => $dbSize,
            'size_mb' => round($dbSize / (1024 * 1024), 2),
            'wal_size_bytes' => $walSize,
            'path' => DB_PATH
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
 * Fetch events from Police API
 */
function fetchFromPoliceApi(): ?array {
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

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return null;
    }

    return $data;
}

/**
 * Fetch events from Police API and store in database
 */
function fetchAndStoreEvents(): array {
    $pdo = getDatabase();
    $events = fetchFromPoliceApi();

    if ($events === null) {
        logFetch($pdo, 0, 0, false, 'Failed to fetch from Police API');
        return ['success' => false, 'error' => 'Failed to fetch from Police API'];
    }

    $newCount = 0;
    $pdo->beginTransaction();

    try {
        foreach ($events as $event) {
            // Validate required fields
            if (!isset($event['id'], $event['datetime'], $event['name'], $event['type'], $event['location'])) {
                continue;
            }

            if (insertEvent($pdo, $event)) {
                $newCount++;
            }
        }

        $pdo->commit();
        logFetch($pdo, count($events), $newCount, true);

        return [
            'success' => true,
            'events_fetched' => count($events),
            'events_new' => $newCount
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
// CACHING FUNCTIONS (for file-based cache of processed data)
// ============================================================================

function getCacheFilePath($key) {
    return sys_get_temp_dir() . '/samband_' . md5($key) . '.json';
}

function getFromFileCache($key, $maxAge = 300) {
    $file = getCacheFilePath($key);
    if (file_exists($file) && (time() - filemtime($file)) < $maxAge) {
        $data = json_decode(file_get_contents($file), true);
        if ($data !== null) return $data;
    }
    return null;
}

function saveToFileCache($key, $data) {
    file_put_contents(getCacheFilePath($key), json_encode($data));
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
              'byHour' => array_fill(0, 24, 0), 'byDay' => [], 'last24h' => 0, 'last7days' => 0];

    $now = new DateTime();
    $yesterday = (clone $now)->modify('-24 hours');
    $lastWeek = (clone $now)->modify('-7 days');

    foreach ($events as $event) {
        $type = $event['type'] ?? 'Okänd';
        $location = $event['location']['name'] ?? 'Okänd';
        $stats['byType'][$type] = ($stats['byType'][$type] ?? 0) + 1;
        $stats['byLocation'][$location] = ($stats['byLocation'][$location] ?? 0) + 1;

        try {
            $eventDate = new DateTime($event['datetime']);
            $stats['byHour'][(int)$eventDate->format('H')]++;
            $dayKey = $eventDate->format('Y-m-d');
            $stats['byDay'][$dayKey] = ($stats['byDay'][$dayKey] ?? 0) + 1;
            if ($eventDate >= $yesterday) $stats['last24h']++;
            if ($eventDate >= $lastWeek) $stats['last7days']++;
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

        $page = max(1, intval($_GET['page'] ?? 1));
        $filters = [];
        if (!empty($_GET['location'])) $filters['location'] = $_GET['location'];
        if (!empty($_GET['type'])) $filters['type'] = $_GET['type'];

        $allEvents = fetchPoliceEvents($filters);
        if (isset($allEvents['error'])) { echo json_encode(['error' => $allEvents['error']]); exit; }

        // Client-side type filtering as fallback
        $typeFilter = $filters['type'] ?? '';
        if ($typeFilter && is_array($allEvents)) {
            $allEvents = array_values(array_filter($allEvents, function($e) use ($typeFilter) {
                return isset($e['type']) && $e['type'] === $typeFilter;
            }));
        }

        $searchFilter = $_GET['search'] ?? '';
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
            $date = formatDate($e['datetime'] ?? date('Y-m-d H:i:s'));
            return [
                'id' => $e['id'] ?? uniqid(), 'name' => $e['name'] ?? '', 'summary' => $e['summary'] ?? '',
                'type' => $e['type'] ?? 'Okänd', 'url' => $e['url'] ?? '',
                'location' => $e['location']['name'] ?? 'Okänd', 'gps' => $e['location']['gps'] ?? null,
                'date' => $date, 'icon' => getEventIcon($e['type'] ?? ''), 'color' => getEventColor($e['type'] ?? ''),
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

        $page = max(1, intval($_GET['page'] ?? 1));
        $regionFilter = !empty($_GET['region']) ? $_GET['region'] : null;
        $searchFilter = trim($_GET['search'] ?? '');

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
        // Hidden status endpoint - returns extended stats
        header('Cache-Control: no-cache');
        echo json_encode(getExtendedStats(), JSON_PRETTY_PRINT);
        exit;
    }

    if ($_GET['ajax'] === 'backup') {
        // Trigger manual backup
        header('Cache-Control: no-cache');
        $result = createBackup();
        echo json_encode($result);
        exit;
    }
}

// ============================================================================
// BACKUP DOWNLOAD ENDPOINT
// ============================================================================

if (isset($_GET['download_backup'])) {
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
    $stats = getExtendedStats();
    ?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Status - Sambandscentralen</title>
    <style>
        :root { --bg: #0a1628; --surface: #0f1f38; --border: rgba(255,255,255,0.1); --text: #e2e8f0; --muted: #94a3b8; --accent: #fcd34d; --success: #10b981; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { font-size: 24px; margin-bottom: 8px; color: var(--accent); }
        h2 { font-size: 16px; margin: 24px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
        h3 { font-size: 14px; margin: 16px 0 8px; color: var(--muted); }
        .subtitle { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
        .card-title { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .card-value { font-size: 28px; font-weight: 700; color: var(--accent); }
        .card-sub { font-size: 12px; color: var(--muted); margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-weight: 500; font-size: 11px; text-transform: uppercase; }
        tr:hover { background: rgba(255,255,255,0.02); }
        .success { color: var(--success); }
        .danger { color: var(--danger); }
        .mono { font-family: monospace; font-size: 12px; }
        .btn { display: inline-block; padding: 8px 16px; background: var(--accent); color: var(--bg); border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; margin-right: 8px; margin-bottom: 8px; }
        .btn:hover { opacity: 0.9; }
        .btn-secondary { background: var(--surface); color: var(--text); border: 1px solid var(--border); }
        .section { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .back-link { display: inline-block; margin-bottom: 20px; color: var(--accent); text-decoration: none; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
        #backup-status { margin-top: 12px; padding: 10px; border-radius: 6px; display: none; }
        #backup-status.success { display: block; background: rgba(16,185,129,0.1); border: 1px solid var(--success); }
        #backup-status.error { display: block; background: rgba(239,68,68,0.1); border: 1px solid var(--danger); }
    </style>
</head>
<body>
    <div class="container">
        <a href="./" class="back-link">← Tillbaka till Sambandscentralen</a>
        <h1>📊 Status Dashboard</h1>
        <p class="subtitle">Intern statistik och databasöversikt • <?= date('Y-m-d H:i:s') ?></p>

        <div class="grid">
            <div class="card">
                <div class="card-title">Totalt antal händelser</div>
                <div class="card-value"><?= number_format($stats['total_events'], 0, ',', ' ') ?></div>
                <div class="card-sub">Sedan <?= $stats['date_range']['oldest'] ? date('Y-m-d', strtotime($stats['date_range']['oldest'])) : 'N/A' ?></div>
            </div>
            <div class="card">
                <div class="card-title">Senaste 24 timmarna</div>
                <div class="card-value"><?= $stats['time_stats']['last_24h'] ?></div>
                <div class="card-sub">nya händelser</div>
            </div>
            <div class="card">
                <div class="card-title">Senaste 7 dagarna</div>
                <div class="card-value"><?= $stats['time_stats']['last_7_days'] ?></div>
                <div class="card-sub">händelser</div>
            </div>
            <div class="card">
                <div class="card-title">Senaste 30 dagarna</div>
                <div class="card-value"><?= $stats['time_stats']['last_30_days'] ?></div>
                <div class="card-sub">händelser</div>
            </div>
            <div class="card">
                <div class="card-title">Unika platser</div>
                <div class="card-value"><?= $stats['unique_locations'] ?></div>
            </div>
            <div class="card">
                <div class="card-title">Händelsetyper</div>
                <div class="card-value"><?= $stats['unique_types'] ?></div>
            </div>
            <div class="card">
                <div class="card-title">Databasstorlek</div>
                <div class="card-value"><?= $stats['database']['size_mb'] ?> MB</div>
                <div class="card-sub">WAL: <?= round($stats['database']['wal_size_bytes'] / 1024, 1) ?> KB</div>
            </div>
            <div class="card">
                <div class="card-title">Senaste hämtning</div>
                <div class="card-value" style="font-size: 16px;"><?= $stats['fetch_stats']['last_fetch'] ? date('H:i', strtotime($stats['fetch_stats']['last_fetch'])) : 'Aldrig' ?></div>
                <div class="card-sub"><?= $stats['fetch_stats']['last_fetch'] ? date('Y-m-d', strtotime($stats['fetch_stats']['last_fetch'])) : '' ?></div>
            </div>
        </div>

        <div class="section">
            <h2>📅 Månadsstatistik</h2>
            <table>
                <thead><tr><th>Månad</th><th>Händelser</th></tr></thead>
                <tbody>
                <?php foreach ($stats['monthly'] as $m): ?>
                    <tr><td><?= htmlspecialchars($m['month']) ?></td><td><?= number_format($m['count'], 0, ',', ' ') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>📈 Veckostatistik</h2>
            <table>
                <thead><tr><th>Vecka</th><th>Händelser</th></tr></thead>
                <tbody>
                <?php foreach ($stats['weekly'] as $w): ?>
                    <tr><td><?= htmlspecialchars($w['week']) ?></td><td><?= number_format($w['count'], 0, ',', ' ') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr;">
            <div class="section">
                <h2>📍 Topp 15 platser</h2>
                <table>
                    <thead><tr><th>Plats</th><th>Antal</th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['top_locations'] as $loc): ?>
                        <tr><td><?= htmlspecialchars($loc['location_name']) ?></td><td><?= number_format($loc['count'], 0, ',', ' ') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="section">
                <h2>🏷️ Topp 15 typer</h2>
                <table>
                    <thead><tr><th>Typ</th><th>Antal</th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['top_types'] as $type): ?>
                        <tr><td><?= htmlspecialchars($type['type']) ?></td><td><?= number_format($type['count'], 0, ',', ' ') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <h2>🔄 Senaste hämtningar</h2>
            <table>
                <thead><tr><th>Tidpunkt</th><th>Hämtade</th><th>Nya</th><th>Status</th><th>Fel</th></tr></thead>
                <tbody>
                <?php foreach ($stats['recent_fetches'] as $f): ?>
                    <tr>
                        <td class="mono"><?= htmlspecialchars($f['fetched_at']) ?></td>
                        <td><?= $f['events_fetched'] ?></td>
                        <td><?= $f['events_new'] ?></td>
                        <td class="<?= $f['success'] ? 'success' : 'danger' ?>"><?= $f['success'] ? '✓ OK' : '✗ Fel' ?></td>
                        <td class="mono" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($f['error_message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>💾 Säkerhetskopior</h2>
            <p style="margin-bottom: 16px; color: var(--muted); font-size: 13px;">Automatiska veckovisa säkerhetskopior. Senaste 10 sparas.</p>

            <button class="btn" onclick="createBackup()">Skapa ny säkerhetskopia</button>
            <div id="backup-status"></div>

            <?php if (empty($stats['backups'])): ?>
                <p style="color: var(--muted); margin-top: 16px;">Inga säkerhetskopior ännu.</p>
            <?php else: ?>
                <table style="margin-top: 16px;">
                    <thead><tr><th>Fil</th><th>Storlek</th><th>Skapad</th><th>Ladda ner</th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['backups'] as $backup): ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars($backup['filename']) ?></td>
                            <td><?= round($backup['size'] / (1024 * 1024), 2) ?> MB</td>
                            <td class="mono"><?= date('Y-m-d H:i', strtotime($backup['created'])) ?></td>
                            <td><a href="?download_backup=<?= urlencode($backup['filename']) ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;">⬇️ Ladda ner</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2>🖥️ Systeminformation</h2>
            <table>
                <tr><td>PHP-version</td><td class="mono"><?= $stats['php_version'] ?></td></tr>
                <tr><td>Servertid</td><td class="mono"><?= $stats['server_time'] ?></td></tr>
                <tr><td>Databassökväg</td><td class="mono"><?= htmlspecialchars($stats['database']['path']) ?></td></tr>
                <tr><td>Totalt antal hämtningar</td><td><?= $stats['fetch_stats']['total_fetches'] ?></td></tr>
                <tr><td>Lyckade hämtningar</td><td><?= $stats['fetch_stats']['successful'] ?></td></tr>
                <tr><td>Totalt nya händelser (via hämtningar)</td><td><?= $stats['fetch_stats']['total_new_events'] ?></td></tr>
            </table>
        </div>

        <p style="text-align: center; color: var(--muted); font-size: 12px; margin-top: 40px;">
            Sambandscentralen Status Dashboard • v<?= ASSET_VERSION ?>
        </p>
    </div>

    <script>
    async function createBackup() {
        const status = document.getElementById('backup-status');
        status.className = '';
        status.style.display = 'none';
        status.textContent = 'Skapar säkerhetskopia...';

        try {
            const res = await fetch('?ajax=backup');
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
    </script>
</body>
</html>
    <?php
    exit;
}

// ============================================================================
// MAIN PAGE
// ============================================================================

$locationFilter = trim($_GET['location'] ?? '');
$customLocation = trim($_GET['customLocation'] ?? '');
if ($customLocation) {
    $locationFilter = $customLocation;
} elseif ($locationFilter === '__custom__') {
    $locationFilter = '';
}
$typeFilter = trim($_GET['type'] ?? '');
$searchFilter = trim($_GET['search'] ?? '');
$currentView = $_GET['view'] ?? 'list';
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
                            $date = formatDate($event['datetime'] ?? date('Y-m-d H:i:s'));
                            $type = $event['type'] ?? 'Okänd';
                            $color = getEventColor($type);
                            $icon = getEventIcon($type);
                            $location = $event['location']['name'] ?? 'Okänd';
                            $gps = $event['location']['gps'] ?? null;
                        ?>
                            <article class="event-card" style="animation-delay: <?= min($i * 0.02, 0.2) ?>s">
                                <div class="event-card-inner">
                                    <div class="event-date">
                                        <div class="day"><?= $date['day'] ?></div>
                                        <div class="month"><?= $date['month'] ?></div>
                                        <div class="time"><?= $date['time'] ?></div>
                                        <div class="relative"><?= $date['relative'] ?></div>
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
                                                <a href="https://polisen.se<?= htmlspecialchars($event['url']) ?>" target="_blank" rel="noopener noreferrer" class="read-more-link"><span>🔗</span> polisen.se</a>
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
    window.eventsData = <?= json_encode(is_array($events) && !isset($events['error']) ? array_map(fn($e) => ['name' => $e['name'] ?? '', 'summary' => $e['summary'] ?? '', 'type' => $e['type'] ?? '', 'url' => $e['url'] ?? '', 'location' => $e['location']['name'] ?? '', 'gps' => $e['location']['gps'] ?? null, 'datetime' => $e['datetime'] ?? '', 'icon' => getEventIcon($e['type'] ?? ''), 'color' => getEventColor($e['type'] ?? '')], $events) : []) ?>;
    </script>
    <script src="js/app.js?v=<?= ASSET_VERSION ?>" defer></script>
</body>
</html>
