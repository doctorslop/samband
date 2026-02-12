// Test HTML entity decoding by importing the real function from policeApi.
// We mock better-sqlite3 (and the db module) to avoid native module issues in tests.

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

import { decodeHtmlEntities } from '@/lib/policeApi';

describe('decodeHtmlEntities', () => {
  describe('Swedish character entities', () => {
    it('decodes ö (ouml)', () => {
      expect(decodeHtmlEntities('J&ouml;nk&ouml;ping')).toBe('Jönköping');
    });

    it('decodes ä (auml)', () => {
      expect(decodeHtmlEntities('G&auml;vle')).toBe('Gävle');
    });

    it('decodes å (aring)', () => {
      expect(decodeHtmlEntities('&aring;r')).toBe('år');
    });

    it('decodes uppercase Swedish chars', () => {
      expect(decodeHtmlEntities('&Aring;&Auml;&Ouml;')).toBe('ÅÄÖ');
    });
  });

  describe('common HTML entities', () => {
    it('decodes &amp;', () => {
      expect(decodeHtmlEntities('A &amp; B')).toBe('A & B');
    });

    it('decodes &lt; and &gt;', () => {
      expect(decodeHtmlEntities('&lt;tag&gt;')).toBe('<tag>');
    });

    it('decodes &quot;', () => {
      expect(decodeHtmlEntities('&quot;quoted&quot;')).toBe('"quoted"');
    });

    it('decodes &nbsp;', () => {
      expect(decodeHtmlEntities('hello&nbsp;world')).toBe('hello world');
    });
  });

  describe('numeric entities', () => {
    it('decodes decimal entities', () => {
      expect(decodeHtmlEntities('&#246;')).toBe('ö');
    });

    it('decodes hex entities', () => {
      expect(decodeHtmlEntities('&#xF6;')).toBe('ö');
    });

    it('decodes mixed case hex', () => {
      expect(decodeHtmlEntities('&#xf6;')).toBe('ö');
    });
  });

  describe('mixed content', () => {
    it('decodes real-world police text', () => {
      const input = 'centrala J&ouml;nk&ouml;ping. Bilen ska ha stulits fr&aring;n en parkering vid ett gym.';
      const expected = 'centrala Jönköping. Bilen ska ha stulits från en parkering vid ett gym.';
      expect(decodeHtmlEntities(input)).toBe(expected);
    });

    it('preserves unknown entities', () => {
      expect(decodeHtmlEntities('&unknown;')).toBe('&unknown;');
    });

    it('handles text without entities', () => {
      expect(decodeHtmlEntities('Hello World')).toBe('Hello World');
    });
  });
});
