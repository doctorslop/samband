import { getTypeStyle } from '@/types';

describe('getTypeStyle', () => {
  it('returns explicit emoji mapping for updated crime types', () => {
    expect(getTypeStyle('Mord/dråp').icon).toBe('🔪');
    expect(getTypeStyle('Ofredande').icon).toBe('🙅');
    expect(getTypeStyle('Rattfylleri').icon).toBe('🍺');
  });

  it('returns a generic crime emoji for unknown crime categories', () => {
    expect(getTypeStyle('Ekobrott').icon).toBe('⚖️');
  });

  it('returns specific emoji for weapon-related events via partial match', () => {
    expect(getTypeStyle('Brott mot vapenlagen').icon).toBe('🔫');
  });
});
