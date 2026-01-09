<?php
/**
 * Sambandscentralen
 * Geten Claude bräker nytt från utryckningens rytt
 *
 * @version 4.0 - Performance optimized
 */

date_default_timezone_set('Europe/Stockholm');
define('CACHE_TIME', 300);           // 5 minutes for events
define('STALE_CACHE_TIME', 600);     // 10 minutes stale-while-revalidate window
define('EVENTS_PER_PAGE', 40);
define('ASSET_VERSION', '1.0.0');    // Bump this to bust browser cache

// VPS API Configuration
define('VPS_API_URL', 'http://193.181.23.219:8000');
define('VPS_API_KEY', 'CB5l7O1F-OwVKtuybmyRTQAfKhrgLnlz7IPhrfJhKZU');
define('VPS_API_TIMEOUT', 5);        // Short timeout for fast fallback

/**
 * Get cache file path for filters
 */
function getCacheFilePath($filters) {
    $cacheKey = md5(serialize($filters));
    return sys_get_temp_dir() . '/police_events_' . $cacheKey . '.json';
}

/**
 * Get cache metadata file path (stores last refresh time)
 */
function getCacheMetaPath($filters) {
    $cacheKey = md5(serialize($filters));
    return sys_get_temp_dir() . '/police_events_meta_' . $cacheKey . '.json';
}

/**
 * Get data from cache with stale-while-revalidate support
 * Returns: ['data' => ..., 'stale' => bool, 'age' => seconds]
 */
function getFromCacheWithMeta($filters) {
    $cacheFile = getCacheFilePath($filters);
    if (!file_exists($cacheFile)) {
        return null;
    }

    $age = time() - filemtime($cacheFile);
    $data = json_decode(file_get_contents($cacheFile), true);

    if ($data === null) {
        return null;
    }

    return [
        'data' => $data,
        'stale' => $age >= CACHE_TIME,
        'age' => $age,
        'expired' => $age >= STALE_CACHE_TIME
    ];
}

/**
 * Legacy function for backwards compatibility
 */
function getFromCache($filters) {
    $result = getFromCacheWithMeta($filters);
    if ($result === null || $result['expired']) {
        return null;
    }
    return $result['data'];
}

function saveToCache($filters, $data) {
    file_put_contents(getCacheFilePath($filters), json_encode($data));
}

/**
 * Trigger async cache refresh (non-blocking)
 */
function triggerAsyncRefresh($filters) {
    $metaFile = getCacheMetaPath($filters);
    $lockFile = $metaFile . '.lock';

    // Check if refresh is already in progress
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 30) {
        return; // Another process is refreshing
    }

    // Create lock
    file_put_contents($lockFile, time());

    // Perform refresh synchronously (in shared hosting we can't do true async)
    // But we serve stale content first, then refresh
    register_shutdown_function(function() use ($filters, $lockFile) {
        // This runs after response is sent
        if (connection_status() === CONNECTION_NORMAL) {
            fetchPoliceEventsForce($filters);
        }
        @unlink($lockFile);
    });
}

/**
 * Fetch from VPS API
 */
function fetchFromVpsApi($endpoint, $params = []) {
    $url = VPS_API_URL . $endpoint;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => VPS_API_TIMEOUT,
            'header' => "X-API-Key: " . VPS_API_KEY . "\r\nAccept: application/json",
            'ignore_errors' => true
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    return $data;
}

/**
 * Fetch locations from VPS API (all locations with event counts)
 */
function fetchLocationsFromVps() {
    $cacheFile = sys_get_temp_dir() . '/samband_locations.json';

    // Check cache (1 hour)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }

    $data = fetchFromVpsApi('/api/locations');
    if ($data && is_array($data)) {
        file_put_contents($cacheFile, json_encode($data));
        return $data;
    }

    // Return cached even if stale
    if (file_exists($cacheFile)) {
        return json_decode(file_get_contents($cacheFile), true) ?: [];
    }

    return [];
}

/**
 * Check VPS API health status
 * Returns: ['online' => bool, 'latency_ms' => int|null, 'total_events' => int|null]
 */
function checkVpsHealth() {
    $cacheFile = sys_get_temp_dir() . '/samband_health.json';

    // Check cache (30 seconds)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 30) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }

    $start = microtime(true);
    $data = fetchFromVpsApi('/health');
    $latency = round((microtime(true) - $start) * 1000);

    if ($data && isset($data['status']) && $data['status'] === 'ok') {
        // Get stats for total events count and date range
        $stats = fetchFromVpsApi('/api/stats');
        $result = [
            'online' => true,
            'latency_ms' => $latency,
            'total_events' => $stats['total'] ?? null,
            'oldest_date' => $stats['date_range']['oldest'] ?? null,
            'newest_date' => $stats['date_range']['latest'] ?? null,
            'checked_at' => date('Y-m-d H:i:s')
        ];
    } else {
        $result = [
            'online' => false,
            'latency_ms' => null,
            'total_events' => null,
            'oldest_date' => null,
            'newest_date' => null,
            'checked_at' => date('Y-m-d H:i:s')
        ];
    }

    file_put_contents($cacheFile, json_encode($result));
    return $result;
}

/**
 * Force fetch from API (bypass cache)
 * Tries VPS API first, falls back to Police API
 */
function fetchPoliceEventsForce($filters = []) {
    // Try VPS API first
    $vpsParams = [];
    if (!empty($filters['location'])) $vpsParams['location'] = $filters['location'];
    if (!empty($filters['type'])) $vpsParams['type'] = $filters['type'];
    if (!empty($filters['date'])) $vpsParams['date'] = $filters['date'];
    if (!empty($filters['from'])) $vpsParams['from'] = $filters['from'];
    if (!empty($filters['to'])) $vpsParams['to'] = $filters['to'];

    $vpsResponse = fetchFromVpsApi('/api/events/raw', $vpsParams);
    if ($vpsResponse !== null && is_array($vpsResponse) && !isset($vpsResponse['error'])) {
        if (!isset($vpsResponse['error'])) saveToCache($filters, $vpsResponse);
        return $vpsResponse;
    }

    // Fallback to Police API
    $baseUrl = 'https://polisen.se/api/events';
    $queryParams = [];

    if (!empty($filters['location'])) $queryParams['locationname'] = $filters['location'];
    if (!empty($filters['type'])) $queryParams['type'] = $filters['type'];
    if (!empty($filters['date'])) $queryParams['DateTime'] = $filters['date'];

    $url = $baseUrl . (!empty($queryParams) ? '?' . http_build_query($queryParams) : '');

    $context = stream_context_create([
        'http' => ['timeout' => 15, 'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36\r\nAccept: application/json"]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) return ['error' => 'Kunde inte hämta data från Polisens API.'];

    $data = json_decode($response, true) ?: [];
    if (!isset($data['error'])) saveToCache($filters, $data);
    return $data;
}

/**
 * Send HTTP cache headers for API responses
 */
function sendCacheHeaders($maxAge = 60, $staleWhileRevalidate = 120) {
    header('Cache-Control: public, max-age=' . $maxAge . ', stale-while-revalidate=' . $staleWhileRevalidate);
    header('Vary: Accept-Encoding');
}

function fetchPoliceEvents($filters = []) {
    // Try to get from cache with metadata
    $cached = getFromCacheWithMeta($filters);

    if ($cached !== null) {
        // If cache is stale but not expired, serve stale and trigger background refresh
        if ($cached['stale'] && !$cached['expired']) {
            triggerAsyncRefresh($filters);
            return $cached['data'];
        }
        // If cache is fresh, serve it
        if (!$cached['stale']) {
            return $cached['data'];
        }
    }

    // Cache is expired or doesn't exist, fetch fresh
    return fetchPoliceEventsForce($filters);
}

function getDetailCacheFilePath($eventUrl) {
    $cacheKey = md5($eventUrl);
    return sys_get_temp_dir() . '/police_event_detail_' . $cacheKey . '.json';
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
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: sv-SE,sv;q=0.9,en;q=0.8"
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $html = @file_get_contents($fullUrl, false, $context);
    if ($html === false) return null;

    $details = [];

    // polisen.se event pages typically have the content in a specific structure
    // Try multiple patterns to extract the main text content

    // Pattern 1: Look for the main event body content (common polisen.se structure)
    if (preg_match('/<div[^>]*class="[^"]*(?:text-body|body-content|article-body|event-body|hpt-body)[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
        $content = $matches[1];
        // Remove script and style tags
        $content = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $content);
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = preg_replace('/\s+/', ' ', $content);
        $details['content'] = trim($content);
    }

    // Pattern 2: Look for main content area
    if (empty($details['content'])) {
        if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $matches)) {
            // Find paragraphs within main
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
            // Get all paragraphs, excluding those that look like navigation or metadata
            if (preg_match_all('/<p(?:\s[^>]*)?>([^<]{20,})<\/p>/is', $matches[1], $pMatches)) {
                $paragraphs = array_map(function($p) {
                    $text = strip_tags($p);
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                    return trim(preg_replace('/\s+/', ' ', $text));
                }, $pMatches[1]);
                $paragraphs = array_filter($paragraphs, function($p) {
                    // Filter out navigation-like text
                    return strlen($p) > 20 && !preg_match('/^(Dela|Skriv ut|Tipsa|Läs mer|Tillbaka)/i', $p);
                });
                if (!empty($paragraphs)) {
                    $details['content'] = implode("\n\n", $paragraphs);
                }
            }
        }
    }

    // Pattern 4: Last resort - find any substantial text blocks on the page
    if (empty($details['content'])) {
        if (preg_match_all('/<(?:p|div)[^>]*>([^<]{50,})<\/(?:p|div)>/is', $html, $matches)) {
            $blocks = array_map(function($text) {
                $text = strip_tags($text);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                return trim(preg_replace('/\s+/', ' ', $text));
            }, $matches[1]);
            // Filter to get only content-like blocks
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

function cleanEventName($name, $type, $location) {
    // Remove date prefix pattern like "03 januari 08.37, " from the name
    $cleaned = preg_replace('/^\d{1,2}\s+\w+\s+\d{1,2}[\.:]\d{2},?\s*/', '', $name);
    // Remove the type if it's at the start
    if (stripos($cleaned, $type) === 0) {
        $cleaned = trim(substr($cleaned, strlen($type)));
        $cleaned = ltrim($cleaned, ', ');
    }
    // Remove location if it's the only thing left
    if (trim($cleaned) === $location || empty(trim($cleaned))) {
        return null;
    }
    // Remove trailing location if present
    if ($location && preg_match('/,\s*' . preg_quote($location, '/') . '\s*$/i', $cleaned)) {
        $cleaned = preg_replace('/,\s*' . preg_quote($location, '/') . '\s*$/i', '', $cleaned);
    }
    return trim($cleaned) ?: null;
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

    // If specific region requested, only fetch that one
    $regionsToFetch = $regionFilter ? [$regionFilter => $regions[$regionFilter] ?? $regionFilter] : $regions;

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36\r\nAccept: application/rss+xml, application/xml, text/xml"
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);

    foreach ($regionsToFetch as $slug => $name) {
        $url = "https://polisen.se/aktuellt/rss/{$slug}/press-rss---{$slug}/";
        $xml = @file_get_contents($url, false, $context);

        if ($xml === false) continue;

        // Suppress warnings for malformed XML
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

    // Sort by date, newest first
    usort($allItems, fn($a, $b) => $b['timestamp'] - $a['timestamp']);

    // Cache the results
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

// AJAX endpoints
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['ajax'] === 'events') {
        // Cache events for 60s, allow stale for 5min
        sendCacheHeaders(60, 300);

        $page = max(1, intval($_GET['page'] ?? 1));
        $filters = [];
        if (!empty($_GET['location'])) $filters['location'] = $_GET['location'];
        if (!empty($_GET['type'])) $filters['type'] = $_GET['type'];

        $allEvents = fetchPoliceEvents($filters);
        if (isset($allEvents['error'])) { echo json_encode(['error' => $allEvents['error']]); exit; }

        // Client-side type filtering as fallback (API may not filter correctly when no location is specified)
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
        // Cache stats for 2 minutes
        sendCacheHeaders(120, 300);
        echo json_encode(calculateStats(fetchPoliceEvents()));
        exit;
    }

    if ($_GET['ajax'] === 'details') {
        // Cache details for 1 hour (they rarely change)
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
        // Cache press releases for 2 minutes
        sendCacheHeaders(120, 600);

        $page = max(1, intval($_GET['page'] ?? 1));
        $regionFilter = !empty($_GET['region']) ? $_GET['region'] : null;
        $searchFilter = trim($_GET['search'] ?? '');

        $allPress = fetchPressReleases($regionFilter);

        // Apply search filter
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
        // Cache press details for 1 hour
        sendCacheHeaders(3600, 7200);

        $pressUrl = $_GET['url'] ?? '';
        if (empty($pressUrl)) {
            echo json_encode(['error' => 'No URL provided']);
            exit;
        }

        // Validate URL is from polisen.se
        if (strpos($pressUrl, 'https://polisen.se/') !== 0) {
            echo json_encode(['error' => 'Invalid URL']);
            exit;
        }

        // Check cache (cache for 1 hour)
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
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: sv-SE,sv;q=0.9,en;q=0.8"
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);

        $html = @file_get_contents($pressUrl, false, $context);
        if ($html === false) {
            echo json_encode(['error' => 'Could not fetch press release']);
            exit;
        }

        $details = ['content' => ''];

        // Pattern 1: Look for article body content
        if (preg_match('/<div[^>]*class="[^"]*(?:text-body|body-content|article-body|editorial-body|news-body|hpt-body)[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
            $content = $matches[1];
            $content = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $content);
            $content = strip_tags($content);
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
            $content = preg_replace('/\s+/', ' ', $content);
            $details['content'] = trim($content);
        }

        // Pattern 2: Look for main content with paragraphs
        if (empty($details['content'])) {
            if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $pMatches)) {
                $paragraphs = [];
                foreach ($pMatches[1] as $p) {
                    $text = strip_tags($p);
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                    $text = trim(preg_replace('/\s+/', ' ', $text));
                    if (strlen($text) > 50) { // Only include substantial paragraphs
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
}

// Main page
$locationFilter = trim($_GET['location'] ?? '');
$customLocation = trim($_GET['customLocation'] ?? '');
// Use custom location if provided, otherwise use dropdown selection
if ($customLocation) {
    $locationFilter = $customLocation;
} elseif ($locationFilter === '__custom__') {
    $locationFilter = ''; // Reset if __custom__ was selected but no custom value provided
}
$typeFilter = trim($_GET['type'] ?? '');
$searchFilter = trim($_GET['search'] ?? '');
$currentView = $_GET['view'] ?? 'list';

$allEvents = fetchPoliceEvents();
$filters = [];
if ($locationFilter) $filters['location'] = $locationFilter;
if ($typeFilter) $filters['type'] = $typeFilter;

$events = !empty($filters) ? fetchPoliceEvents($filters) : $allEvents;

// Client-side type filtering as fallback (API may not filter correctly when no location is specified)
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

// Fetch locations from VPS API (complete list with counts)
$vpsLocations = fetchLocationsFromVps();
$locations = [];
if (!empty($vpsLocations)) {
    // VPS returns [{"name": "Stockholm", "count": 123}, ...]
    foreach ($vpsLocations as $loc) {
        if (isset($loc['name'])) {
            $locations[] = $loc['name'];
        }
    }
} else {
    // Fallback: extract from current events
    if (is_array($allEvents) && !isset($allEvents['error'])) {
        foreach ($allEvents as $e) {
            if (isset($e['location']['name']) && !in_array($e['location']['name'], $locations)) {
                $locations[] = $e['location']['name'];
            }
        }
    }
}
sort($locations);

// Extract types from events (these change rarely)
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
$apiHealth = checkVpsHealth();
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
                            $cleanedName = cleanEventName($event['name'] ?? '', $type, $location);
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
                <span class="api-status <?= $apiHealth['online'] ? 'online' : 'offline' ?>">
                    <?= $apiHealth['online'] ? '🟢' : '🔴' ?> API <?= $apiHealth['online'] ? 'online' : 'offline' ?>
                </span>
                <?php if ($apiHealth['online'] && $apiHealth['total_events']): ?>
                    • <strong><?= number_format($apiHealth['total_events'], 0, ',', ' ') ?></strong> händelser i arkivet
                    <?php if ($apiHealth['oldest_date']): ?>
                        (sedan <?= date('Y-m-d', strtotime($apiHealth['oldest_date'])) ?>)
                    <?php endif; ?>
                <?php endif; ?>
                • Uppdateras var 5:e minut
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
