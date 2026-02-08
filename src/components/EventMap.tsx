'use client';

import { useEffect, useRef, useCallback, useState, memo } from 'react';
import { FormattedEvent } from '@/types';

interface EventMapProps {
  events: FormattedEvent[];
  isActive: boolean;
}

type TimeRange = '1h' | '6h' | '24h';

const TIME_RANGES: { key: TimeRange; label: string; ms: number }[] = [
  { key: '1h', label: '1 tim', ms: 60 * 60 * 1000 },
  { key: '6h', label: '6 tim', ms: 6 * 60 * 60 * 1000 },
  { key: '24h', label: '24 tim', ms: 24 * 60 * 60 * 1000 },
];

const REPLAY_STEP_MS = 5 * 60 * 1000; // Each replay step = 5 minutes of real time
const REPLAY_INTERVAL_MS = 80; // Animation frame interval

function EventMap({ events, isActive }: EventMapProps) {
  const mapRef = useRef<L.Map | null>(null);
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const markersLayerRef = useRef<L.FeatureGroup | null>(null);
  const infoControlRef = useRef<L.Control | null>(null);
  const initializingRef = useRef(false);
  const leafletRef = useRef<typeof import('leaflet') | null>(null);
  const eventsRef = useRef<FormattedEvent[]>(events);
  const replayIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Timeline state
  const [timeRange, setTimeRange] = useState<TimeRange>('24h');
  const [isPlaying, setIsPlaying] = useState(false);
  const [replayPosition, setReplayPosition] = useState(1); // 0..1 slider position
  const [replayTimestamp, setReplayTimestamp] = useState<number | null>(null);

  // Update events ref when events change
  eventsRef.current = events;

  // Escape HTML special characters to prevent XSS in Leaflet popups
  const escapeHtml = useCallback((str: string): string => {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }, []);

  // Get the current time range in ms
  const getTimeRangeMs = useCallback(() => {
    return TIME_RANGES.find(r => r.key === timeRange)?.ms || 24 * 60 * 60 * 1000;
  }, [timeRange]);

  // Create markers from events with heat-fade based on age
  const updateMarkers = useCallback((cutoffTimestamp?: number | null) => {
    const L = leafletRef.current;
    const map = mapRef.current;
    if (!L || !map) return;

    // Clear existing markers
    if (markersLayerRef.current) {
      markersLayerRef.current.clearLayers();
    } else {
      markersLayerRef.current = L.featureGroup().addTo(map);
    }

    // Remove old info control
    if (infoControlRef.current) {
      map.removeControl(infoControlRef.current);
    }

    const now = cutoffTimestamp ?? Date.now();
    const rangeMs = getTimeRangeMs();
    const windowStart = now - rangeMs;

    const recentEvents = eventsRef.current.filter((e) => {
      const eventTimeStr = e.date?.iso || e.datetime;
      if (!eventTimeStr) return false;
      const eventDate = new Date(eventTimeStr).getTime();
      return !isNaN(eventDate) && eventDate >= windowStart && eventDate <= now;
    });

    let eventCount = 0;

    recentEvents.forEach((e) => {
      if (e.gps) {
        const [lat, lng] = e.gps.split(',').map(Number);
        if (!isNaN(lat) && !isNaN(lng)) {
          eventCount++;

          const eventTimeStr = e.date?.iso || e.datetime;
          const eventTs = new Date(eventTimeStr).getTime();
          const age = now - eventTs; // ms since event
          const ageFraction = Math.min(1, age / rangeMs); // 0 = brand new, 1 = oldest

          // Heat fade: newer = brighter & larger, older = dimmer & smaller
          const opacity = 0.95 - ageFraction * 0.65; // 0.95 -> 0.30
          const radius = 10 - ageFraction * 5; // 10 -> 5
          const borderOpacity = 1 - ageFraction * 0.6;

          // Pulse ring for very recent events (< 30 min)
          const isVeryRecent = age < 30 * 60 * 1000;

          const diffMs = age;
          const diffMins = Math.floor(diffMs / 60000);
          const diffHours = Math.floor(diffMs / 3600000);
          const relTime =
            diffMins <= 1
              ? 'Just nu'
              : diffMins < 60
                ? `${diffMins} min sedan`
                : `${diffHours} timmar sedan`;

          const googleMapsUrl = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
          const summaryText = e.summary || '';
          const summaryPreview =
            summaryText.length > 120 ? `${summaryText.substring(0, 120)}...` : summaryText;

          // Pulse ring for recent events
          if (isVeryRecent) {
            const pulse = L.circleMarker([lat, lng], {
              radius: radius + 6,
              fillColor: e.color,
              color: e.color,
              weight: 1,
              opacity: 0.3,
              fillOpacity: 0.1,
              className: 'pulse-marker',
            });
            markersLayerRef.current!.addLayer(pulse);
          }

          const m = L.circleMarker([lat, lng], {
            radius,
            fillColor: e.color,
            color: '#fff',
            weight: isVeryRecent ? 2.5 : 2,
            opacity: borderOpacity,
            fillOpacity: opacity,
          });

          const safeName = escapeHtml(e.name || '');
          const safeType = escapeHtml(e.type || '');
          const safeSummary = escapeHtml(summaryPreview);
          const safeLocation = escapeHtml(e.location || '');
          const safeIcon = escapeHtml(e.icon || '');
          const safeColor = escapeHtml(e.color || '');
          const safeUrl = e.url ? escapeHtml(e.url) : '';

          m.bindPopup(`
            <div class="map-popup">
              <span class="badge" style="background:${safeColor}20;color:${safeColor}">${safeIcon} ${safeType}</span>
              <div class="popup-time">${isVeryRecent ? '🔴' : '🕐'} ${relTime}</div>
              <h3>${safeName}</h3>
              <p>${safeSummary}</p>
              <p><strong>📍 ${safeLocation}</strong></p>
              <div class="popup-links">
                <a href="${googleMapsUrl}" target="_blank" rel="noopener noreferrer">🗺️ Google Maps</a>
                ${safeUrl ? `<a href="https://polisen.se${safeUrl}" target="_blank" rel="noopener noreferrer nofollow">📄 Läs mer</a>` : ''}
              </div>
            </div>
          `);

          markersLayerRef.current!.addLayer(m);
        }
      }
    });

    // Add info control
    const rangeLabel = TIME_RANGES.find(r => r.key === timeRange)?.label || '24 tim';
    const timeLabel = cutoffTimestamp
      ? new Date(cutoffTimestamp).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })
      : 'nu';

    const InfoControl = L.Control.extend({
      onAdd: function () {
        const div = L.DomUtil.create('div', 'map-info');
        div.innerHTML = `<div class="map-info-content">📍 ${eventCount} händelser<br><small>senaste ${rangeLabel}${cutoffTimestamp ? ` (t.o.m. ${timeLabel})` : ''}</small></div>`;
        return div;
      },
    });

    infoControlRef.current = new InfoControl({ position: 'topright' });
    infoControlRef.current.addTo(map);

    // Fit bounds if we have markers (only on initial load, not during replay)
    if (!cutoffTimestamp && markersLayerRef.current.getLayers().length > 0) {
      map.fitBounds(markersLayerRef.current.getBounds(), { padding: [40, 40] });
    }
  }, [escapeHtml, getTimeRangeMs, timeRange]);

  // Handle replay
  useEffect(() => {
    if (!isPlaying) {
      if (replayIntervalRef.current) {
        clearInterval(replayIntervalRef.current);
        replayIntervalRef.current = null;
      }
      return;
    }

    const rangeMs = getTimeRangeMs();
    const now = Date.now();
    const windowStart = now - rangeMs;

    // Start replay from the beginning of the time window
    let currentPos = 0;

    replayIntervalRef.current = setInterval(() => {
      currentPos += REPLAY_STEP_MS / rangeMs; // Advance proportionally

      if (currentPos >= 1) {
        // Replay finished
        currentPos = 1;
        setIsPlaying(false);
        setReplayPosition(1);
        setReplayTimestamp(null);
        updateMarkers(null);
        return;
      }

      setReplayPosition(currentPos);
      const ts = windowStart + currentPos * rangeMs;
      setReplayTimestamp(ts);
      updateMarkers(ts);
    }, REPLAY_INTERVAL_MS);

    return () => {
      if (replayIntervalRef.current) {
        clearInterval(replayIntervalRef.current);
        replayIntervalRef.current = null;
      }
    };
  }, [isPlaying, getTimeRangeMs, updateMarkers]);

  // Handle manual slider change
  const handleSliderChange = useCallback((value: number) => {
    setIsPlaying(false);
    setReplayPosition(value);

    if (value >= 0.99) {
      // At end = live
      setReplayTimestamp(null);
      updateMarkers(null);
    } else {
      const rangeMs = getTimeRangeMs();
      const now = Date.now();
      const windowStart = now - rangeMs;
      const ts = windowStart + value * rangeMs;
      setReplayTimestamp(ts);
      updateMarkers(ts);
    }
  }, [getTimeRangeMs, updateMarkers]);

  // Handle time range change
  const handleTimeRangeChange = useCallback((newRange: TimeRange) => {
    setTimeRange(newRange);
    setIsPlaying(false);
    setReplayPosition(1);
    setReplayTimestamp(null);
  }, []);

  // Toggle play/pause
  const togglePlay = useCallback(() => {
    if (isPlaying) {
      setIsPlaying(false);
    } else {
      setReplayPosition(0);
      setIsPlaying(true);
    }
  }, [isPlaying]);

  // Initialize map only once
  useEffect(() => {
    if (!isActive) return;
    if (mapRef.current || initializingRef.current) return;

    initializingRef.current = true;

    const initMap = async () => {
      const L = await import('leaflet');
      leafletRef.current = L;

      if (!mapContainerRef.current) {
        initializingRef.current = false;
        return;
      }

      if (mapRef.current) {
        initializingRef.current = false;
        return;
      }

      // Wait for the container to be laid out
      await new Promise<void>(resolve => {
        requestAnimationFrame(() => resolve());
      });

      if (!mapContainerRef.current || mapRef.current) {
        initializingRef.current = false;
        return;
      }

      const map = L.map(mapContainerRef.current, {
        center: [62.5, 17.5],
        zoom: 5,
        zoomControl: true,
        attributionControl: true,
      });

      L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 18,
      }).addTo(map);

      mapRef.current = map;
      initializingRef.current = false;

      updateMarkers(null);

      setTimeout(() => map.invalidateSize(), 100);
      setTimeout(() => map.invalidateSize(), 500);
    };

    initMap();

    return () => {
      if (replayIntervalRef.current) {
        clearInterval(replayIntervalRef.current);
        replayIntervalRef.current = null;
      }
      if (mapRef.current) {
        mapRef.current.remove();
        mapRef.current = null;
      }
      markersLayerRef.current = null;
      infoControlRef.current = null;
      leafletRef.current = null;
      initializingRef.current = false;
    };
  }, [isActive, updateMarkers]);

  // Update markers when events or time range change
  useEffect(() => {
    if (mapRef.current && leafletRef.current && !isPlaying) {
      updateMarkers(replayTimestamp);
    }
  }, [events, timeRange, updateMarkers, isPlaying, replayTimestamp]);

  // Invalidate map size when becoming active
  useEffect(() => {
    if (isActive && mapRef.current) {
      requestAnimationFrame(() => {
        mapRef.current?.invalidateSize();
      });
    }
  }, [isActive]);

  // Format the slider timestamp for display
  const sliderTimeLabel = replayTimestamp
    ? new Date(replayTimestamp).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })
    : 'Live';

  return (
    <div
      className={`map-wrapper${isActive ? ' active' : ''}`}
      aria-hidden={!isActive}
    >
      {/* Timeline controls */}
      <div className="map-timeline">
        <div className="timeline-controls">
          {/* Time range selector */}
          <div className="timeline-range-selector">
            {TIME_RANGES.map(r => (
              <button
                key={r.key}
                className={`timeline-range-btn${timeRange === r.key ? ' active' : ''}`}
                onClick={() => handleTimeRangeChange(r.key)}
                aria-pressed={timeRange === r.key}
              >
                {r.label}
              </button>
            ))}
          </div>

          {/* Play button */}
          <button
            className={`timeline-play-btn${isPlaying ? ' playing' : ''}`}
            onClick={togglePlay}
            aria-label={isPlaying ? 'Pausa uppspelning' : 'Spela upp tidslinje'}
            title={isPlaying ? 'Pausa' : 'Replay'}
          >
            {isPlaying ? (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                <rect x="6" y="4" width="4" height="16" rx="1" />
                <rect x="14" y="4" width="4" height="16" rx="1" />
              </svg>
            ) : (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                <polygon points="5,3 19,12 5,21" />
              </svg>
            )}
          </button>

          {/* Time slider */}
          <div className="timeline-slider-container">
            <input
              type="range"
              className="timeline-slider"
              min="0"
              max="1"
              step="0.005"
              value={replayPosition}
              onChange={(e) => handleSliderChange(parseFloat(e.target.value))}
              aria-label="Tidslinje"
            />
            <div
              className="timeline-slider-fill"
              style={{ width: `${replayPosition * 100}%` }}
            />
          </div>

          {/* Current time label */}
          <span className={`timeline-time-label${replayTimestamp ? '' : ' live'}`}>
            {replayTimestamp ? null : <span className="timeline-live-dot" />}
            {sliderTimeLabel}
          </span>
        </div>
      </div>

      <div
        id="mapContainer"
        className="map-container"
        ref={mapContainerRef}
      />
    </div>
  );
}

export default memo(EventMap);
