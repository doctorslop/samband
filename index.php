<?php
/**
 * Sambandscentralen - Polisens Händelsenotiser
 * En modern och responsiv app för att visa polisens utryckningar i Sverige
 * 
 * @version 3.0
 */

date_default_timezone_set('Europe/Stockholm');
define('CACHE_TIME', 300);
define('EVENTS_PER_PAGE', 20);

function getCacheFilePath($filters) {
    $cacheKey = md5(serialize($filters));
    return sys_get_temp_dir() . '/police_events_' . $cacheKey . '.json';
}

function getFromCache($filters) {
    $cacheFile = getCacheFilePath($filters);
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TIME) {
        return json_decode(file_get_contents($cacheFile), true);
    }
    return null;
}

function saveToCache($filters, $data) {
    file_put_contents(getCacheFilePath($filters), json_encode($data));
}

function fetchPoliceEvents($filters = []) {
    $cached = getFromCache($filters);
    if ($cached !== null) return $cached;

    $baseUrl = 'https://polisen.se/api/events';
    $queryParams = [];

    if (!empty($filters['location'])) $queryParams['locationname'] = $filters['location'];
    if (!empty($filters['type'])) $queryParams['type'] = $filters['type'];
    if (!empty($filters['date'])) $queryParams['DateTime'] = $filters['date'];

    $url = $baseUrl . (!empty($queryParams) ? '?' . http_build_query($queryParams) : '');

    $context = stream_context_create([
        'http' => ['timeout' => 15, 'header' => "User-Agent: Sambandscentralen/3.0\r\nAccept: application/json"]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) return ['error' => 'Kunde inte hämta data från Polisens API.'];

    $data = json_decode($response, true) ?: [];
    if (!isset($data['error'])) saveToCache($filters, $data);
    return $data;
}

function getDetailCacheFilePath($eventUrl) {
    $cacheKey = md5($eventUrl);
    return sys_get_temp_dir() . '/police_event_detail_' . $cacheKey . '.json';
}

function fetchEventDetails($eventUrl) {
    if (empty($eventUrl)) return null;

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
        'Polisinsats' => '🚔', 'Mord' => '⚠️', 'Olaga hot' => '⚡', 'Våld' => '🛑',
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

// AJAX endpoints
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'events') {
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
        echo json_encode(calculateStats(fetchPoliceEvents()));
        exit;
    }

    if ($_GET['ajax'] === 'details') {
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
}

// Main page
$locationFilter = trim($_GET['location'] ?? '');
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

$locations = $types = [];
if (is_array($allEvents) && !isset($allEvents['error'])) {
    foreach ($allEvents as $e) {
        if (isset($e['location']['name']) && !in_array($e['location']['name'], $locations)) $locations[] = $e['location']['name'];
        if (isset($e['type']) && !in_array($e['type'], $types)) $types[] = $e['type'];
    }
    sort($locations); sort($types);
}

$eventCount = is_array($events) && !isset($events['error']) ? count($events) : 0;
$stats = calculateStats($allEvents);
$initialEvents = is_array($events) ? array_slice($events, 0, EVENTS_PER_PAGE) : [];
$hasMorePages = $eventCount > EVENTS_PER_PAGE;
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Sambandscentralen - Aktuella händelsenotiser från Svenska Polisen i realtid">
    <meta name="theme-color" content="#0a1628" id="theme-color-meta">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Sambandscentralen">
    <meta property="og:title" content="Sambandscentralen">
    <meta property="og:description" content="Aktuella händelsenotiser från Svenska Polisen i realtid">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://<?= $_SERVER['HTTP_HOST'] ?? 'sambandscentralen.se' ?><?= $_SERVER['REQUEST_URI'] ?? '/' ?>">
    <meta property="og:image" content="https://<?= $_SERVER['HTTP_HOST'] ?? 'sambandscentralen.se' ?>/og-image.php">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="Sambandscentralen">
    <meta property="og:locale" content="sv_SE">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Sambandscentralen">
    <meta name="twitter:description" content="Aktuella händelsenotiser från Svenska Polisen i realtid">
    <meta name="twitter:image" content="https://<?= $_SERVER['HTTP_HOST'] ?? 'sambandscentralen.se' ?>/og-image.php">
    
    <title>Sambandscentralen</title>
    
    <link rel="icon" type="image/x-icon" href="icons/favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="icons/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="icons/apple-touch-icon.png">
    <link rel="manifest" href="manifest.json">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    
    <style>
        :root {
            --radius: 16px; --radius-sm: 8px;
            --primary: #0a1628; --primary-light: #1a2d4a; --accent: #fcd34d; --accent-dark: #d4a82c;
            --accent-glow: rgba(252, 211, 77, 0.4); --text: #e2e8f0; --text-muted: #94a3b8;
            --surface: #0f1f38; --surface-light: #162a48; --border: rgba(255, 255, 255, 0.08);
            --success: #10b981; --danger: #ef4444; --shadow: rgba(0, 0, 0, 0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        
        body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--primary); color: var(--text); min-height: 100vh; line-height: 1.6;
            -webkit-font-smoothing: antialiased; overflow-x: hidden;
        }

        body::before {
            content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 0;
            background: radial-gradient(ellipse at 20% 0%, rgba(252, 211, 77, 0.06) 0%, transparent 50%),
                        radial-gradient(ellipse at 80% 100%, rgba(59, 130, 246, 0.06) 0%, transparent 50%);
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 0 40px; position: relative; z-index: 1; }

        header {
            position: sticky; top: 0; z-index: 100;
            background: var(--primary); border-bottom: 1px solid var(--border);
            margin: 0 -40px 20px; padding: 24px 40px 20px;
            width: calc(100% + 80px);
        }

        .header-content {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 16px; max-width: 1320px; margin: 0 auto;
        }

        .logo { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .logo-icon {
            width: 44px; height: 44px; min-width: 44px; min-height: 44px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            font-size: 22px; box-shadow: 0 4px 16px var(--accent-glow); transition: transform 0.3s;
        }
        .logo:hover .logo-icon { transform: scale(1.05) rotate(-3deg); }
        .logo-icon.radio-playing { animation: radioGlow 1.5s ease-in-out infinite; }
        @keyframes radioGlow { 0%, 100% { box-shadow: 0 4px 16px var(--accent-glow); } 50% { box-shadow: 0 4px 24px var(--accent-glow), 0 0 30px var(--accent-glow); } }
        .logo-text { min-width: 0; }
        .logo-text h1 { font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 700; line-height: 1.2; }
        .logo-text p { font-size: 12px; color: var(--text-muted); line-height: 1.3; }

        .header-controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .view-toggle {
            display: flex; background: var(--surface); border-radius: var(--radius-sm);
            padding: 3px; border: 1px solid var(--border);
        }
        .view-toggle button {
            padding: 6px 14px; background: transparent; border: none; color: var(--text-muted);
            cursor: pointer; border-radius: 5px; font-size: 13px; transition: all 0.2s;
            display: flex; align-items: center; gap: 5px;
        }
        .view-toggle button.active { background: var(--accent); color: var(--primary); }
        .view-toggle button:hover:not(.active) { color: var(--text); background: var(--surface-light); }

        .live-indicator {
            display: flex; align-items: center; gap: 5px; padding: 5px 10px;
            background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 50px; font-size: 11px; color: var(--success);
        }
        .live-dot {
            width: 7px; height: 7px; background: var(--success); border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; transform: scale(1.3); } }

        .filters-section { margin-bottom: 20px; }
        .search-bar {
            background: var(--surface); border-radius: var(--radius); padding: 6px;
            border: 1px solid var(--border); margin-bottom: 10px;
        }
        .search-form { display: flex; gap: 6px; flex-wrap: wrap; }
        .search-input-wrapper { flex: 1; min-width: 180px; position: relative; }
        .search-input-wrapper::before {
            content: '🔍'; position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            font-size: 13px; opacity: 0.6; pointer-events: none;
        }
        .search-input {
            width: 100%; padding: 10px 12px 10px 38px; background: var(--primary);
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            color: var(--text); font-size: 13px; font-family: inherit; transition: all 0.2s;
        }
        .search-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        .search-input::placeholder { color: var(--text-muted); }

        .filter-select {
            padding: 10px 32px 10px 12px; background: var(--primary); border: 1px solid var(--border);
            border-radius: var(--radius-sm); color: var(--text); font-size: 13px; font-family: inherit;
            cursor: pointer; appearance: none; min-width: 140px; transition: all 0.2s;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .filter-select:focus { outline: none; border-color: var(--accent); }
        .filter-select option { background: var(--primary); color: var(--text); }

        .btn {
            padding: 10px 20px; background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: var(--primary); border: none; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; white-space: nowrap;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 16px var(--accent-glow); }
        .btn-secondary { background: var(--surface-light); color: var(--text); }
        .btn-secondary:hover { background: var(--primary-light); box-shadow: none; }

        .active-filters { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-tag {
            display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px;
            background: var(--surface-light); border-radius: 50px; font-size: 11px;
            border: 1px solid var(--border);
        }
        .filter-tag a {
            color: var(--text-muted); text-decoration: none; font-size: 13px;
            width: 16px; height: 16px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; transition: all 0.2s;
        }
        .filter-tag a:hover { color: var(--danger); background: rgba(239, 68, 68, 0.1); }

        .main-content { display: flex; gap: 20px; }
        .content-area { flex: 1; min-width: 0; }

        .events-grid { display: grid; gap: 10px; }
        .event-card {
            background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);
            overflow: hidden; transition: all 0.3s; animation: slideIn 0.4s ease-out backwards;
        }
        @keyframes slideIn { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        .event-card:hover { border-color: var(--accent-glow); transform: translateY(-2px); box-shadow: 0 8px 24px var(--shadow); }

        .event-card-inner { display: flex; gap: 14px; padding: 16px; }
        .event-date { flex-shrink: 0; text-align: center; min-width: 60px; padding: 4px 0; }
        .event-date .day { font-size: 26px; font-weight: 700; color: var(--accent); line-height: 1; }
        .event-date .month { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        .event-date .time { font-size: 10px; color: var(--text-muted); margin-top: 8px; padding: 3px 6px; background: var(--primary); border-radius: 3px; display: inline-block; }
        .event-date .relative { font-size: 9px; color: var(--success); margin-top: 6px; }

        .event-content { flex: 1; min-width: 0; }
        .event-header { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px; }
        .event-icon { font-size: 20px; line-height: 1; flex-shrink: 0; }
        .event-title-group { flex: 1; min-width: 0; }
        .event-type { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; text-decoration: none; cursor: pointer; transition: all 0.2s; }
        .event-type:hover { opacity: 0.8; transform: scale(1.05); }
        .event-location-link { display: block; font-size: 14px; font-weight: 600; color: var(--text); text-decoration: none; margin-top: 4px; transition: color 0.2s; }
        .event-location-link:hover { color: var(--accent); }
        .event-summary { color: var(--text-muted); font-size: 13px; line-height: 1.5; margin-top: 8px; }

        .event-meta { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 10px; }

        .read-more-link {
            display: inline-flex; align-items: center; gap: 4px; color: var(--accent);
            text-decoration: none; font-size: 11px; font-weight: 500; transition: all 0.2s; margin-left: auto;
        }
        .read-more-link span { opacity: 0.7; }
        .read-more-link:hover { text-decoration: underline; }

        .show-details-btn {
            background: transparent; border: 1px solid var(--border); color: var(--text-muted);
            padding: 4px 10px; border-radius: 4px; font-size: 11px; cursor: pointer;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px;
        }
        .show-details-btn:hover { border-color: var(--accent); color: var(--accent); }
        .show-details-btn.loading { opacity: 0.6; cursor: wait; }
        .show-details-btn.expanded { background: var(--accent); color: var(--primary); border-color: var(--accent); }

        .event-details {
            display: none; margin-top: 12px; padding: 12px; background: var(--primary);
            border-radius: var(--radius-sm); border: 1px solid var(--border);
            font-size: 13px; line-height: 1.7; color: var(--text); white-space: pre-wrap;
        }
        .event-details.visible { display: block; animation: fadeIn 0.3s ease-out; }
        .event-details.error { color: var(--text-muted); font-style: italic; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

        .empty-state { text-align: center; padding: 50px 20px; color: var(--text-muted); }
        .empty-state-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }
        .empty-state h2 { font-size: 18px; color: var(--text); margin-bottom: 8px; }

        .loading-more { display: flex; justify-content: center; padding: 20px; }
        .spinner { width: 28px; height: 28px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .map-container { display: none; height: 550px; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); }
        .map-container.active { display: block; }
        #map { height: 100%; width: 100%; }
        .leaflet-popup-content-wrapper { background: var(--surface); color: var(--text); border-radius: var(--radius-sm); }
        .leaflet-popup-tip { background: var(--surface); }
        .map-popup { max-width: 260px; }
        .map-popup h3 { font-size: 13px; margin-bottom: 5px; }
        .map-popup p { font-size: 11px; color: var(--text-muted); margin-bottom: 6px; }
        .map-popup .badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; }
        .map-popup a { color: var(--accent); text-decoration: none; font-size: 11px; }

        .stats-sidebar { width: 300px; flex-shrink: 0; display: none; }
        .stats-sidebar.active { display: block; }
        .stats-card { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); padding: 16px; margin-bottom: 14px; }
        .stats-card h3 { font-size: 13px; font-weight: 600; margin-bottom: 14px; display: flex; align-items: center; gap: 6px; }
        .stat-number { font-size: 28px; font-weight: 700; color: var(--accent); }
        .stat-label { font-size: 11px; color: var(--text-muted); }
        .stat-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); }
        .stat-row:last-child { border-bottom: none; }
        .stat-row-label { font-size: 12px; display: flex; align-items: center; gap: 5px; }
        .stat-row-value { font-size: 13px; font-weight: 600; color: var(--accent); }
        .stat-bar { height: 5px; background: var(--border); border-radius: 2px; margin-top: 3px; overflow: hidden; }
        .stat-bar-fill { height: 100%; background: var(--accent); border-radius: 2px; transition: width 0.5s; }
        .hour-chart { display: flex; align-items: flex-end; gap: 2px; height: 70px; margin-top: 10px; }
        .hour-bar { flex: 1; background: var(--accent); border-radius: 2px 2px 0 0; min-height: 2px; transition: height 0.3s; opacity: 0.7; }
        .hour-bar:hover { opacity: 1; }

        footer { margin-top: 40px; padding: 20px 0; border-top: 1px solid var(--border); text-align: center; color: var(--text-muted); font-size: 11px; }
        footer a { color: var(--accent); text-decoration: none; }
        footer a:hover { text-decoration: underline; }

        .scroll-top, .refresh-btn {
            position: fixed; bottom: 20px; width: 40px; height: 40px; border-radius: 50%;
            border: none; cursor: pointer; transition: all 0.3s; z-index: 100;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }
        .scroll-top { right: 20px; background: var(--accent); color: var(--primary); opacity: 0; visibility: hidden; box-shadow: 0 4px 12px var(--accent-glow); }
        .scroll-top.visible { opacity: 1; visibility: visible; }
        .scroll-top:hover { transform: translateY(-3px); }
        .refresh-btn { left: 20px; background: var(--surface); color: var(--text); border: 1px solid var(--border); }
        .refresh-btn:hover { background: var(--surface-light); transform: rotate(180deg); }
        .refresh-btn.loading { animation: spin 1s linear infinite; }

        .install-prompt {
            display: none; position: fixed; bottom: 70px; left: 20px; right: 20px; max-width: 360px;
            background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            padding: 14px; box-shadow: 0 8px 24px var(--shadow); z-index: 200;
        }
        .install-prompt.show { display: block; }
        .install-prompt h4 { font-size: 13px; margin-bottom: 6px; }
        .install-prompt p { font-size: 11px; color: var(--text-muted); margin-bottom: 10px; }
        .install-prompt-buttons { display: flex; gap: 6px; }
        .install-prompt-buttons button { flex: 1; padding: 8px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 600; cursor: pointer; border: none; }
        .install-btn { background: var(--accent); color: var(--primary); }
        .dismiss-btn { background: var(--surface-light); color: var(--text); }

        @media (max-width: 1024px) { .stats-sidebar { display: none !important; } .main-content { flex-direction: column; } }
        @media (max-width: 768px) {
            .container { padding: 0 20px; padding-left: max(20px, env(safe-area-inset-left)); padding-right: max(20px, env(safe-area-inset-right)); }
            header { margin: 0 -20px 16px; padding: 12px 20px; width: calc(100% + 40px); }
            .header-content { flex-direction: row; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: nowrap; }
            .logo { justify-content: flex-start; gap: 8px; }
            .logo-icon { width: 36px; height: 36px; min-width: 36px; min-height: 36px; font-size: 18px; border-radius: 8px; }
            .logo-text h1 { font-size: 15px; }
            .logo-text p { font-size: 10px; }
            .header-controls { flex-wrap: nowrap; gap: 6px; }
            .view-toggle { padding: 2px; }
            .view-toggle button { padding: 5px 8px; font-size: 12px; }
            .search-form { flex-direction: column; }
            .filter-select { width: 100%; }
            .event-card-inner { flex-direction: column; gap: 10px; }
            .event-date { display: flex; align-items: center; gap: 12px; text-align: left; padding: 0; }
            .event-date .day { font-size: 22px; }
            .event-date .month { margin-top: 0; }
            .event-date .time { margin-top: 0; }
            .event-date .relative { margin-top: 0; }
            .event-meta { flex-direction: column; align-items: flex-start; gap: 5px; }
            .read-more-link { margin-left: 0; margin-top: 6px; }
            .map-container { height: 350px; }
            .view-toggle button span.label { display: none; }
            .live-indicator { display: none; }
        }

        /* Stats view: hide search, show full width stats */
        body.view-stats .filters-section { display: none; }
        body.view-stats .stats-sidebar { display: block !important; width: 100%; max-width: 100%; }
        body.view-stats .stats-sidebar .stats-card { display: inline-block; vertical-align: top; width: calc(25% - 12px); margin-right: 14px; margin-bottom: 14px; }
        body.view-stats .stats-sidebar .stats-card:nth-child(4n) { margin-right: 0; }
        body.view-stats .content-area { display: none; }
        body.view-stats .main-content { display: block; }

        @media (max-width: 1200px) {
            body.view-stats .stats-sidebar .stats-card { width: calc(50% - 8px); }
            body.view-stats .stats-sidebar .stats-card:nth-child(4n) { margin-right: 14px; }
            body.view-stats .stats-sidebar .stats-card:nth-child(2n) { margin-right: 0; }
        }

        @media (max-width: 768px) {
            body.view-stats .stats-sidebar .stats-card { width: 100%; margin-right: 0; }
        }

        @media print { body::before, .scroll-top, .refresh-btn, .search-bar, .stats-sidebar, .view-toggle, .theme-toggle, .install-prompt { display: none !important; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
    </style>
</head>
<body class="view-<?= htmlspecialchars($currentView) ?>">
    <div class="container">
        <header>
            <div class="header-content">
                <div class="logo">
                    <div class="logo-icon">🚔</div>
                    <div class="logo-text">
                        <h1>Sambandscentralen</h1>
                        <p>Svenska Polisens händelsenotiser</p>
                    </div>
                </div>
                
                <div class="header-controls">
                    <div class="live-indicator"><span class="live-dot"></span><span>Live</span></div>
                    
                    <div class="view-toggle">
                        <button type="button" data-view="list" class="<?= $currentView === 'list' ? 'active' : '' ?>">📋 <span class="label">Lista</span></button>
                        <button type="button" data-view="map" class="<?= $currentView === 'map' ? 'active' : '' ?>">🗺️ <span class="label">Karta</span></button>
                        <button type="button" data-view="stats" class="<?= $currentView === 'stats' ? 'active' : '' ?>">📊 <span class="label">Statistik</span></button>
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
                    <select name="location" class="filter-select" id="locationSelect">
                        <option value="">Alla platser</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= htmlspecialchars($loc) ?>" <?= $locationFilter === $loc ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                        <div class="empty-state"><div class="empty-state-icon">📭</div><h2>Inga händelser</h2><p>Ändra filter eller sök efter något annat.</p></div>
                    <?php else: ?>
                        <?php foreach ($initialEvents as $i => $event):
                            $date = formatDate($event['datetime'] ?? date('Y-m-d H:i:s'));
                            $type = $event['type'] ?? 'Okänd';
                            $color = getEventColor($type);
                            $icon = getEventIcon($type);
                            $location = $event['location']['name'] ?? 'Okänd';
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
                    <h3>📊 Översikt</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
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
        </div>

        <footer><p>Data från <a href="https://polisen.se" target="_blank" rel="noopener">Polisens öppna API</a> • Uppdateras var 5:e minut • <?= date('Y-m-d H:i') ?></p></footer>
    </div>

    <button class="scroll-top" id="scrollTop" aria-label="Till toppen">↑</button>
    <button class="refresh-btn" id="refreshBtn" aria-label="Uppdatera">🔄</button>

    <div class="install-prompt" id="installPrompt">
        <h4>📱 Installera Sambandscentralen</h4>
        <p>Lägg till på hemskärmen för snabb åtkomst.</p>
        <div class="install-prompt-buttons">
            <button class="install-btn" id="installBtn">Installera</button>
            <button class="dismiss-btn" id="dismissInstall">Inte nu</button>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
    const CONFIG = { perPage: <?= EVENTS_PER_PAGE ?>, filters: { location: '<?= addslashes($locationFilter) ?>', type: '<?= addslashes($typeFilter) ?>', search: '<?= addslashes($searchFilter) ?>' }, total: <?= $eventCount ?>, hasMore: <?= $hasMorePages ? 'true' : 'false' ?> };


    // Views
    const viewBtns = document.querySelectorAll('.view-toggle button');
    const eventsGrid = document.getElementById('eventsGrid');
    const mapContainer = document.getElementById('mapContainer');
    const statsSidebar = document.getElementById('statsSidebar');
    const viewInput = document.getElementById('viewInput');
    let map = null, mapInit = false;

    const setView = (v) => {
        viewBtns.forEach(b => b.classList.toggle('active', b.dataset.view === v));
        viewInput.value = v;
        document.body.className = 'view-' + v;
        eventsGrid.style.display = v === 'list' ? 'grid' : 'none';
        mapContainer.classList.toggle('active', v === 'map');
        statsSidebar.classList.toggle('active', v === 'stats');
        if (v === 'map' && !mapInit) initMap();
        history.replaceState(null, '', `?view=${v}${CONFIG.filters.location ? '&location=' + encodeURIComponent(CONFIG.filters.location) : ''}${CONFIG.filters.type ? '&type=' + encodeURIComponent(CONFIG.filters.type) : ''}${CONFIG.filters.search ? '&search=' + encodeURIComponent(CONFIG.filters.search) : ''}`);
    };
    viewBtns.forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));

    // Map
    const eventsData = <?= json_encode(is_array($events) && !isset($events['error']) ? array_map(fn($e) => ['name' => $e['name'] ?? '', 'summary' => $e['summary'] ?? '', 'type' => $e['type'] ?? '', 'url' => $e['url'] ?? '', 'location' => $e['location']['name'] ?? '', 'gps' => $e['location']['gps'] ?? null, 'icon' => getEventIcon($e['type'] ?? ''), 'color' => getEventColor($e['type'] ?? '')], $events) : []) ?>;

    function initMap() {
        if (mapInit) return; mapInit = true;
        map = L.map('map').setView([62.5, 17.5], 5);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '© OpenStreetMap', maxZoom: 18 }).addTo(map);
        
        const markers = L.layerGroup();
        eventsData.forEach(e => {
            if (e.gps) {
                const [lat, lng] = e.gps.split(',').map(Number);
                if (!isNaN(lat) && !isNaN(lng)) {
                    const m = L.circleMarker([lat, lng], { radius: 7, fillColor: e.color, color: '#fff', weight: 2, opacity: 1, fillOpacity: 0.8 });
                    m.bindPopup(`<div class="map-popup"><span class="badge" style="background:${e.color}20;color:${e.color}">${e.icon} ${e.type}</span><h3>${e.name}</h3><p>${e.summary.substring(0, 120)}${e.summary.length > 120 ? '...' : ''}</p><p><strong>📍 ${e.location}</strong></p>${e.url ? `<a href="https://polisen.se${e.url}" target="_blank" rel="noopener">Läs mer →</a>` : ''}</div>`);
                    markers.addLayer(m);
                }
            }
        });
        map.addLayer(markers);
        if (markers.getLayers().length) map.fitBounds(markers.getBounds(), { padding: [40, 40] });
    }

    // Infinite Scroll
    let page = 1, loading = false, hasMore = CONFIG.hasMore;
    const loadingEl = document.getElementById('loadingMore');

    async function loadMore() {
        if (loading || !hasMore) return;
        loading = true; loadingEl.style.display = 'flex'; page++;
        try {
            const res = await fetch(`?ajax=events&page=${page}&location=${encodeURIComponent(CONFIG.filters.location)}&type=${encodeURIComponent(CONFIG.filters.type)}&search=${encodeURIComponent(CONFIG.filters.search)}`);
            const data = await res.json();
            if (data.error) { console.error(data.error); return; }
            hasMore = data.hasMore;
            data.events.forEach((e, i) => {
                const card = document.createElement('article');
                card.className = 'event-card';
                card.style.animationDelay = `${i * 0.02}s`;
                card.innerHTML = `<div class="event-card-inner"><div class="event-date"><div class="day">${e.date.day}</div><div class="month">${e.date.month}</div><div class="time">${e.date.time}</div><div class="relative">${e.date.relative}</div></div><div class="event-content"><div class="event-header"><div class="event-title-group"><a href="?type=${encodeURIComponent(e.type)}&view=${viewInput.value}" class="event-type" style="background:${e.color}20;color:${e.color}">${e.icon} ${escHtml(e.type)}</a><a href="?location=${encodeURIComponent(e.location)}&view=${viewInput.value}" class="event-location-link">${escHtml(e.location)}</a></div></div><p class="event-summary">${escHtml(e.summary)}</p><div class="event-meta">${e.url ? `<button type="button" class="show-details-btn" data-url="${escHtml(e.url)}">📖 Visa detaljer</button><a href="https://polisen.se${escHtml(e.url)}" target="_blank" rel="noopener noreferrer" class="read-more-link"><span>🔗</span> polisen.se</a>` : ''}</div><div class="event-details"></div></div></div>`;
                eventsGrid.appendChild(card);
            });
        } catch (err) { console.error(err); } finally { loading = false; loadingEl.style.display = 'none'; }
    }

    function escHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

    new IntersectionObserver((e) => { if (e[0].isIntersecting && eventsGrid.style.display !== 'none') loadMore(); }, { rootMargin: '150px' }).observe(loadingEl);

    // Filter auto-submit
    document.querySelectorAll('.filter-select').forEach(s => s.addEventListener('change', () => s.form.submit()));

    // Scroll & Refresh
    const scrollTop = document.getElementById('scrollTop');
    window.addEventListener('scroll', () => scrollTop.classList.toggle('visible', window.scrollY > 300), { passive: true });
    scrollTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    document.getElementById('refreshBtn').addEventListener('click', function() { this.classList.add('loading'); location.reload(); });
    setInterval(() => { if (!document.hidden) location.reload(); }, 300000);

    // PWA Install
    let deferredPrompt;
    const installPrompt = document.getElementById('installPrompt');
    window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; if (!localStorage.getItem('installDismissed')) setTimeout(() => installPrompt.classList.add('show'), 20000); });
    document.getElementById('installBtn')?.addEventListener('click', async () => { if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; installPrompt.classList.remove('show'); });
    document.getElementById('dismissInstall')?.addEventListener('click', () => { installPrompt.classList.remove('show'); localStorage.setItem('installDismissed', 'true'); });
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});

    // Keyboard
    document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); document.getElementById('searchInput')?.focus(); } });

    // Event details expansion
    const detailsCache = {};
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.show-details-btn');
        if (!btn) return;

        const eventUrl = btn.dataset.url;
        const detailsDiv = btn.closest('.event-content').querySelector('.event-details');
        if (!eventUrl || !detailsDiv) return;

        // Toggle if already visible
        if (detailsDiv.classList.contains('visible')) {
            detailsDiv.classList.remove('visible');
            btn.classList.remove('expanded');
            btn.innerHTML = '📖 Visa detaljer';
            return;
        }

        // Check cache first
        if (detailsCache[eventUrl]) {
            detailsDiv.textContent = detailsCache[eventUrl];
            detailsDiv.classList.add('visible');
            detailsDiv.classList.remove('error');
            btn.classList.add('expanded');
            btn.innerHTML = '📖 Dölj detaljer';
            return;
        }

        // Fetch details
        btn.classList.add('loading');
        btn.innerHTML = '⏳ Laddar...';

        try {
            const res = await fetch(`?ajax=details&url=${encodeURIComponent(eventUrl)}`);
            const data = await res.json();

            if (data.success && data.details?.content) {
                detailsCache[eventUrl] = data.details.content;
                detailsDiv.textContent = data.details.content;
                detailsDiv.classList.add('visible');
                detailsDiv.classList.remove('error');
                btn.classList.add('expanded');
                btn.innerHTML = '📖 Dölj detaljer';
            } else {
                detailsDiv.textContent = 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.';
                detailsDiv.classList.add('visible', 'error');
                btn.innerHTML = '📖 Visa detaljer';
            }
        } catch (err) {
            console.error('Failed to fetch details:', err);
            detailsDiv.textContent = 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.';
            detailsDiv.classList.add('visible', 'error');
            btn.innerHTML = '📖 Visa detaljer';
        } finally {
            btn.classList.remove('loading');
        }
    });

    // Radio easter egg
    (function() {
        const logoIcon = document.querySelector('.logo-icon');
        let audio = null;
        logoIcon.style.cursor = 'pointer';
        logoIcon.title = '';
        logoIcon.addEventListener('click', function(e) {
            e.preventDefault();
            if (!audio) {
                audio = new Audio('radio.mp3');
                audio.loop = true;
                audio.volume = 0.5;
                audio.addEventListener('ended', () => logoIcon.classList.remove('radio-playing'));
            }
            if (audio.paused) {
                audio.play().then(() => logoIcon.classList.add('radio-playing')).catch(() => {});
            } else {
                audio.pause();
                logoIcon.classList.remove('radio-playing');
            }
        });
    })();

    // Init view
    if ('<?= $currentView ?>' !== 'list') setView('<?= $currentView ?>');
    </script>
</body>
</html>
