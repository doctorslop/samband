import { formatRelativeTime, sanitizeInput, sanitizeLocation, sanitizeType, sanitizeSearch } from '@/lib/utils';

describe('formatRelativeTime', () => {
  const baseDate = new Date('2024-01-15T12:00:00Z');

  it('returns "Just nu" for less than 60 seconds', () => {
    const date = new Date(baseDate.getTime() - 30 * 1000);
    expect(formatRelativeTime(date, baseDate)).toBe('Just nu');
  });

  it('returns minutes for less than 60 minutes', () => {
    const date = new Date(baseDate.getTime() - 5 * 60 * 1000);
    expect(formatRelativeTime(date, baseDate)).toBe('5 min sedan');
  });

  it('returns hours for less than 24 hours', () => {
    const date = new Date(baseDate.getTime() - 3 * 60 * 60 * 1000);
    expect(formatRelativeTime(date, baseDate)).toBe('3 timmar sedan');
  });

  it('returns days for more than 24 hours', () => {
    const date = new Date(baseDate.getTime() - 2 * 24 * 60 * 60 * 1000);
    expect(formatRelativeTime(date, baseDate)).toBe('2 dagar sedan');
  });
});

describe('sanitizeInput', () => {
  it('removes null bytes and control characters', () => {
    const input = 'hello\x00world\x1F';
    expect(sanitizeInput(input)).toBe('helloworld');
  });

  it('normalizes whitespace', () => {
    const input = 'hello   world\t\ntest';
    expect(sanitizeInput(input)).toBe('hello world test');
  });

  it('trims and limits length', () => {
    const input = '  hello world  ';
    expect(sanitizeInput(input, 5)).toBe('hello');
  });
});

describe('sanitizeLocation', () => {
  it('allows Swedish characters', () => {
    const input = 'Jönköping';
    expect(sanitizeLocation(input)).toBe('Jönköping');
  });

  it('allows common punctuation', () => {
    const input = 'Stockholm, Södermalm';
    expect(sanitizeLocation(input)).toBe('Stockholm, Södermalm');
  });

  it('removes invalid characters', () => {
    const input = 'Göteborg <script>';
    expect(sanitizeLocation(input)).toBe('Göteborg script');
  });
});

describe('sanitizeType', () => {
  it('allows Swedish characters and slashes', () => {
    const input = 'Stöld/inbrott';
    expect(sanitizeType(input)).toBe('Stöld/inbrott');
  });

  it('removes invalid characters', () => {
    const input = 'Trafikolycka <dangerous>';
    expect(sanitizeType(input)).toBe('Trafikolycka dangerous');
  });
});

describe('sanitizeSearch', () => {
  it('sanitizes search input', () => {
    const input = 'sökning\x00test';
    expect(sanitizeSearch(input)).toBe('sökningtest');
  });

  it('limits to 200 characters', () => {
    const input = 'a'.repeat(300);
    expect(sanitizeSearch(input)).toHaveLength(200);
  });
});
