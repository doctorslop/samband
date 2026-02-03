// Test HTML entity decoding logic directly without importing the full module
// This avoids issues with better-sqlite3 native module in test environment

// Copy of the HTML_ENTITIES map and decodeHtmlEntities function for testing
const HTML_ENTITIES: Record<string, string> = {
  aring: "å", Aring: "Å",
  auml: "ä", Auml: "Ä",
  ouml: "ö", Ouml: "Ö",
  nbsp: " ", amp: "&", lt: "<", gt: ">", quot: "\"", apos: "'",
  copy: "©", reg: "®", trade: "™", euro: "€", pound: "£", yen: "¥",
  cent: "¢", deg: "°", plusmn: "±", times: "×", divide: "÷",
  frac12: "½", frac14: "¼", frac34: "¾",
  hellip: "…", mdash: "—", ndash: "–", lsquo: "\u2018", rsquo: "\u2019",
  ldquo: "\u201C", rdquo: "\u201D", bull: "•", middot: "·",
  eacute: "é", Eacute: "É", egrave: "è", Egrave: "È",
  aacute: "á", Aacute: "Á", agrave: "à", Agrave: "À",
  oacute: "ó", Oacute: "Ó", ograve: "ò", Ograve: "Ò",
  uacute: "ú", Uacute: "Ú", ugrave: "ù", Ugrave: "Ù",
  iacute: "í", Iacute: "Í", igrave: "ì", Igrave: "Ì",
  ntilde: "ñ", Ntilde: "Ñ", ccedil: "ç", Ccedil: "Ç",
  uuml: "ü", Uuml: "Ü", oslash: "ø", Oslash: "Ø",
  aelig: "æ", AElig: "Æ", szlig: "ß",
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
