import { generateStatsInsights } from '@/lib/statsInsights';
import { Statistics } from '@/types';

const baseStats: Statistics = {
  period: '24h',
  total: 100,
  totalStored: 100,
  last24h: 100,
  last7d: 100,
  last30d: 100,
  avgPerDay: 100,
  topTypes: [{ label: 'Brand', total: 25 }],
  topLocations: [{ label: 'Stockholms län', total: 40 }],
  hourly: Array.from({ length: 24 }, (_, i) => (i === 14 ? 20 : 1)),
  weekdays: Array(7).fill(10),
  daily: [],
  gpsPercent: 90,
  updatedPercent: 5,
  uniqueLocations: 10,
  uniqueTypes: 4,
};

test('generates core insights', () => {
  const insights = generateStatsInsights(baseStats);
  expect(insights).toHaveLength(3);
  expect(insights[0]).toContain('14–15');
  expect(insights[1]).toContain('brand');
  expect(insights[2]).toContain('Stockholms län');
});
