'use client';

import { Statistics } from '@/types';

interface StatsViewProps {
  stats: Statistics;
  isActive: boolean;
}

export default function StatsView({ stats, isActive }: StatsViewProps) {
  const maxDaily = Math.max(...stats.daily.map(d => d.count)) || 1;
  const maxWeekday = Math.max(...stats.weekdays) || 1;
  const maxHourly = Math.max(...stats.hourly) || 1;
  const weekdayNames = ['M', 'Ti', 'O', 'To', 'F', 'L', 'S'];

  return (
    <aside id="statsSidebar" className={`stats-sidebar${isActive ? ' active' : ''}`}>
      <div className="stats-grid">
        {/* Key metrics */}
        <div className="stats-metrics">
          <div className="metric">
            <span className="metric-value">{stats.total}</span>
            <span className="metric-label">Totalt</span>
          </div>
          <div className="metric">
            <span className="metric-value">{stats.last24h}</span>
            <span className="metric-label">24h</span>
          </div>
          <div className="metric">
            <span className="metric-value">{stats.last7d}</span>
            <span className="metric-label">7 dagar</span>
          </div>
          <div className="metric">
            <span className="metric-value">{stats.last30d}</span>
            <span className="metric-label">30 dagar</span>
          </div>
          <div className="metric metric-avg">
            <span className="metric-value">~{stats.avgPerDay}</span>
            <span className="metric-label">per dag</span>
          </div>
        </div>

        {/* Trend last 7 days */}
        <div className="stats-card">
          <h3>Senaste 7 dagarna</h3>
          <div className="trend-chart">
            {stats.daily.map((day, i) => {
              const pct = (day.count / maxDaily) * 100;
              return (
                <div key={i} className="trend-col">
                  <div className="trend-bar-wrap">
                    <div
                      className="trend-bar"
                      style={{ height: `${pct}%` }}
                      title={day.date}
                    />
                  </div>
                  <span className="trend-val">{day.count}</span>
                  <span className="trend-day">{day.day.substring(0, 2)}</span>
                </div>
              );
            })}
          </div>
        </div>

        {/* Row with two charts */}
        <div className="stats-row">
          <div className="stats-card">
            <h3>Per veckodag</h3>
            <div className="bar-chart bar-chart-weekday">
              {stats.weekdays.map((count, i) => {
                const pct = (count / maxWeekday) * 100;
                return (
                  <div key={i} className="bar-col">
                    <div className="bar-wrap">
                      <div className="bar" style={{ height: `${pct}%` }} />
                    </div>
                    <span className="bar-label">{weekdayNames[i]}</span>
                  </div>
                );
              })}
            </div>
          </div>
          <div className="stats-card">
            <h3>Per timme</h3>
            <div className="bar-chart bar-chart-hourly">
              {stats.hourly.map((count, hour) => {
                const pct = (count / maxHourly) * 100;
                return (
                  <div
                    key={hour}
                    className="bar-col bar-col-hour"
                    title={`${String(hour).padStart(2, '0')}:00: ${count}`}
                  >
                    <div className="bar-wrap">
                      <div className="bar" style={{ height: `${pct}%` }} />
                    </div>
                  </div>
                );
              })}
            </div>
            <div className="hour-axis">
              <span>00</span>
              <span>06</span>
              <span>12</span>
              <span>18</span>
              <span>23</span>
            </div>
          </div>
        </div>

        {/* Top lists row */}
        <div className="stats-row">
          <div className="stats-card">
            <h3>Vanligaste händelser</h3>
            <div className="top-list">
              {stats.topTypes.map((row, i) => {
                const pct = stats.total > 0 ? Math.round((row.total / stats.total) * 100) : 0;
                return (
                  <div key={i} className="top-item">
                    <span className="top-name">{row.label}</span>
                    <div className="top-bar-wrap">
                      <div className="top-bar" style={{ width: `${pct}%` }} />
                    </div>
                    <span className="top-count">{row.total}</span>
                  </div>
                );
              })}
            </div>
          </div>
          <div className="stats-card">
            <h3>Vanligaste platser</h3>
            <div className="top-list">
              {stats.topLocations.map((row, i) => {
                const pct = stats.total > 0 ? Math.round((row.total / stats.total) * 100) : 0;
                return (
                  <div key={i} className="top-item">
                    <span className="top-name">{row.label}</span>
                    <div className="top-bar-wrap">
                      <div className="top-bar" style={{ width: `${pct}%` }} />
                    </div>
                    <span className="top-count">{row.total}</span>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </div>
    </aside>
  );
}
