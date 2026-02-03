'use client';

import { useState, useEffect, useCallback } from 'react';
import { VMAAlert, VMAResponse } from '@/types';

interface VMAViewProps {
  isActive: boolean;
}

function VMAAlertCard({ alert, isActive }: { alert: VMAAlert; isActive: boolean }) {
  const [expanded, setExpanded] = useState(false);

  const statusClass = isActive ? 'vma-alert-status--active' : 'vma-alert-status--inactive';
  const statusText = isActive ? '🔴 Aktiv' : 'Avslutad';
  const cardClass = isActive ? 'vma-alert vma-alert--active' : 'vma-alert';

  return (
    <article className={`${cardClass}${expanded ? ' expanded' : ''}`} data-id={alert.id}>
      <div
        className="vma-alert-header"
        tabIndex={0}
        role="button"
        aria-expanded={expanded}
        onClick={() => setExpanded(!expanded)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            setExpanded(!expanded);
          }
        }}
      >
        <div className="vma-alert-meta">
          <span className={`vma-alert-status ${statusClass}`}>{statusText}</span>
          <span className={`vma-severity ${alert.severityClass}`}>{alert.severityLabel}</span>
          <span className="vma-alert-time">
            {alert.sentDate}
            {alert.relativeTime && (
              <span className="vma-alert-time-relative"> • {alert.relativeTime}</span>
            )}
          </span>
        </div>
        <h3 className="vma-alert-headline">{alert.headline}</h3>
        <div className="vma-alert-areas">
          <span className="vma-alert-areas-icon">📍</span>
          <span>{alert.areaText}</span>
        </div>
        {alert.description && (
          <p className="vma-alert-description">{alert.description}</p>
        )}
      </div>
      <div className="vma-alert-body">
        {alert.description && (
          <div className="vma-alert-full-description">{alert.description}</div>
        )}
        {alert.instruction && (
          <div className="vma-alert-instruction">
            <div className="vma-alert-instruction-title">📋 Instruktioner</div>
            <div className="vma-alert-instruction-text">{alert.instruction}</div>
          </div>
        )}
        <div className="vma-alert-footer">
          <div className="vma-alert-info">
            <span className="vma-alert-info-item">📝 {alert.msgTypeLabel}</span>
            {alert.urgency && alert.urgency !== 'Unknown' && (
              <span className="vma-alert-info-item">⏱️ {alert.urgency}</span>
            )}
            {alert.certainty && alert.certainty !== 'Unknown' && (
              <span className="vma-alert-info-item">📊 {alert.certainty}</span>
            )}
          </div>
          {alert.web && (
            <a
              href={alert.web}
              target="_blank"
              rel="noopener noreferrer"
              className="vma-alert-link"
              onClick={(e) => e.stopPropagation()}
            >
              🔗 Läs mer på SR
            </a>
          )}
        </div>
      </div>
    </article>
  );
}

export default function VMAView({ isActive }: VMAViewProps) {
  const [data, setData] = useState<VMAResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [initialized, setInitialized] = useState(false);

  const loadVmaAlerts = useCallback(async (forceRefresh = false) => {
    if (loading) return;

    setLoading(true);
    setError(null);

    try {
      const url = forceRefresh ? '/api/vma?refresh=1' : '/api/vma';
      const res = await fetch(url);
      const result: VMAResponse = await res.json();

      if (!result.success) {
        throw new Error(result.error || 'Kunde inte hämta VMA');
      }

      setData(result);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Ett fel uppstod');
    } finally {
      setLoading(false);
      setInitialized(true);
    }
  }, [loading]);

  useEffect(() => {
    if (isActive && !initialized) {
      loadVmaAlerts();
    }
  }, [isActive, initialized, loadVmaAlerts]);

  const hasCurrentAlerts = data?.current && data.current.length > 0;
  const hasRecentAlerts = data?.recent && data.recent.length > 0;

  return (
    <section id="vmaContainer" className={`vma-container${isActive ? ' active' : ''}`}>
      <div className="vma-header">
        <div className="vma-title">
          <h2>⚠️ Viktigt Meddelande till Allmänheten</h2>
          <p>Aktuella och senaste VMA från Sveriges Radio</p>
        </div>
        <button
          type="button"
          id="vmaRefreshBtn"
          className="btn btn-secondary"
          onClick={() => loadVmaAlerts(true)}
          disabled={loading}
        >
          {loading ? (
            <>
              <span className="spinner-small" /> Laddar...
            </>
          ) : (
            '🔄 Uppdatera'
          )}
        </button>
      </div>

      <div id="vmaContent" className="vma-content">
        {loading && !initialized && (
          <div className="vma-loading">
            <div className="spinner" />
            <p>Hämtar VMA-meddelanden...</p>
          </div>
        )}

        {error && (
          <div className="vma-error">
            <div className="vma-error-icon">⚠️</div>
            <h3>Kunde inte hämta VMA</h3>
            <p>{error}</p>
            <button type="button" className="btn" onClick={() => loadVmaAlerts(true)}>
              Försök igen
            </button>
          </div>
        )}

        {!loading && !error && data && !hasCurrentAlerts && !hasRecentAlerts && (
          <div className="vma-empty">
            <div className="vma-empty-icon">✓</div>
            <h3>Inga VMA-meddelanden</h3>
            <p>Det finns inga aktiva eller nyliga VMA-meddelanden just nu. Systemet övervakas kontinuerligt.</p>
          </div>
        )}

        {!loading && !error && data && (hasCurrentAlerts || hasRecentAlerts) && (
          <>
            {hasCurrentAlerts ? (
              <div className="vma-section vma-active-section">
                <div className="vma-section-header">
                  <h3>🚨 Aktiva VMA</h3>
                  <span className="vma-section-count">{data.current.length}</span>
                </div>
                <div className="vma-alerts-list">
                  {data.current.map((alert) => (
                    <VMAAlertCard key={alert.id} alert={alert} isActive={true} />
                  ))}
                </div>
              </div>
            ) : (
              <div className="vma-no-active">
                <div className="vma-no-active-icon">✓</div>
                <h4>Inga aktiva VMA</h4>
                <p>Det finns inga pågående VMA-meddelanden just nu.</p>
              </div>
            )}

            {hasRecentAlerts && (
              <div className="vma-section">
                <div className="vma-section-header">
                  <h3>📜 Senaste VMA</h3>
                  <span className="vma-section-count">{data.recent.length}</span>
                </div>
                <div className="vma-alerts-list">
                  {data.recent.map((alert) => (
                    <VMAAlertCard key={alert.id} alert={alert} isActive={false} />
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </section>
  );
}
