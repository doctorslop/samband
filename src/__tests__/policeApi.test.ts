// Tests for policeApi module — focusing on pure functions and fetchDetailsText URL validation.
// We mock better-sqlite3 and the db module to avoid native module issues.

jest.mock('better-sqlite3', () => {
  return jest.fn(() => ({
    pragma: jest.fn(),
    exec: jest.fn(),
    prepare: jest.fn(() => ({
      run: jest.fn(),
      get: jest.fn(),
      all: jest.fn(() => []),
    })),
  }));
});

jest.mock('@/lib/db', () => ({
  insertEvent: jest.fn(() => 'new'),
  logFetch: jest.fn(),
  getLastFetchTime: jest.fn(() => null),
  countEventsInDb: jest.fn(() => 0),
  getDailyFetchCount: jest.fn(() => 0),
}));

import { decodeHtmlEntities, fetchDetailsText } from '@/lib/policeApi';

describe('decodeHtmlEntities (additional edge cases)', () => {
  it('handles empty string', () => {
    expect(decodeHtmlEntities('')).toBe('');
  });

  it('handles string with only entities', () => {
    expect(decodeHtmlEntities('&amp;&lt;&gt;')).toBe('&<>');
  });

  it('decodes Nordic characters (ø, æ, ß)', () => {
    expect(decodeHtmlEntities('&oslash;&aelig;&szlig;')).toBe('øæß');
  });

  it('decodes accented characters used in European names', () => {
    expect(decodeHtmlEntities('&eacute;&ntilde;&ccedil;')).toBe('éñç');
  });

  it('decodes special typographic entities', () => {
    expect(decodeHtmlEntities('&hellip;&mdash;&ndash;')).toBe('…—–');
    // ldquo and rdquo map to plain ASCII quotes in the entity table
    expect(decodeHtmlEntities('&ldquo;test&rdquo;')).toBe('"test"');
  });

  it('decodes copyright and trademark symbols', () => {
    expect(decodeHtmlEntities('&copy; &reg; &trade;')).toBe('© ® ™');
  });

  it('decodes currency symbols', () => {
    expect(decodeHtmlEntities('&euro;&pound;&yen;&cent;')).toBe('€£¥¢');
  });

  it('handles decimal entity for Swedish å (229)', () => {
    expect(decodeHtmlEntities('&#229;')).toBe('å');
  });

  it('handles multiple consecutive named entities', () => {
    expect(decodeHtmlEntities('&auml;&ouml;&aring;')).toBe('äöå');
  });

  it('preserves partial/broken entity-like strings', () => {
    expect(decodeHtmlEntities('& something;')).toBe('& something;');
    expect(decodeHtmlEntities('&;')).toBe('&;');
  });
});

describe('fetchDetailsText URL validation', () => {
  const originalFetch = global.fetch;

  beforeEach(() => {
    // Define global.fetch as a mock since jsdom doesn't provide it
    global.fetch = jest.fn();
  });

  afterEach(() => {
    global.fetch = originalFetch;
  });

  it('rejects non-polisen.se hostnames', async () => {
    const result = await fetchDetailsText('https://evil.com/steal');
    expect(result).toBeNull();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('rejects http:// protocol URLs with non-polisen host', async () => {
    const result = await fetchDetailsText('http://evil.com/page');
    expect(result).toBeNull();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('allows polisen.se subdomains', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      text: async () => '<article><p>Test content</p></article>',
    });

    const result = await fetchDetailsText('https://www.polisen.se/page');
    expect(result).toBe('Test content');
  });

  it('allows relative URLs (resolved against polisen.se)', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      text: async () => '<article><p>Event details here</p></article>',
    });

    const result = await fetchDetailsText('/aktuellt/handelser/2024/test');
    expect(result).toBe('Event details here');
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('polisen.se'),
      expect.any(Object)
    );
  });

  it('returns null when fetch response is not ok', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: false,
      status: 404,
    });

    const result = await fetchDetailsText('https://polisen.se/missing');
    expect(result).toBeNull();
  });

  it('returns null when no paragraphs found in HTML', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      text: async () => '<article><div>No paragraphs here</div></article>',
    });

    const result = await fetchDetailsText('https://polisen.se/event');
    expect(result).toBeNull();
  });

  it('extracts text from <main> tag when no <article> tag exists', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      text: async () => '<main><p>Main content</p></main>',
    });

    const result = await fetchDetailsText('https://polisen.se/event');
    expect(result).toBe('Main content');
  });

  it('strips HTML tags from paragraph content', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      text: async () => '<article><p>Text with <strong>bold</strong> and <a href="#">link</a></p></article>',
    });

    const result = await fetchDetailsText('https://polisen.se/event');
    expect(result).toBe('Text with bold and link');
  });

  it('decodes HTML entities in extracted text', async () => {
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      text: async () => '<article><p>Polisen i G&ouml;teborg</p></article>',
    });

    const result = await fetchDetailsText('https://polisen.se/event');
    expect(result).toBe('Polisen i Göteborg');
  });

  it('limits output to first 4 paragraphs', async () => {
    const html = '<article>' +
      '<p>One</p><p>Two</p><p>Three</p><p>Four</p><p>Five</p><p>Six</p>' +
      '</article>';
    (global.fetch as jest.Mock).mockResolvedValue({
      ok: true,
      text: async () => html,
    });

    const result = await fetchDetailsText('https://polisen.se/event');
    const paragraphs = result!.split('\n\n');
    expect(paragraphs).toHaveLength(4);
    expect(paragraphs).toEqual(['One', 'Two', 'Three', 'Four']);
  });

  it('returns null when fetch throws an error', async () => {
    (global.fetch as jest.Mock).mockRejectedValue(new Error('Network error'));

    const result = await fetchDetailsText('https://polisen.se/event');
    expect(result).toBeNull();
  });
});
