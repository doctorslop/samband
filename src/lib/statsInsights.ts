import { Statistics } from '@/types';

export function generateStatsInsights(stats: Statistics, previousStats?: Statistics): string[] {
  const insights: string[] = [];

  const topHourCount = Math.max(...stats.hourly, 0);
  const topHour = stats.hourly.findIndex((count) => count === topHourCount);
  if (topHourCount > 0 && topHour >= 0) {
    const nextHour = (topHour + 1) % 24;
    insights.push(`Flest händelser sker mellan ${String(topHour).padStart(2, '0')}–${String(nextHour).padStart(2, '0')}`);
  }

  if (stats.topTypes[0]) {
    insights.push(`Vanligast: ${stats.topTypes[0].label.toLowerCase()} (${stats.topTypes[0].total})`);
  }

  if (stats.topLocations[0]) {
    insights.push(`Mest aktivt län: ${stats.topLocations[0].label} (${stats.topLocations[0].total})`);
  }

  if (previousStats && previousStats.total > 0) {
    const diff = ((stats.total - previousStats.total) / previousStats.total) * 100;
    const sign = diff > 0 ? '+' : '';
    insights.push(`Förändring vs föregående period: ${sign}${Math.round(diff)}%`);
  }

  return insights.slice(0, 4);
}
