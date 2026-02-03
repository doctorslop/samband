import { RawEvent } from '@/types';
import { insertEvent, logFetch, getLastFetchTime } from './db';

const POLICE_API_URL = 'https://polisen.se/api/events';
const POLICE_API_TIMEOUT = 30000;
const USER_AGENT = 'FreshRSS/1.28.0 (Linux; https://freshrss.org)';
const CACHE_TIME = 1800; // 30 minutes in seconds
const MAX_FETCH_RETRIES = 3;

async function fetchWithRetry(url: string, retries = MAX_FETCH_RETRIES): Promise<RawEvent[]> {
  let lastError: Error | null = null;

  for (let attempt = 0; attempt < retries; attempt++) {
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), POLICE_API_TIMEOUT);

      const response = await fetch(url, {
        headers: {
          'User-Agent': USER_AGENT,
        },
        signal: controller.signal,
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        throw new Error(`HTTP error ${response.status}`);
      }

      const data = await response.json();
      if (!Array.isArray(data)) {
        throw new Error('Invalid JSON response');
      }

      return data as RawEvent[];
    } catch (error) {
      lastError = error as Error;
      if (attempt < retries - 1) {
        await new Promise(resolve => setTimeout(resolve, 200));
      }
    }
  }

  throw lastError || new Error('Failed to fetch after retries');
}

export interface RefreshResult {
  fetched: number;
  new: number;
  updated: number;
  success: boolean;
  error: string | null;
}

export async function refreshEventsIfNeeded(): Promise<RefreshResult> {
  const lastFetch = getLastFetchTime();
  const shouldFetch = !lastFetch || (Date.now() - lastFetch.getTime()) > CACHE_TIME * 1000;

  if (!shouldFetch) {
    return { fetched: 0, new: 0, updated: 0, success: true, error: null };
  }

  let eventsFetched = 0;
  let eventsNew = 0;
  let eventsUpdated = 0;

  try {
    const events = await fetchWithRetry(POLICE_API_URL);

    for (const event of events) {
      eventsFetched++;
      const status = insertEvent(event);
      if (status === 'new') {
        eventsNew++;
      } else if (status === 'updated') {
        eventsUpdated++;
      }
    }

    logFetch(eventsFetched, eventsNew, true);
    return { fetched: eventsFetched, new: eventsNew, updated: eventsUpdated, success: true, error: null };
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : 'Unknown error';
    logFetch(eventsFetched, eventsNew, false, errorMessage);
    return { fetched: eventsFetched, new: eventsNew, updated: eventsUpdated, success: false, error: errorMessage };
  }
}

// Map of HTML named entities to their character equivalents
// Includes Swedish characters and common entities
const HTML_ENTITIES: Record<string, string> = {
  // Swedish characters
  'aring': 'å', 'Aring': 'Å',
  'auml': 'ä', 'Auml': 'Ä',
  'ouml': 'ö', 'Ouml': 'Ö',
  // Common entities
  'nbsp': ' ', 'amp': '&', 'lt': '<', 'gt': '>', 'quot': '"', 'apos': "'",
  'copy': '©', 'reg': '®', 'trade': '™', 'euro': '€', 'pound': '£', 'yen': '¥',
  'cent': '¢', 'deg': '°', 'plusmn': '±', 'times': '×', 'divide': '÷',
  'frac12': '½', 'frac14': '¼', 'frac34': '¾',
  'hellip': '…', 'mdash': '—', 'ndash': '–', 'lsquo': "'", 'rsquo': "'",
  'ldquo': '"', 'rdquo': '"', 'bull': '•', 'middot': '·',
  // Other Nordic/European characters
  'eacute': 'é', 'Eacute': 'É', 'egrave': 'è', 'Egrave': 'È',
  'aacute': 'á', 'Aacute': 'Á', 'agrave': 'à', 'Agrave': 'À',
  'oacute': 'ó', 'Oacute': 'Ó', 'ograve': 'ò', 'Ograve': 'Ò',
  'uacute': 'ú', 'Uacute': 'Ú', 'ugrave': 'ù', 'Ugrave': 'Ù',
  'iacute': 'í', 'Iacute': 'Í', 'igrave': 'ì', 'Igrave': 'Ì',
  'ntilde': 'ñ', 'Ntilde': 'Ñ', 'ccedil': 'ç', 'Ccedil': 'Ç',
  'uuml': 'ü', 'Uuml': 'Ü', 'oslash': 'ø', 'Oslash': 'Ø',
  'aelig': 'æ', 'AElig': 'Æ', 'szlig': 'ß',
};

// Decode all HTML entities (named and numeric)
export function decodeHtmlEntities(text: string): string {
  // First decode named entities
  let decoded = text.replace(/&([a-zA-Z]+);/g, (match, entity) => {
    return HTML_ENTITIES[entity] || match;
  });

  // Decode numeric entities (&#xNN; hex and &#NNN; decimal)
  decoded = decoded.replace(/&#x([0-9A-Fa-f]+);/g, (_, hex) =>
    String.fromCharCode(parseInt(hex, 16))
  );
  decoded = decoded.replace(/&#(\d+);/g, (_, dec) =>
    String.fromCharCode(parseInt(dec, 10))
  );

  return decoded;
}

// Fetch event details from polisen.se
export async function fetchDetailsText(url: string): Promise<string | null> {
  // Validate and construct URL safely using URL constructor
  let absoluteUrl: string;
  try {
    // If url is relative, resolve against polisen.se base
    const baseUrl = 'https://polisen.se';
    const parsedUrl = new URL(url, baseUrl);

    // Security: Only allow https protocol and polisen.se hostname
    if (parsedUrl.protocol !== 'https:') {
      console.error('Invalid protocol in URL:', parsedUrl.protocol);
      return null;
    }
    if (parsedUrl.hostname !== 'polisen.se' && !parsedUrl.hostname.endsWith('.polisen.se')) {
      console.error('Invalid hostname in URL:', parsedUrl.hostname);
      return null;
    }

    absoluteUrl = parsedUrl.href;
  } catch {
    console.error('Invalid URL format:', url);
    return null;
  }

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), POLICE_API_TIMEOUT);

    const response = await fetch(absoluteUrl, {
      headers: {
        'User-Agent': USER_AGENT,
      },
      signal: controller.signal,
    });

    clearTimeout(timeoutId);

    if (!response.ok) {
      return null;
    }

    const html = await response.text();

    // Simple HTML parsing to extract article content
    // Look for content within <article> or <main> tags and extract paragraphs
    const articleMatch = html.match(/<article[^>]*>([\s\S]*?)<\/article>/i);
    const mainMatch = html.match(/<main[^>]*>([\s\S]*?)<\/main>/i);

    const content = articleMatch?.[1] || mainMatch?.[1] || '';

    // Extract text from paragraphs
    const paragraphs: string[] = [];
    const pRegex = /<p[^>]*>([\s\S]*?)<\/p>/gi;
    let match;

    while ((match = pRegex.exec(content)) !== null) {
      // Remove HTML tags from paragraph content
      let text = match[1].replace(/<[^>]+>/g, '');

      // Decode all HTML entities (named and numeric)
      text = decodeHtmlEntities(text);

      text = text.trim();

      if (text) {
        paragraphs.push(text);
      }
    }

    if (paragraphs.length === 0) {
      return null;
    }

    return paragraphs.slice(0, 4).join('\n\n');
  } catch {
    return null;
  }
}
