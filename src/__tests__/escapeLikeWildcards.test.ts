import { escapeLikeWildcards } from '@/lib/utils';

describe('escapeLikeWildcards', () => {
  it('escapes percent signs', () => {
    expect(escapeLikeWildcards('100%')).toBe('100\\%');
  });

  it('escapes underscores', () => {
    expect(escapeLikeWildcards('user_name')).toBe('user\\_name');
  });

  it('escapes backslashes', () => {
    expect(escapeLikeWildcards('path\\to\\file')).toBe('path\\\\to\\\\file');
  });

  it('escapes all wildcards in combination', () => {
    expect(escapeLikeWildcards('50% of_users\\')).toBe('50\\% of\\_users\\\\');
  });

  it('returns unchanged string with no wildcards', () => {
    expect(escapeLikeWildcards('Stockholm')).toBe('Stockholm');
  });

  it('handles empty string', () => {
    expect(escapeLikeWildcards('')).toBe('');
  });

  it('handles string of only wildcards', () => {
    expect(escapeLikeWildcards('%_%')).toBe('\\%\\_\\%');
  });
});
