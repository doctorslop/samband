'use client';

import { useState, useEffect } from 'react';
import { OperationalStats, FetchLogEntry, DatabaseHealth, Statistics } from '@/types';

interface OperationalDashboardProps {
  operationalStats: OperationalStats;
  fetchLogs: FetchLogEntry[];
  databaseHealth: DatabaseHealth;
  eventStats: Statistics;
}

function formatTimeAgo(dateString: string | null): string {
  if (!dateString) return 'Aldrig';
  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMins / 60);
  const diffDays = Math.floor(diffHours / 24);

  if (diffMins < 1) return 'Just nu';
  if (diffMins < 60) return `${diffMins} min sedan`;
  if (diffHours < 24) return `${diffHours} tim sedan`;
  if (diffDays < 7) return `${diffDays} dagar sedan`;
  return date.toLocaleDateString('sv-SE');
}

function formatDateTime(dateString: string): string {
  const date = new Date(dateString);
  return date.toLocaleString('sv-SE', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function StatusIndicator({ status }: { status: 'healthy' | 'warning' | 'error' }) {
  const colors = {
    healthy: '#10b981',
    warning: '#f59e0b',
    error: '#ef4444',
  };
  return (
    <span
      className="ops-status-dot"
      style={{ backgroundColor: colors[status] }}
      title={status}
    />
  );
}

export default function OperationalDashboard({
  operationalStats,
  fetchLogs,
  databaseHealth,
  eventStats,
}: OperationalDashboardProps) {
  const getSystemStatus = (): 'healthy' | 'warning' | 'error' => {
    if (operationalStats.successRate < 80 || operationalStats.uptimeScore < 50) return 'error';
    if (operationalStats.successRate < 95 || operationalStats.uptimeScore < 80) return 'warning';
    return 'healthy';
  };

  const getFreshnessStatus = (): 'healthy' | 'warning' | 'error' => {
    if (databaseHealth.dataFreshnessMinutes > 120) return 'error';
    if (databaseHealth.dataFreshnessMinutes > 60) return 'warning';
    return 'healthy';
  };

  const systemStatus = getSystemStatus();
  const freshnessStatus = getFreshnessStatus();

  const [updatedAt, setUpdatedAt] = useState<string>('');
  useEffect(() => {
    setUpdatedAt(new Date().toLocaleString('sv-SE'));
  }, []);

  const maxHourlyFetches = Math.max(...operationalStats.hourlyFetches, 1);
  const maxDailyEventCount = Math.max(...eventStats.daily.map(d => d.count), 1);
  const maxHourlyEventCount = Math.max(...eventStats.hourly, 1);

  return (
    <div className="ops-container">
      <header className="ops-header">
        <div className="ops-header-content">
          <div className="ops-logo">
            <span className="ops-logo-icon">
              <StatusIndicator status={systemStatus} />
            </span>
            <div className="ops-logo-text">
              <h1>Systemstatus</h1>
              <p>Driftöversikt</p>
            </div>
          </div>
          <div className="ops-header-meta">
            <span className="ops-timestamp">
              {updatedAt && `Uppdaterad: ${updatedAt}`}
            </span>
          </div>
        </div>
      </header>

      <main className="ops-main">
        {/* Systemhälsa */}
        <section className="ops-section">
          <h2 className="ops-section-title">Systemhälsa</h2>
          <div className="ops-metrics-grid">
            <div className={`ops-metric ops-metric--large ops-metric--${systemStatus}`}>
              <span className="ops-metric-value">{operationalStats.uptimeScore}%</span>
              <span className="ops-metric-label">Drifttid (24h)</span>
            </div>
            <div className={`ops-metric ops-metric--large ops-metric--${operationalStats.successRate >= 95 ? 'healthy' : operationalStats.successRate >= 80 ? 'warning' : 'error'}`}>
              <span className="ops-metric-value">{operationalStats.successRate}%</span>
              <span className="ops-metric-label">Lyckade hämtningar</span>
            </div>
            <div className={`ops-metric ops-metric--large ops-metric--${freshnessStatus}`}>
              <span className="ops-metric-value">{databaseHealth.dataFreshnessMinutes}m</span>
              <span className="ops-metric-label">Datafärskhet</span>
            </div>
          </div>
        </section>

        {/* Hämtningsstatistik */}
        <section className="ops-section">
          <h2 className="ops-section-title">Hämtningar</h2>
          <div className="ops-metrics-grid ops-metrics-grid--4">
            <div className="ops-metric">
              <span className="ops-metric-value">{operationalStats.totalFetches.toLocaleString()}</span>
              <span className="ops-metric-label">Totalt</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value ops-metric-value--success">{operationalStats.successfulFetches.toLocaleString()}</span>
              <span className="ops-metric-label">Lyckade</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value ops-metric-value--danger">{operationalStats.failedFetches}</span>
              <span className="ops-metric-label">Misslyckade</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value">{operationalStats.avgFetchInterval}m</span>
              <span className="ops-metric-label">Snittintervall</span>
            </div>
          </div>

          <div className="ops-grid ops-grid--2">
            <div className="ops-card">
              <h3 className="ops-card-title">Hämtningar (24h)</h3>
              <div className="ops-bar-chart">
                {operationalStats.hourlyFetches.map((count, hour) => {
                  const height = (count / maxHourlyFetches) * 100;
                  return (
                    <div key={hour} className="ops-bar-col">
                      <div className="ops-bar-container">
                        <div
                          className="ops-bar"
                          style={{ height: `${height}%` }}
                          title={`${hour}:00 - ${count} hämtningar`}
                        />
                      </div>
                      {hour % 6 === 0 && <span className="ops-bar-label">{hour}</span>}
                    </div>
                  );
                })}
              </div>
            </div>

            <div className="ops-card">
              <h3 className="ops-card-title">Senaste aktivitet</h3>
              <div className="ops-info-grid">
                <div className="ops-info-row">
                  <span className="ops-info-label">Senast lyckad</span>
                  <span className="ops-info-value ops-info-value--success">
                    {formatTimeAgo(operationalStats.lastSuccessfulFetch)}
                  </span>
                </div>
                <div className="ops-info-row">
                  <span className="ops-info-label">Senast misslyckad</span>
                  <span className="ops-info-value ops-info-value--muted">
                    {formatTimeAgo(operationalStats.lastFailedFetch)}
                  </span>
                </div>
                <div className="ops-info-row">
                  <span className="ops-info-label">Hämtningar idag</span>
                  <span className="ops-info-value">{operationalStats.fetches24h}</span>
                </div>
                <div className="ops-info-row">
                  <span className="ops-info-label">Hämtningar (7d)</span>
                  <span className="ops-info-value">{operationalStats.fetches7d}</span>
                </div>
                <div className="ops-info-row">
                  <span className="ops-info-label">Snitt händelser/hämtning</span>
                  <span className="ops-info-value">{operationalStats.avgEventsPerFetch}</span>
                </div>
                <div className="ops-info-row">
                  <span className="ops-info-label">Nya händelser idag</span>
                  <span className="ops-info-value">{operationalStats.eventsAddedToday}</span>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* Databashälsa */}
        <section className="ops-section">
          <h2 className="ops-section-title">Databas</h2>
          <div className="ops-metrics-grid ops-metrics-grid--4">
            <div className="ops-metric">
              <span className="ops-metric-value">{databaseHealth.totalEvents.toLocaleString()}</span>
              <span className="ops-metric-label">Totalt antal händelser</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value">{databaseHealth.uniqueLocations}</span>
              <span className="ops-metric-label">Platser</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value">{databaseHealth.uniqueTypes}</span>
              <span className="ops-metric-label">Händelsetyper</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value">{databaseHealth.eventsWithGpsPercent}%</span>
              <span className="ops-metric-label">Med GPS</span>
            </div>
          </div>

          <div className="ops-grid ops-grid--2">
            <div className="ops-card">
              <h3 className="ops-card-title">Datatäckning</h3>
              <div className="ops-info-grid">
                <div className="ops-info-row">
                  <span className="ops-info-label">Äldsta händelse</span>
                  <span className="ops-info-value">
                    {databaseHealth.oldestEvent
                      ? new Date(databaseHealth.oldestEvent).toLocaleDateString('sv-SE')
                      : 'N/A'}
                  </span>
                </div>
                <div className="ops-info-row">
                  <span className="ops-info-label">Nyaste händelse</span>
                  <span className="ops-info-value">
                    {databaseHealth.newestEvent
                      ? new Date(databaseHealth.newestEvent).toLocaleDateString('sv-SE')
                      : 'N/A'}
                  </span>
                </div>
                <div className="ops-info-row">
                  <span className="ops-info-label">Uppdaterade händelser</span>
                  <span className="ops-info-value">
                    {databaseHealth.updatedEvents.toLocaleString()} ({databaseHealth.updatedEventsPercent}%)
                  </span>
                </div>
                <div className="ops-info-row">
                  <span className="ops-info-label">Hämtningsloggar</span>
                  <span className="ops-info-value">{databaseHealth.totalFetchLogs.toLocaleString()}</span>
                </div>
              </div>
            </div>

            <div className="ops-card">
              <h3 className="ops-card-title">Händelser per typ</h3>
              <div className="ops-type-list">
                {databaseHealth.eventsByType.slice(0, 8).map((item, index) => (
                  <div key={item.type} className="ops-type-item">
                    <span className="ops-type-rank">{index + 1}</span>
                    <span className="ops-type-name">{item.type}</span>
                    <span className="ops-type-count">{item.count.toLocaleString()}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </section>

        {/* Händelsestatistik */}
        <section className="ops-section">
          <h2 className="ops-section-title">Händelsestatistik</h2>
          <div className="ops-metrics-grid ops-metrics-grid--4">
            <div className="ops-metric">
              <span className="ops-metric-value">{eventStats.last24h}</span>
              <span className="ops-metric-label">Senaste 24h</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value">{eventStats.last7d}</span>
              <span className="ops-metric-label">Senaste 7d</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value">{eventStats.last30d}</span>
              <span className="ops-metric-label">Senaste 30d</span>
            </div>
            <div className="ops-metric">
              <span className="ops-metric-value">{eventStats.avgPerDay}</span>
              <span className="ops-metric-label">Snitt/dag</span>
            </div>
          </div>

          <div className="ops-grid ops-grid--2">
            <div className="ops-card">
              <h3 className="ops-card-title">Daglig trend (7d)</h3>
              <div className="ops-trend-chart">
                {eventStats.daily.map((day) => {
                  const height = (day.count / maxDailyEventCount) * 100;
                  return (
                    <div key={day.date} className="ops-trend-col">
                      <div className="ops-trend-bar-container">
                        <div
                          className="ops-trend-bar"
                          style={{ height: `${height}%` }}
                          title={`${day.date}: ${day.count} händelser`}
                        />
                      </div>
                      <span className="ops-trend-value">{day.count}</span>
                      <span className="ops-trend-label">{day.day}</span>
                    </div>
                  );
                })}
              </div>
            </div>

            <div className="ops-card">
              <h3 className="ops-card-title">Fördelning per timme (24h)</h3>
              <div className="ops-bar-chart ops-bar-chart--small">
                {eventStats.hourly.map((count, hour) => {
                  const height = (count / maxHourlyEventCount) * 100;
                  return (
                    <div key={hour} className="ops-bar-col">
                      <div className="ops-bar-container">
                        <div
                          className="ops-bar ops-bar--accent"
                          style={{ height: `${height}%` }}
                          title={`${hour}:00 - ${count} händelser`}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
              <div className="ops-bar-axis">
                <span>00:00</span>
                <span>12:00</span>
                <span>23:00</span>
              </div>
            </div>
          </div>
        </section>

        {/* Senaste fel */}
        {operationalStats.recentErrors.length > 0 && (
          <section className="ops-section">
            <h2 className="ops-section-title">Senaste fel</h2>
            <div className="ops-card">
              <div className="ops-error-list">
                {operationalStats.recentErrors.map((error, index) => (
                  <div key={index} className="ops-error-item">
                    <span className="ops-error-type">{error.error_type}</span>
                    <span className="ops-error-time">{formatDateTime(error.fetched_at)}</span>
                  </div>
                ))}
              </div>
            </div>
          </section>
        )}

        {/* Hämtningslogg */}
        <section className="ops-section">
          <h2 className="ops-section-title">Senaste hämtningslogg</h2>
          <div className="ops-card ops-card--table">
            <div className="ops-table-container">
              <table className="ops-table">
                <thead>
                  <tr>
                    <th>Tid</th>
                    <th>Status</th>
                    <th>Hämtade</th>
                    <th>Nya</th>
                    <th>Anteckningar</th>
                  </tr>
                </thead>
                <tbody>
                  {fetchLogs.map((log) => (
                    <tr key={log.id} className={log.success ? '' : 'ops-table-row--error'}>
                      <td className="ops-table-time">{formatDateTime(log.fetchedAt)}</td>
                      <td>
                        <span className={`ops-status-badge ops-status-badge--${log.success ? 'success' : 'error'}`}>
                          {log.success ? 'OK' : 'FEL'}
                        </span>
                      </td>
                      <td>{log.eventsFetched}</td>
                      <td className={log.eventsNew > 0 ? 'ops-table-highlight' : ''}>
                        {log.eventsNew > 0 ? `+${log.eventsNew}` : '0'}
                      </td>
                      <td className="ops-table-notes">{log.errorType || '-'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </main>

      <footer className="ops-footer">
        <p>Sambandscentralen Driftöversikt</p>
      </footer>
    </div>
  );
}
