import { VMAAlert, VMAResponse } from '@/types';
import fs from 'fs';
import path from 'path';

const VMA_API_URL = 'https://vmaapi.sr.se/api/v3/alerts/feed.atom';
const VMA_API_TIMEOUT = 15000;
const USER_AGENT = 'FreshRSS/1.28.0 (Linux; https://freshrss.org)';
const DATA_DIR = path.join(process.cwd(), 'data');
const VMA_CACHE_FILE = path.join(DATA_DIR, 'vma_cache.json');
const VMA_CACHE_TTL = 300; // 5 minutes

// HTML entities map for decoding
const HTML_ENTITIES: Record<string, string> = {
  'aring': 'å', 'Aring': 'Å', 'auml': 'ä', 'Auml': 'Ä', 'ouml': 'ö', 'Ouml': 'Ö',
  'nbsp': ' ', 'amp': '&', 'lt': '<', 'gt': '>', 'quot': '"', 'apos': "'",
  'eacute': 'é', 'egrave': 'è', 'aacute': 'á', 'agrave': 'à', 'oacute': 'ó',
  'uacute': 'ú', 'iacute': 'í', 'ntilde': 'ñ', 'ccedil': 'ç', 'uuml': 'ü',
  'oslash': 'ø', 'Oslash': 'Ø', 'aelig': 'æ', 'AElig': 'Æ',
  'hellip': '…', 'mdash': '—', 'ndash': '–',
};

function decodeHtmlEntities(text: string): string {
  let decoded = text.replace(/&([a-zA-Z]+);/g, (match, entity) => {
    return HTML_ENTITIES[entity] || match;
  });
  decoded = decoded.replace(/&#x([0-9A-Fa-f]+);/g, (_, hex) =>
    String.fromCharCode(parseInt(hex, 16))
  );
  decoded = decoded.replace(/&#(\d+);/g, (_, dec) =>
    String.fromCharCode(parseInt(dec, 10))
  );
  return decoded;
}

interface VmaCache {
  timestamp: number;
  data: VMAResponse;
}

function ensureDataDir(): void {
  try {
    if (!fs.existsSync(DATA_DIR)) {
      fs.mkdirSync(DATA_DIR, { recursive: true });
    }
  } catch {
    // Ignore directory creation errors
  }
}

function getVmaCache(): VMAResponse | null {
  try {
    if (!fs.existsSync(VMA_CACHE_FILE)) {
      return null;
    }

    const cacheData = fs.readFileSync(VMA_CACHE_FILE, 'utf-8');
    const cache: VmaCache = JSON.parse(cacheData);

    if (Date.now() / 1000 - cache.timestamp > VMA_CACHE_TTL) {
      return null;
    }

    return cache.data;
  } catch {
    return null;
  }
}

function saveVmaCache(data: VMAResponse): void {
  try {
    ensureDataDir();
    const cache: VmaCache = {
      timestamp: Math.floor(Date.now() / 1000),
      data,
    };
    fs.writeFileSync(VMA_CACHE_FILE, JSON.stringify(cache), 'utf-8');
  } catch {
    // Ignore cache write errors
  }
}

function formatRelativeTime(date: Date, now: Date): string {
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

interface ParsedAlert {
  identifier: string;
  headline: string;
  description: string;
  instruction: string;
  severity: string;
  urgency: string;
  certainty: string;
  msgType: string;
  areas: string[];
  sent: string;
  expires: string | null;
  web: string;
}

function parseAtomEntry(entry: string): ParsedAlert | null {
  // Extract ID
  const idMatch = entry.match(/<id>([^<]*)<\/id>/);
  if (!idMatch) return null;

  const id = idMatch[1];

  // Extract title and decode entities
  const titleMatch = entry.match(/<title[^>]*>([^<]*)<\/title>/);
  const title = decodeHtmlEntities(titleMatch?.[1] || 'VMA');

  // Extract summary and decode entities
  const summaryMatch = entry.match(/<summary[^>]*>([^<]*)<\/summary>/);
  const summary = decodeHtmlEntities(summaryMatch?.[1] || '');

  // Extract content and decode entities
  const contentMatch = entry.match(/<content[^>]*>([\s\S]*?)<\/content>/);
  let content = contentMatch?.[1]?.replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1').trim() || '';
  // Remove HTML tags and decode entities
  content = decodeHtmlEntities(content.replace(/<[^>]+>/g, ''));

  // Extract published/updated
  const publishedMatch = entry.match(/<published>([^<]*)<\/published>/);
  const updatedMatch = entry.match(/<updated>([^<]*)<\/updated>/);
  const published = publishedMatch?.[1] || updatedMatch?.[1] || '';

  // Extract link
  const linkMatch = entry.match(/<link[^>]*rel="alternate"[^>]*href="([^"]*)"[^>]*\/?>|<link[^>]*href="([^"]*)"[^>]*rel="alternate"[^>]*\/?>/);
  const web = linkMatch?.[1] || linkMatch?.[2] || '';

  // Extract areas from title (format: "VMA: Area - Description")
  const areas: string[] = [];
  const areaMatch = title.match(/^VMA:\s*([^-–]+)\s*[-–]/u);
  if (areaMatch) {
    areas.push(areaMatch[1].trim());
  }

  return {
    identifier: id,
    headline: title,
    description: content || summary,
    instruction: '',
    severity: 'Unknown',
    urgency: 'Unknown',
    certainty: 'Unknown',
    msgType: 'Alert',
    areas,
    sent: published,
    expires: null,
    web,
  };
}

function formatVmaAlert(alert: ParsedAlert, now: Date): VMAAlert | null {
  if (!alert.identifier) {
    return null;
  }

  // Determine if alert is active
  let isActive = true;
  if (alert.expires) {
    try {
      const expiresDate = new Date(alert.expires);
      isActive = expiresDate > now;
    } catch {
      // Keep as active if date parsing fails
    }
  }

  // Parse sent time for display
  let sentDate: Date | null = null;
  let relativeTime = 'Okänd tid';
  if (alert.sent) {
    try {
      sentDate = new Date(alert.sent);
      relativeTime = formatRelativeTime(sentDate, now);
    } catch {
      // Keep default
    }
  }

  // Map severity to display values
  const severityMap: Record<string, { label: string; class: string }> = {
    'Extreme': { label: 'Extrem', class: 'vma-severity--extreme' },
    'Severe': { label: 'Allvarlig', class: 'vma-severity--severe' },
    'Moderate': { label: 'Måttlig', class: 'vma-severity--moderate' },
    'Minor': { label: 'Mindre', class: 'vma-severity--minor' },
    'Unknown': { label: 'Okänd', class: 'vma-severity--unknown' },
  };
  const severityInfo = severityMap[alert.severity] || severityMap['Unknown'];

  // Map message type
  const msgTypeMap: Record<string, string> = {
    'Alert': 'Varning',
    'Update': 'Uppdatering',
    'Cancel': 'Avslutad',
    'Ack': 'Bekräftelse',
    'Error': 'Fel',
  };
  const msgTypeLabel = msgTypeMap[alert.msgType] || alert.msgType;

  return {
    id: alert.identifier,
    headline: alert.headline,
    description: alert.description,
    instruction: alert.instruction,
    severity: alert.severity,
    severityLabel: severityInfo.label,
    severityClass: severityInfo.class,
    urgency: alert.urgency,
    certainty: alert.certainty,
    msgType: alert.msgType,
    msgTypeLabel,
    areas: alert.areas,
    areaText: alert.areas.length > 0 ? alert.areas.join(', ') : 'Hela Sverige',
    sentAt: alert.sent,
    sentDate: sentDate ? sentDate.toISOString().slice(0, 16).replace('T', ' ') : '',
    relativeTime,
    expiresAt: alert.expires,
    isActive,
    web: alert.web,
  };
}

export async function fetchVmaAlerts(forceRefresh = false): Promise<VMAResponse> {
  const result: VMAResponse = {
    success: false,
    current: [],
    recent: [],
    error: null,
  };

  // Check cache first
  if (!forceRefresh) {
    const cached = getVmaCache();
    if (cached) {
      return cached;
    }
  }

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), VMA_API_TIMEOUT);

    const response = await fetch(VMA_API_URL, {
      headers: {
        'User-Agent': USER_AGENT,
        'Accept': 'application/atom+xml, application/xml, text/xml',
      },
      signal: controller.signal,
    });

    clearTimeout(timeoutId);

    if (!response.ok) {
      result.error = `HTTP error ${response.status}`;
      return result;
    }

    const xml = await response.text();
    result.success = true;

    const now = new Date();

    // Parse ATOM entries
    const entryRegex = /<entry>([\s\S]*?)<\/entry>/g;
    let match;

    while ((match = entryRegex.exec(xml)) !== null) {
      const alertData = parseAtomEntry(match[1]);
      if (alertData) {
        const formatted = formatVmaAlert(alertData, now);
        if (formatted) {
          if (formatted.isActive) {
            result.current.push(formatted);
          } else {
            result.recent.push(formatted);
          }
        }
      }
    }

    // Sort recent alerts by sent time (newest first)
    result.recent.sort((a, b) => {
      const dateA = a.sentAt ? new Date(a.sentAt).getTime() : 0;
      const dateB = b.sentAt ? new Date(b.sentAt).getTime() : 0;
      return dateB - dateA;
    });

    // Limit recent alerts
    result.recent = result.recent.slice(0, 50);

    // Save to cache
    saveVmaCache(result);

    return result;
  } catch (error) {
    result.error = error instanceof Error ? error.message : 'Unknown error';
    return result;
  }
}
