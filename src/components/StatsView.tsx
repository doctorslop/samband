'use client';

import { memo } from 'react';
import { Statistics } from '@/types';

interface StatsViewProps {
  stats: Statistics;
  isActive: boolean;
  onTypeClick?: (type: string) => void;
  onLocationClick?: (location: string) => void;
}

function StatsView({ stats, isActive, onTypeClick, onLocationClick }: StatsViewProps) {
  const maxDaily = Math.max(...stats.daily.map(d => d.count), 1);
  const maxWeekday = Math.max(...stats.weekdays, 1);
  const maxHourly = Math.max(...stats.hourly, 1);
  const weekdayNames = ['Mån', 'Tis', 'Ons', 'Tor', 'Fre', 'Lör', 'Sön'];

  return (
    <section
      className={`stats-view${isActive ? ' active' : ''}`}
      aria-hidden={!isActive}
      role="region"
      aria-label="Statistik"
    >
      {/* Hero metrics */}
      <div className="stats-hero">
        <div className="stats-hero-grid">
          <div className="stats-metric stats-metric--primary">
            <span className="stats-metric__value">{stats.total.toLocaleString('sv-SE')}</span>
            <span className="stats-metric__label">Totalt antal händelser</span>
          </div>
          <div className="stats-metric">
            <span className="stats-metric__value">{stats.last24h}</span>
            <span className="stats-metric__label">Senaste 24h</span>
          </div>
          <div className="stats-metric">
            <span className="stats-metric__value">{stats.last7d}</span>
            <span className="stats-metric__label">Senaste 7 dagar</span>
          </div>
          <div className="stats-metric">
            <span className="stats-metric__value">{stats.last30d}</span>
            <span className="stats-metric__label">Senaste 30 dagar</span>
          </div>
          <div className="stats-metric stats-metric--highlight">
            <span className="stats-metric__value">~{stats.avgPerDay}</span>
            <span className="stats-metric__label">Genomsnitt/dag</span>
          </div>
          <div className="stats-metric">
            <span className="stats-metric__value">{stats.uniqueLocations}</span>
            <span className="stats-metric__label">Unika platser</span>
          </div>
          <div className="stats-metric">
            <span className="stats-metric__value">{stats.uniqueTypes}</span>
            <span className="stats-metric__label">Händelsetyper</span>
          </div>
          <div className="stats-metric">
            <span className="stats-metric__value">{stats.gpsPercent}%</span>
            <span className="stats-metric__label">Med GPS-position</span>
          </div>
          <div className="stats-metric">
            <span className="stats-metric__value">{stats.updatedPercent}%</span>
            <span className="stats-metric__label">Uppdaterade</span>
          </div>
        </div>
      </div>

      {/* Trend section */}
      <div className="stats-section">
        <h2 className="stats-section__title">Senaste 7 dagarna</h2>
        <div className="stats-card stats-card--chart">
          <div className="trend-chart">
            {stats.daily.map((day, i) => {
              const pct = (day.count / maxDaily) * 100;
              return (
                <div key={i} className="trend-chart__col">
                  <div className="trend-chart__bar-container">
                    <div
                      className="trend-chart__bar"
                      style={{ height: `${pct}%` }}
                      title={`${day.date}: ${day.count} händelser`}
                    />
                  </div>
                  <span className="trend-chart__value">{day.count}</span>
                  <span className="trend-chart__label">{day.day.substring(0, 3)}</span>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Distribution section */}
      <div className="stats-section">
        <h2 className="stats-section__title">Fördelning</h2>
        <div className="stats-grid stats-grid--2col">
          {/* Weekday distribution */}
          <div className="stats-card">
            <h3 className="stats-card__title">Per veckodag</h3>
            <div className="bar-chart bar-chart--weekday">
              {stats.weekdays.map((count, i) => {
                const pct = (count / maxWeekday) * 100;
                return (
                  <div key={i} className="bar-chart__col">
                    <div className="bar-chart__bar-container">
                      <div
                        className="bar-chart__bar"
                        style={{ height: `${pct}%` }}
                        title={`${weekdayNames[i]}: ${count} händelser`}
                      />
                    </div>
                    <span className="bar-chart__label">{weekdayNames[i]}</span>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Hourly distribution */}
          <div className="stats-card">
            <h3 className="stats-card__title">Per timme</h3>
            <div className="bar-chart bar-chart--hourly">
              {stats.hourly.map((count, hour) => {
                const pct = (count / maxHourly) * 100;
                return (
                  <div
                    key={hour}
                    className="bar-chart__col bar-chart__col--hour"
                    title={`${String(hour).padStart(2, '0')}:00 - ${count} händelser`}
                  >
                    <div className="bar-chart__bar-container">
                      <div
                        className="bar-chart__bar"
                        style={{ height: `${pct}%` }}
                      />
                    </div>
                  </div>
                );
              })}
            </div>
            <div className="bar-chart__axis">
              <span>00</span>
              <span>06</span>
              <span>12</span>
              <span>18</span>
              <span>23</span>
            </div>
          </div>
        </div>
      </div>

      {/* Top lists section */}
      <div className="stats-section">
        <h2 className="stats-section__title">Vanligast förekommande</h2>
        <div className="stats-grid stats-grid--2col">
          {/* Top event types */}
          <div className="stats-card">
            <h3 className="stats-card__title">Händelsetyper</h3>
            <ul className="top-list">
              {stats.topTypes.map((row, i) => {
                const pct = stats.total > 0 ? Math.round((row.total / stats.total) * 100) : 0;
                return (
                  <li
                    key={i}
                    className={`top-list__item${onTypeClick ? ' top-list__item--clickable' : ''}`}
                    onClick={() => onTypeClick?.(row.label)}
                    role={onTypeClick ? 'button' : undefined}
                    tabIndex={onTypeClick ? 0 : undefined}
                    onKeyDown={(e) => {
                      if (onTypeClick && (e.key === 'Enter' || e.key === ' ')) {
                        e.preventDefault();
                        onTypeClick(row.label);
                      }
                    }}
                  >
                    <span className="top-list__rank">{i + 1}</span>
                    <span className="top-list__name">{row.label}</span>
                    <div className="top-list__bar-container">
                      <div className="top-list__bar" style={{ width: `${pct}%` }} />
                    </div>
                    <span className="top-list__count">{row.total.toLocaleString('sv-SE')}</span>
                  </li>
                );
              })}
            </ul>
          </div>

          {/* Top locations */}
          <div className="stats-card">
            <h3 className="stats-card__title">Platser</h3>
            <ul className="top-list">
              {stats.topLocations.map((row, i) => {
                const pct = stats.total > 0 ? Math.round((row.total / stats.total) * 100) : 0;
                return (
                  <li
                    key={i}
                    className={`top-list__item${onLocationClick ? ' top-list__item--clickable' : ''}`}
                    onClick={() => onLocationClick?.(row.label)}
                    role={onLocationClick ? 'button' : undefined}
                    tabIndex={onLocationClick ? 0 : undefined}
                    onKeyDown={(e) => {
                      if (onLocationClick && (e.key === 'Enter' || e.key === ' ')) {
                        e.preventDefault();
                        onLocationClick(row.label);
                      }
                    }}
                  >
                    <span className="top-list__rank">{i + 1}</span>
                    <span className="top-list__name">{row.label}</span>
                    <div className="top-list__bar-container">
                      <div className="top-list__bar" style={{ width: `${pct}%` }} />
                    </div>
                    <span className="top-list__count">{row.total.toLocaleString('sv-SE')}</span>
                  </li>
                );
              })}
            </ul>
          </div>
        </div>
      </div>
    </section>
  );
}

export default memo(StatsView);
