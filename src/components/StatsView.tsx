'use client';

import { memo, useMemo, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { Statistics } from '@/types';
import { generateStatsInsights } from '@/lib/statsInsights';

interface StatsViewProps {
  stats: Statistics;
  previousStats?: Statistics;
  isActive: boolean;
  onTypeClick?: (type: string) => void;
  onLocationClick?: (location: string) => void;
}

const PERIODS = [
  { key: 'live', label: 'Live' },
  { key: '24h', label: '24h' },
  { key: '7d', label: '7 dagar' },
  { key: '30d', label: '30 dagar' },
  { key: 'custom', label: 'Anpassad' },
];

function StatsView({ stats, previousStats, isActive, onTypeClick, onLocationClick }: StatsViewProps) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [showAllLocations, setShowAllLocations] = useState(false);
  const [showAllTypes, setShowAllTypes] = useState(false);

  const maxDaily = Math.max(...stats.daily.map((d) => d.count), 1);
  const maxWeekday = Math.max(...stats.weekdays, 1);
  const maxHourly = Math.max(...stats.hourly, 1);
  const weekdayNames = ['Mån', 'Tis', 'Ons', 'Tor', 'Fre', 'Lör', 'Sön'];

  const insights = useMemo(() => generateStatsInsights(stats, previousStats), [stats, previousStats]);

  const setPeriod = (period: string) => {
    const params = new URLSearchParams(searchParams.toString());
    params.set('view', 'stats');
    params.set('period', period);
    if (period !== 'custom') {
      params.delete('from');
      params.delete('to');
    }
    router.push(`/?${params.toString()}`, { scroll: false });
  };

  const topTypes = showAllTypes ? stats.topTypes : stats.topTypes.slice(0, 8);
  const topLocations = showAllLocations ? stats.topLocations : stats.topLocations.slice(0, 8);

  return (
    <section className={`stats-view${isActive ? ' active' : ''}`} aria-hidden={!isActive} role="region" aria-label="Lägesbild">
      <div className="stats-headline">
        <h2>Lägesbild</h2>
        <div className="period-filter" role="tablist" aria-label="Välj period">
          {PERIODS.map((period) => (
            <button key={period.key} type="button" className={stats.period === period.key ? 'active' : ''} onClick={() => setPeriod(period.key)}>
              {period.label}
            </button>
          ))}
        </div>
      </div>

      {stats.period === 'custom' && (
        <form className="stats-custom-period" onSubmit={(e) => {
          e.preventDefault();
          const formData = new FormData(e.currentTarget);
          const from = formData.get('from')?.toString() || '';
          const to = formData.get('to')?.toString() || '';
          const params = new URLSearchParams(searchParams.toString());
          params.set('view', 'stats');
          params.set('period', 'custom');
          if (from) params.set('from', from);
          if (to) params.set('to', to);
          router.push(`/?${params.toString()}`, { scroll: false });
        }}>
          <input type="date" name="from" defaultValue={searchParams.get('from') || ''} />
          <input type="date" name="to" defaultValue={searchParams.get('to') || ''} />
          <button type="submit">Uppdatera</button>
        </form>
      )}

      <div className="stats-hero-grid stats-hero-grid--overview">
        <div className="stats-metric stats-metric--primary stats-metric--span2">
          <span className="stats-metric__value">{stats.total.toLocaleString('sv-SE')}</span>
          <span className="stats-metric__label">Totala händelser</span>
        </div>
        <div className="stats-metric"><span className="stats-metric__value">{stats.last24h}</span><span className="stats-metric__label">Senaste 24h</span></div>
        <div className="stats-metric"><span className="stats-metric__value">{stats.avgPerDay}</span><span className="stats-metric__label">Genomsnitt per dag</span></div>
        <div className="stats-metric"><span className="stats-metric__value">{stats.uniqueLocations}</span><span className="stats-metric__label">Unika platser</span></div>
        <div className="stats-metric"><span className="stats-metric__value">{stats.uniqueTypes}</span><span className="stats-metric__label">Händelsetyper</span></div>
      </div>

      <div className="stats-quality">
        <span>Datakvalitet</span>
        <span className="stats-quality__badge">GPS-position: {stats.gpsPercent}%</span>
        <span className="stats-quality__badge">Uppdaterade: {stats.updatedPercent}%</span>
      </div>

      <div className="stats-section">
        <h3 className="stats-section__title">Händelser över tid</h3>
        <div className="stats-card stats-card--chart stats-card--hero">
          <div className="trend-chart">
            {stats.daily.map((day, i) => {
              const pct = (day.count / maxDaily) * 100;
              return (
                <div key={i} className="trend-chart__col" title={`${day.day}: ${day.count} händelser`}>
                  <div className="trend-chart__bar-container"><div className="trend-chart__bar" style={{ height: `${pct}%` }} /></div>
                  <span className="trend-chart__value">{day.count}</span>
                  <span className="trend-chart__label">{day.day}</span>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      <div className="stats-section">
        <h3 className="stats-section__title">Händelsemönster</h3>
        <div className="stats-grid stats-grid--2col">
          <div className="stats-card">
            <h4 className="stats-card__title">Per veckodag</h4>
            <div className="bar-chart bar-chart--weekday">
              {stats.weekdays.map((count, i) => <div key={i} className="bar-chart__col"><div className="bar-chart__bar-container"><div className="bar-chart__bar" style={{ height: `${(count / maxWeekday) * 100}%` }} /></div><span className="bar-chart__label">{weekdayNames[i]}</span></div>)}
            </div>
          </div>
          <div className="stats-card">
            <h4 className="stats-card__title">Per timme</h4>
            <div className="bar-chart bar-chart--hourly">
              {stats.hourly.map((count, hour) => <div key={hour} className="bar-chart__col bar-chart__col--hour" title={`${String(hour).padStart(2, '0')}:00 - ${count}`}><div className="bar-chart__bar-container"><div className="bar-chart__bar" style={{ height: `${(count / maxHourly) * 100}%` }} /></div></div>)}
            </div>
          </div>
        </div>
      </div>

      <div className="stats-section">
        <h3 className="stats-section__title">Topplistor</h3>
        <div className="stats-grid stats-grid--2col">
          <div className="stats-card">
            <h4 className="stats-card__title">Län</h4>
            <ul className="top-list">
              {topLocations.map((row, i) => <li key={row.label} className={`top-list__item${onLocationClick ? ' top-list__item--clickable' : ''}`} onClick={() => onLocationClick?.(row.label)}><span className="top-list__rank">{i + 1}</span><span className="top-list__name">{row.label}</span><span className="top-list__count">{row.total}</span></li>)}
            </ul>
            {stats.topLocations.length > 8 && <button type="button" className="stats-show-more" onClick={() => setShowAllLocations((v) => !v)}>{showAllLocations ? 'Visa färre' : 'Visa fler'}</button>}
          </div>
          <div className="stats-card">
            <h4 className="stats-card__title">Händelsetyper</h4>
            <ul className="top-list">
              {topTypes.map((row, i) => <li key={row.label} className={`top-list__item${onTypeClick ? ' top-list__item--clickable' : ''}`} onClick={() => onTypeClick?.(row.label)}><span className="top-list__rank">{i + 1}</span><span className="top-list__name">{row.label}</span><span className="top-list__count">{row.total}</span></li>)}
            </ul>
            {stats.topTypes.length > 8 && <button type="button" className="stats-show-more" onClick={() => setShowAllTypes((v) => !v)}>{showAllTypes ? 'Visa färre' : 'Visa fler'}</button>}
          </div>
        </div>
      </div>

      <div className="stats-section">
        <h3 className="stats-section__title">Insikter</h3>
        <div className="stats-card">
          <ul className="stats-insights">
            {insights.map((line) => <li key={line}>{line}</li>)}
          </ul>
        </div>
      </div>
    </section>
  );
}

export default memo(StatsView);
