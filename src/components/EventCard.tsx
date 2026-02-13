'use client';

import { useState, useCallback, useEffect, useMemo } from 'react';
import { FormattedEvent, getTypeClass } from '@/types';
import { formatRelativeTime } from '@/lib/utils';
import type { Density } from './ClientApp';

interface EventCardProps {
  event: FormattedEvent;
  currentView: string;
  onShowMap?: (lat: number, lng: number, location: string) => void;
  isHighlighted?: boolean;
  autoExpand?: boolean;
  density?: Density;
}

export default function EventCard({ event, currentView, onShowMap, isHighlighted, autoExpand, density }: EventCardProps) {
  const [expanded, setExpanded] = useState(isHighlighted || false);
  const [details, setDetails] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(false);
  const [copied, setCopied] = useState(false);

  // Auto-expand and fetch details if highlighted
  useEffect(() => {
    if (isHighlighted && !details && event.url) {
      const fetchDetails = async () => {
        setLoading(true);
        try {
          const res = await fetch(`/api/details?url=${encodeURIComponent(event.url)}`);
          const data = await res.json();
          if (data.success && data.details?.content) {
            setDetails(data.details.content);
          }
        } catch {
          // Silently fail - user can still expand manually
        } finally {
          setLoading(false);
        }
      };
      fetchDetails();
    }
  }, [isHighlighted, details, event.url]);

  // Auto-expand when "Read More" setting is enabled
  useEffect(() => {
    if (autoExpand) {
      setExpanded(true);
      if (!details && event.url && !loading) {
        setLoading(true);
        setError(false);
        fetch(`/api/details?url=${encodeURIComponent(event.url)}`)
          .then(res => res.json())
          .then(data => {
            if (data.success && data.details?.content) {
              setDetails(data.details.content);
            }
          })
          .catch(() => {
            // Silently fail
          })
          .finally(() => setLoading(false));
      }
    } else if (!isHighlighted) {
      setExpanded(false);
    }
  }, [autoExpand]); // eslint-disable-line react-hooks/exhaustive-deps

  const typeClass = getTypeClass(event.type);

  const toggleAccordion = useCallback(async () => {
    if (expanded) {
      setExpanded(false);
      return;
    }

    setExpanded(true);

    // Skip fetching if no URL or already have details
    if (!event.url || details) return;

    setLoading(true);
    setError(false);

    try {
      const res = await fetch(`/api/details?url=${encodeURIComponent(event.url)}`);
      const data = await res.json();

      if (data.success && data.details?.content) {
        setDetails(data.details.content);
      } else {
        setError(true);
      }
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [expanded, event.url, details]);

  const handleShowMap = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    if (!event.gps || !onShowMap) return;

    const [lat, lng] = event.gps.split(',').map(s => parseFloat(s.trim()));
    if (!isNaN(lat) && !isNaN(lng)) {
      onShowMap(lat, lng, event.location);
    }
  }, [event.gps, event.location, onShowMap]);

  const hasGps = event.gps && event.gps.includes(',');
  let gpsCoords: { lat: number; lng: number } | null = null;
  if (hasGps) {
    const [lat, lng] = event.gps.split(',').map(s => parseFloat(s.trim()));
    if (!isNaN(lat) && !isNaN(lng)) {
      gpsCoords = { lat, lng };
    }
  }

  const handleShare = useCallback(async (e: React.MouseEvent) => {
    e.stopPropagation();
    if (event.id === null) return;

    const url = `${window.location.origin}/?event=${event.id}`;

    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Fallback for older browsers
      const textArea = document.createElement('textarea');
      textArea.value = url;
      textArea.style.position = 'fixed';
      textArea.style.opacity = '0';
      document.body.appendChild(textArea);
      textArea.select();
      document.execCommand('copy');
      document.body.removeChild(textArea);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  }, [event.id]);

  const cardClasses = [
    'event-card',
    expanded ? 'expanded' : '',
    isHighlighted ? 'highlighted' : '',
  ].filter(Boolean).join(' ');

  // Compute relative time client-side so it stays fresh
  const relativeTime = useMemo(() => {
    const eventDate = new Date(event.date.iso || event.datetime);
    return formatRelativeTime(eventDate, new Date());
  }, [event.date.iso, event.datetime]);

  // Stream mode: completely different ticker/feed layout
  if (density === 'stream') {
    const isRecent = relativeTime.includes('min') || relativeTime.includes('Just');
    return (
      <article
        className={`stream-item${expanded ? ' stream-item--expanded' : ''}${isHighlighted ? ' stream-item--highlighted' : ''}`}
        data-url={event.url}
        data-event-id={event.id ?? undefined}
        onClick={toggleAccordion}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleAccordion();
          }
        }}
      >
        <div className="stream-item__indicator">
          <span className={`stream-item__dot${isRecent ? ' stream-item__dot--recent' : ''}`} style={{ background: event.color }} />
        </div>
        <div className="stream-item__time">
          <span className={`stream-item__relative${isRecent ? ' stream-item__relative--recent' : ''}`}>{relativeTime}</span>
        </div>
        <div className="stream-item__content">
          <div className="stream-item__headline">
            <span className="stream-item__type">{event.icon} {event.type}</span>
            <span className="stream-item__headline-location">
              <span className="stream-item__sep">&mdash;</span>
              <a
                href={`/?location=${encodeURIComponent(event.location)}&view=${currentView}`}
                className="stream-item__location"
                onClick={(e) => e.stopPropagation()}
              >
                {event.location}
              </a>
            </span>
          </div>
          <p className="stream-item__summary">{event.summary}</p>
          {expanded && (
            <div className="stream-item__details">
              {loading && <span className="stream-item__loading">Laddar detaljer...</span>}
              {error && <span className="stream-item__error">Kunde inte hämta detaljer.</span>}
              {details && <p className="stream-item__detail-text">{details}</p>}
              <div className="stream-item__actions">
                {event.url && (
                  <a
                    className="stream-item__link"
                    href={`https://polisen.se${event.url}`}
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    onClick={(e) => e.stopPropagation()}
                  >
                    polisen.se
                  </a>
                )}
                {gpsCoords && (
                  <button type="button" className="stream-item__link" onClick={handleShowMap}>
                    Visa på karta
                  </button>
                )}
                {event.id !== null && (
                  <button type="button" className="stream-item__link" onClick={handleShare}>
                    {copied ? 'Kopierad!' : 'Dela'}
                  </button>
                )}
              </div>
            </div>
          )}
        </div>
        <div className="stream-item__region">
          {event.location}
        </div>
      </article>
    );
  }

  return (
    <article
      className={cardClasses}
      data-url={event.url}
      data-event-id={event.id ?? undefined}
    >
      <div
        className="event-card-header"
        tabIndex={0}
        role="button"
        aria-expanded={expanded}
        aria-label={`Expandera händelse: ${event.type} i ${event.location}`}
        onClick={toggleAccordion}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleAccordion();
          }
        }}
      >
        <div className="event-header-content">
          <div className="event-meta-row">
            <span className="event-relative">{event.date.time}</span>
            <span className="meta-separator">•</span>
            <span className="event-datetime">
              {event.date.day} {event.date.month}
            </span>
            {event.url && (
              <>
                <span className="meta-separator">•</span>
                <a
                  className="event-source"
                  href={`https://polisen.se${event.url}`}
                  target="_blank"
                  rel="noopener noreferrer nofollow"
                  onClick={(e) => e.stopPropagation()}
                >
                  Källa
                </a>
              </>
            )}
            {event.wasUpdated && event.updated && (
              <span className="updated-indicator" title={`Uppdaterad ${event.updated}`}>
                uppdaterad
              </span>
            )}
          </div>
          <div className="event-title-group">
            <a
              href={`/?type=${encodeURIComponent(event.type)}&view=${currentView}`}
              className={`event-type ${typeClass}`}
              onClick={(e) => e.stopPropagation()}
            >
              {event.icon} {event.type}
            </a>
            <a
              href={`/?location=${encodeURIComponent(event.location)}&view=${currentView}`}
              className="event-location-link"
              onClick={(e) => e.stopPropagation()}
            >
              {event.location}
            </a>
          </div>
          <p className="event-summary">{event.summary}</p>
          <div className="event-header-actions">
            <button
              type="button"
              className={`expand-details-btn${loading ? ' loading' : ''}`}
              onClick={(e) => {
                e.stopPropagation();
                toggleAccordion();
              }}
            >
              {expanded ? 'Dölj' : 'Läs mer'}
            </button>
            {gpsCoords && (
              <button
                type="button"
                className="show-map-link"
                data-lat={gpsCoords.lat}
                data-lng={gpsCoords.lng}
                data-location={event.location}
                onClick={handleShowMap}
              >
                Visa på karta
              </button>
            )}
            {event.id !== null && (
              <button
                type="button"
                className={`share-event-btn${copied ? ' copied' : ''}`}
                onClick={handleShare}
                title="Kopiera länk till händelse"
              >
                {copied ? 'Kopierad!' : 'Dela'}
              </button>
            )}
          </div>
        </div>
      </div>
      <div className="event-card-body">
        <div className={`event-details${expanded ? ' visible' : ''}${loading ? ' loading' : ''}${error ? ' error' : ''}`}>
          {loading && 'Laddar detaljer...'}
          {error && 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.'}
          {details && details}
        </div>
      </div>
    </article>
  );
}
