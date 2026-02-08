'use client';

import { useEffect, useRef, useCallback, useState, memo } from 'react';
import { FormattedEvent } from '@/types';

interface EventMapProps {
  events: FormattedEvent[];
  isActive: boolean;
}

type TimeRange = '24h' | '48h' | '72h';

const TIME_RANGES: { key: TimeRange; label: string; ms: number }[] = [
  { key: '24h', label: '24 tim', ms: 24 * 60 * 60 * 1000 },
  { key: '48h', label: '48 tim', ms: 48 * 60 * 60 * 1000 },
  { key: '72h', label: '72 tim', ms: 72 * 60 * 60 * 1000 },
];

const REPLAY_STEP_MS = 5 * 60 * 1000;
const REPLAY_INTERVAL_MS = 80;

// Offset radius in degrees (~300m) to separate co-located markers
const OFFSET_RADIUS = 0.003;

/**
 * Pre-compute display positions for events that share the same GPS coordinates.
 * Co-located markers are fanned out in a circle so each one is visible.
 */
function computeMarkerPositions(events: FormattedEvent[]): Map<number, [number, number]> {
  const positions = new Map<number, [number, number]>();
  const groups = new Map<string, FormattedEvent[]>();

  for (const e of events) {
    if (!e.gps || e.id === null) continue;
    const key = e.gps.trim();
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key)!.push(e);
  }

  for (const [gpsKey, group] of groups) {
    const [baseLat, baseLng] = gpsKey.split(',').map(Number);
    if (isNaN(baseLat) || isNaN(baseLng)) continue;

    if (group.length === 1) {
      positions.set(group[0].id!, [baseLat, baseLng]);
    } else {
      for (let i = 0; i < group.length; i++) {
        const angle = (2 * Math.PI * i) / group.length - Math.PI / 2;
        const lat = baseLat + OFFSET_RADIUS * Math.cos(angle);
        const lng = baseLng + OFFSET_RADIUS * Math.sin(angle);
        positions.set(group[i].id!, [lat, lng]);
      }
    }
  }

  return positions;
}

// Escape HTML to prevent XSS in Leaflet popups
function escapeHtml(str: string): string {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function EventMapInner({ events, isActive }: EventMapProps) {
  const mapRef = useRef<L.Map | null>(null);
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const markersLayerRef = useRef<L.FeatureGroup | null>(null);
  const infoControlRef = useRef<L.Control | null>(null);
  const leafletRef = useRef<typeof import('leaflet') | null>(null);
  const eventsRef = useRef<FormattedEvent[]>(events);
  const replayIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const hasFittedBoundsRef = useRef(false);
  // Track which event IDs have been added during current replay run
  const addedMarkerIdsRef = useRef<Set<number>>(new Set());
  // Counter for replay runs to invalidate stale intervals
  const replayRunRef = useRef(0);

  const [timeRange, setTimeRange] = useState<TimeRange>('24h');
  const [isPlaying, setIsPlaying] = useState(false);
  const [replayPosition, setReplayPosition] = useState(1);
  const [replayTimestamp, setReplayTimestamp] = useState<number | null>(null);
  const [mapReady, setMapReady] = useState(false);
  const [visibleCount, setVisibleCount] = useState(0);

  eventsRef.current = events;

  const getRangeMs = useCallback(
    (range?: TimeRange) => TIME_RANGES.find(r => r.key === (range ?? timeRange))?.ms ?? 24 * 60 * 60 * 1000,
    [timeRange]
  );

  // --- Clear all markers from the map ---
  const clearMarkers = useCallback(() => {
    const L = leafletRef.current;
    const map = mapRef.current;
    if (!L || !map) return;

    if (markersLayerRef.current) {
      markersLayerRef.current.clearLayers();
    } else {
      markersLayerRef.current = L.featureGroup().addTo(map);
    }

    if (infoControlRef.current) {
      map.removeControl(infoControlRef.current);
      infoControlRef.current = null;
    }
  }, []);

  // --- Add a single marker to the map ---
  const addMarker = useCallback((e: FormattedEvent, now: number, rangeMs: number, posOverride?: [number, number]) => {
    const L = leafletRef.current;
    if (!L || !markersLayerRef.current) return;
    if (!e.gps) return;

    const [rawLat, rawLng] = e.gps.split(',').map(Number);
    if (isNaN(rawLat) || isNaN(rawLng)) return;

    const lat = posOverride ? posOverride[0] : rawLat;
    const lng = posOverride ? posOverride[1] : rawLng;

    const eventTs = new Date(e.date?.iso || e.datetime).getTime();
    const age = now - eventTs;
    const ageFrac = Math.min(1, age / rangeMs);

    const opacity = 0.95 - ageFrac * 0.6;
    const radius = 10 - ageFrac * 4;
    const isRecent = age < 30 * 60 * 1000;

    const mins = Math.floor(age / 60000);
    const hours = Math.floor(age / 3600000);
    const relTime = mins <= 1 ? 'Just nu' : mins < 60 ? `${mins} min sedan` : `${hours} tim sedan`;

    // Pulse ring for recent events
    if (isRecent) {
      markersLayerRef.current.addLayer(
        L.circleMarker([lat, lng], {
          radius: radius + 6,
          fillColor: e.color,
          color: e.color,
          weight: 1,
          opacity: 0.3,
          fillOpacity: 0.08,
          className: 'pulse-marker',
        })
      );
    }

    const marker = L.circleMarker([lat, lng], {
      radius,
      fillColor: e.color,
      color: '#fff',
      weight: isRecent ? 2.5 : 1.5,
      opacity: 1 - ageFrac * 0.5,
      fillOpacity: opacity,
    });

    const safeName = escapeHtml(e.name || '');
    const safeType = escapeHtml(e.type || '');
    const safeSummary = escapeHtml(
      (e.summary || '').length > 120 ? e.summary!.substring(0, 120) + '...' : e.summary || ''
    );
    const safeLocation = escapeHtml(e.location || '');
    const safeIcon = escapeHtml(e.icon || '');
    const safeColor = escapeHtml(e.color || '');
    const safeUrl = e.url ? escapeHtml(e.url) : '';
    const gMaps = `https://www.google.com/maps/search/?api=1&query=${rawLat},${rawLng}`;

    marker.bindPopup(`
      <div class="map-popup">
        <span class="badge" style="background:${safeColor}20;color:${safeColor}">${safeIcon} ${safeType}</span>
        <div class="popup-time">${isRecent ? '🔴' : '🕐'} ${relTime}</div>
        <h3>${safeName}</h3>
        <p>${safeSummary}</p>
        <p><strong>${safeLocation}</strong></p>
        <div class="popup-links">
          <a href="${gMaps}" target="_blank" rel="noopener noreferrer">Google Maps</a>
          ${safeUrl ? `<a href="https://polisen.se${safeUrl}" target="_blank" rel="noopener noreferrer nofollow">Polisen.se</a>` : ''}
        </div>
      </div>
    `);

    markersLayerRef.current.addLayer(marker);
  }, []);

  // --- Update the info badge ---
  const updateInfoBadge = useCallback((count: number, cutoffTs?: number | null, rangeOverride?: TimeRange) => {
    const L = leafletRef.current;
    const map = mapRef.current;
    if (!L || !map) return;

    if (infoControlRef.current) {
      map.removeControl(infoControlRef.current);
      infoControlRef.current = null;
    }

    const rangeLabel = TIME_RANGES.find(r => r.key === (rangeOverride ?? timeRange))?.label ?? '24 tim';
    const timeLabel = cutoffTs
      ? new Date(cutoffTs).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })
      : null;

    const InfoCtrl = L.Control.extend({
      onAdd() {
        const div = L.DomUtil.create('div', 'map-info');
        div.innerHTML = `<div class="map-info-content">${count} händelser<br><small>senaste ${rangeLabel}${timeLabel ? ` (${timeLabel})` : ''}</small></div>`;
        return div;
      },
    });
    infoControlRef.current = new InfoCtrl({ position: 'topright' });
    infoControlRef.current.addTo(map);
  }, [timeRange]);

  // --- Full render of all markers (for non-playing states) ---
  const renderMarkers = useCallback((cutoffTs?: number | null, rangeOverride?: TimeRange) => {
    const L = leafletRef.current;
    const map = mapRef.current;
    if (!L || !map) return;

    clearMarkers();
    if (!markersLayerRef.current) {
      markersLayerRef.current = L.featureGroup().addTo(map);
    }

    const now = cutoffTs ?? Date.now();
    const rangeMs = getRangeMs(rangeOverride);
    const windowStart = now - rangeMs;

    const visible = eventsRef.current.filter(e => {
      const ts = new Date(e.date?.iso || e.datetime).getTime();
      return !isNaN(ts) && ts >= windowStart && ts <= now;
    });

    const positions = computeMarkerPositions(visible);

    for (const e of visible) {
      const pos = e.id !== null ? positions.get(e.id) : undefined;
      addMarker(e, now, rangeMs, pos);
    }

    const count = markersLayerRef.current.getLayers().length;
    setVisibleCount(visible.length);
    updateInfoBadge(visible.length, cutoffTs, rangeOverride);

    // Fit bounds once on first meaningful render
    if (!hasFittedBoundsRef.current && !cutoffTs && count > 0) {
      map.fitBounds(markersLayerRef.current.getBounds(), { padding: [40, 40] });
      hasFittedBoundsRef.current = true;
    }
  }, [getRangeMs, clearMarkers, addMarker, updateInfoBadge]);

  // --- Initialize map once ---
  useEffect(() => {
    if (!isActive || mapRef.current) return;

    let cancelled = false;

    (async () => {
      const L = await import('leaflet');
      if (cancelled) return;
      leafletRef.current = L;

      // Wait one frame for the container to have layout
      await new Promise<void>(r => requestAnimationFrame(() => r()));
      if (cancelled || !mapContainerRef.current) return;

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
      setMapReady(true);

      // Make sure tiles render properly
      setTimeout(() => map.invalidateSize(), 200);
    })();

    return () => {
      cancelled = true;
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
      hasFittedBoundsRef.current = false;
      setMapReady(false);
    };
  }, [isActive]); // Only depends on isActive — stable

  // --- Render markers when data/range changes (non-playing) ---
  useEffect(() => {
    if (mapReady && !isPlaying) {
      renderMarkers(replayTimestamp);
    }
  }, [mapReady, events, timeRange, renderMarkers, isPlaying, replayTimestamp]);

  // --- Fix tile sizing on tab switch ---
  useEffect(() => {
    if (isActive && mapRef.current) {
      requestAnimationFrame(() => mapRef.current?.invalidateSize());
    }
  }, [isActive]);

  // --- Replay engine: animated dot-by-dot playback ---
  useEffect(() => {
    if (!isPlaying || !mapReady) {
      if (replayIntervalRef.current) {
        clearInterval(replayIntervalRef.current);
        replayIntervalRef.current = null;
      }
      return;
    }

    // New run — bump counter, reset tracking
    const runId = ++replayRunRef.current;
    addedMarkerIdsRef.current = new Set();

    // Immediately clear all existing markers for a clean slate
    clearMarkers();
    if (!markersLayerRef.current) {
      const L = leafletRef.current;
      if (L && mapRef.current) {
        markersLayerRef.current = L.featureGroup().addTo(mapRef.current);
      }
    }
    setVisibleCount(0);

    const rangeMs = getRangeMs();
    const now = Date.now();
    const start = now - rangeMs;
    let pos = 0;

    // Pre-sort events by timestamp for efficient replay
    const sortedEvents = eventsRef.current
      .filter(e => {
        const ts = new Date(e.date?.iso || e.datetime).getTime();
        return !isNaN(ts) && ts >= start && ts <= now && e.gps;
      })
      .sort((a, b) => {
        const ta = new Date(a.date?.iso || a.datetime).getTime();
        const tb = new Date(b.date?.iso || b.datetime).getTime();
        return ta - tb;
      });

    // Pre-compute offset positions so co-located markers fan out
    const positions = computeMarkerPositions(sortedEvents);

    replayIntervalRef.current = setInterval(() => {
      // Stale run check
      if (replayRunRef.current !== runId) return;

      pos += REPLAY_STEP_MS / rangeMs;
      if (pos >= 1) {
        pos = 1;
        setIsPlaying(false);
        setReplayPosition(1);
        setReplayTimestamp(null);
        // Re-render all markers in final state
        renderMarkers(null);
        return;
      }

      setReplayPosition(pos);
      const ts = start + pos * rangeMs;
      setReplayTimestamp(ts);

      // Add only NEW markers that haven't been added yet
      for (const e of sortedEvents) {
        const eventTs = new Date(e.date?.iso || e.datetime).getTime();
        if (eventTs > ts) break; // sorted, so no more to add
        if (e.id !== null && addedMarkerIdsRef.current.has(e.id)) continue;
        const posOverride = e.id !== null ? positions.get(e.id) : undefined;
        addMarker(e, ts, rangeMs, posOverride);
        if (e.id !== null) addedMarkerIdsRef.current.add(e.id);
      }

      const totalAdded = addedMarkerIdsRef.current.size;
      setVisibleCount(totalAdded);
      updateInfoBadge(totalAdded, ts);
    }, REPLAY_INTERVAL_MS);

    return () => {
      if (replayIntervalRef.current) {
        clearInterval(replayIntervalRef.current);
        replayIntervalRef.current = null;
      }
    };
  }, [isPlaying, mapReady, getRangeMs, renderMarkers, clearMarkers, addMarker, updateInfoBadge]);

  // --- Handlers ---
  const handleSlider = useCallback((val: number) => {
    setIsPlaying(false);
    setReplayPosition(val);
    if (val >= 0.99) {
      setReplayTimestamp(null);
      renderMarkers(null);
    } else {
      const rangeMs = getRangeMs();
      const ts = Date.now() - rangeMs + val * rangeMs;
      setReplayTimestamp(ts);
      renderMarkers(ts);
    }
  }, [getRangeMs, renderMarkers]);

  const handleRangeChange = useCallback((r: TimeRange) => {
    setTimeRange(r);
    setIsPlaying(false);
    setReplayPosition(1);
    setReplayTimestamp(null);
    hasFittedBoundsRef.current = false; // re-fit bounds on range change
  }, []);

  const togglePlay = useCallback(() => {
    setIsPlaying(prev => {
      if (!prev) setReplayPosition(0);
      return !prev;
    });
  }, []);

  const sliderLabel = replayTimestamp
    ? new Date(replayTimestamp).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })
    : 'Live';

  return (
    <div className={`map-wrapper${isActive ? ' active' : ''}`} aria-hidden={!isActive}>
      {/* Map container first for immediate visibility */}
      <div id="mapContainer" className="map-container" ref={mapContainerRef} />

      {/* Timeline bar - compact overlay at bottom */}
      <div className="map-timeline">
        <div className="timeline-controls">
          {/* Play / pause */}
          <button
            className={`timeline-play-btn${isPlaying ? ' playing' : ''}`}
            onClick={togglePlay}
            aria-label={isPlaying ? 'Pausa' : 'Spela upp'}
            title={isPlaying ? 'Pausa' : 'Replay'}
          >
            {isPlaying ? (
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
            ) : (
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="6,3 20,12 6,21"/></svg>
            )}
          </button>

          {/* Slider */}
          <div className="timeline-slider-wrap">
            <input
              type="range"
              className="timeline-slider"
              min="0"
              max="1"
              step="0.005"
              value={replayPosition}
              onChange={e => handleSlider(parseFloat(e.target.value))}
              aria-label="Tidslinje"
            />
            <div className="timeline-slider-fill" style={{ width: `${replayPosition * 100}%` }} />
          </div>

          {/* Time label */}
          <span className={`timeline-label${replayTimestamp ? '' : ' live'}`}>
            {!replayTimestamp && <span className="live-dot" />}
            {sliderLabel}
          </span>

          {/* Event counter */}
          <span className="timeline-counter">{visibleCount}</span>

          {/* Range buttons */}
          <div className="timeline-range-selector">
            {TIME_RANGES.map(r => (
              <button
                key={r.key}
                className={`timeline-range-btn${timeRange === r.key ? ' active' : ''}`}
                onClick={() => handleRangeChange(r.key)}
                aria-pressed={timeRange === r.key}
              >
                {r.label}
              </button>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

export default memo(EventMapInner);
