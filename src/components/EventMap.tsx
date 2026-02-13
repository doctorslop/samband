'use client';

import { useEffect, useRef, useCallback, useState, memo } from 'react';
import { FormattedEvent } from '@/types';

interface EventMapProps {
  events: FormattedEvent[];
  isActive: boolean;
}

type TimeRange = '24h' | '48h' | '72h';
type EventCategoryKey = 'all' | 'violence' | 'theft' | 'traffic' | 'fire' | 'other';

interface EventCategory {
  key: EventCategoryKey;
  label: string;
  emoji: string;
}

const TIME_RANGES: { key: TimeRange; label: string; ms: number }[] = [
  { key: '24h', label: '24 tim', ms: 24 * 60 * 60 * 1000 },
  { key: '48h', label: '48 tim', ms: 48 * 60 * 60 * 1000 },
  { key: '72h', label: '72 tim', ms: 72 * 60 * 60 * 1000 },
];

const EVENT_CATEGORIES: EventCategory[] = [
  { key: 'all', label: 'Alla', emoji: '🧭' },
  { key: 'violence', label: 'Våld', emoji: '⚠️' },
  { key: 'theft', label: 'Stöld', emoji: '🔓' },
  { key: 'traffic', label: 'Trafik', emoji: '🚗' },
  { key: 'fire', label: 'Brand', emoji: '🔥' },
  { key: 'other', label: 'Övrigt', emoji: '📍' },
];

const REPLAY_STEP_MS = 5 * 60 * 1000;
const REPLAY_INTERVAL_MS = 80;

// Only group events at effectively the same spot (within ~2 km).
const PROXIMITY_THRESHOLD = 0.02;

// Tiny nudge between co-located markers (~1-2 px at zoom 5).
// At city zoom they separate clearly; at country zoom they pile up,
// which is the expected behaviour for a dense metro area.
const MIN_GAP_DEG = 0.035;

// Hard cap — never displace more than ~8 km from the real position.
const MAX_FAN_RADIUS = 0.07;
const CLUSTER_ZOOM_THRESHOLD = 6;

const SWEDEN_OUTLINE: [number, number][] = [
  [55.35, 12.45],
  [56.2, 13.2],
  [57.0, 12.7],
  [57.9, 11.6],
  [58.9, 11.2],
  [59.8, 11.5],
  [60.8, 12.3],
  [61.9, 13.2],
  [62.8, 14.6],
  [63.7, 16.1],
  [64.8, 17.8],
  [65.8, 19.6],
  [66.9, 21.0],
  [67.9, 22.4],
  [68.8, 23.6],
  [68.7, 20.8],
  [67.9, 19.0],
  [66.8, 18.0],
  [65.5, 17.1],
  [64.0, 16.4],
  [62.6, 15.6],
  [61.3, 14.8],
  [60.1, 14.1],
  [58.9, 13.8],
  [57.5, 13.2],
  [56.4, 13.0],
  [55.35, 12.45],
];

interface ClusterBucket {
  latSum: number;
  lngSum: number;
  events: FormattedEvent[];
}

/**
 * Pre-compute display positions so co-located markers (same city block)
 * get fanned out slightly.  Markers at distinct locations keep their
 * real GPS coordinates—overlaps at the country-wide zoom are acceptable.
 */
function computeMarkerPositions(events: FormattedEvent[]): Map<number, [number, number]> {
  const positions = new Map<number, [number, number]>();

  // Parse coordinates once
  const parsed: { id: number; lat: number; lng: number }[] = [];
  for (const e of events) {
    if (!e.gps || e.id === null) continue;
    const [lat, lng] = e.gps.split(',').map(Number);
    if (!isNaN(lat) && !isNaN(lng)) parsed.push({ id: e.id, lat, lng });
  }

  if (parsed.length === 0) return positions;

  // --- Step 1: cluster only near-identical locations ---
  const used = new Set<number>();
  const clusters: { indices: number[]; cLat: number; cLng: number }[] = [];

  for (let i = 0; i < parsed.length; i++) {
    if (used.has(i)) continue;
    used.add(i);
    const members = [i];
    let cLat = parsed[i].lat;
    let cLng = parsed[i].lng;

    for (let j = i + 1; j < parsed.length; j++) {
      if (used.has(j)) continue;
      const dLat = parsed[j].lat - cLat;
      const dLng = parsed[j].lng - cLng;
      if (dLat * dLat + dLng * dLng < PROXIMITY_THRESHOLD * PROXIMITY_THRESHOLD) {
        members.push(j);
        used.add(j);
        // Update running centroid
        cLat = 0; cLng = 0;
        for (const idx of members) { cLat += parsed[idx].lat; cLng += parsed[idx].lng; }
        cLat /= members.length; cLng /= members.length;
      }
    }

    clusters.push({ indices: members, cLat, cLng });
  }

  // --- Step 2: place markers ---
  for (const { indices, cLat, cLng } of clusters) {
    if (indices.length === 1) {
      const p = parsed[indices[0]];
      positions.set(p.id, [p.lat, p.lng]);
    } else {
      // Fan out in a small circle, capped so dots stay near the real spot
      const idealRadius = (indices.length * MIN_GAP_DEG) / (2 * Math.PI);
      const fanRadius = Math.min(Math.max(MIN_GAP_DEG, idealRadius), MAX_FAN_RADIUS);

      for (let i = 0; i < indices.length; i++) {
        const angle = (2 * Math.PI * i) / indices.length - Math.PI / 2;
        positions.set(parsed[indices[i]].id, [
          cLat + fanRadius * Math.cos(angle),
          cLng + fanRadius * Math.sin(angle),
        ]);
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

function getEventCategory(type: string): EventCategoryKey {
  const t = type.toLowerCase();

  if (
    t.includes('misshandel') ||
    t.includes('mord') ||
    t.includes('dråp') ||
    t.includes('vald') ||
    t.includes('våld') ||
    t.includes('rån') ||
    t.includes('ran') ||
    t.includes('olaga hot') ||
    t.includes('ofredande')
  ) {
    return 'violence';
  }

  if (
    t.includes('stöld') ||
    t.includes('stold') ||
    t.includes('inbrott') ||
    t.includes('bedrägeri') ||
    t.includes('bedrageri')
  ) {
    return 'theft';
  }

  if (
    t.includes('trafik') ||
    t.includes('olycka') ||
    t.includes('rattfylleri') ||
    t.includes('väg') ||
    t.includes('vag')
  ) {
    return 'traffic';
  }

  if (t.includes('brand') || t.includes('eld')) {
    return 'fire';
  }

  return 'other';
}

function matchesCategory(event: FormattedEvent, selectedCategories: EventCategoryKey[]): boolean {
  if (selectedCategories.includes('all')) return true;
  return selectedCategories.includes(getEventCategory(event.type || ''));
}

function getClusterCellSize(zoom: number): number {
  if (zoom <= 4) return 1.2;
  if (zoom <= 5) return 0.8;
  return 0.5;
}

function clusterEvents(events: FormattedEvent[], zoom: number): FormattedEvent[][] {
  if (zoom > CLUSTER_ZOOM_THRESHOLD) {
    return events.map(event => [event]);
  }

  const cellSize = getClusterCellSize(zoom);
  const buckets = new Map<string, ClusterBucket>();

  for (const event of events) {
    if (!event.gps) continue;
    const [lat, lng] = event.gps.split(',').map(Number);
    if (isNaN(lat) || isNaN(lng)) continue;

    const key = `${Math.floor(lat / cellSize)}:${Math.floor(lng / cellSize)}`;
    const bucket = buckets.get(key) ?? { latSum: 0, lngSum: 0, events: [] };
    bucket.latSum += lat;
    bucket.lngSum += lng;
    bucket.events.push(event);
    buckets.set(key, bucket);
  }

  return Array.from(buckets.values()).map(bucket => bucket.events);
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

  const [timeRange, setTimeRange] = useState<TimeRange>('24h');
  const [isPlaying, setIsPlaying] = useState(false);
  const [replayPosition, setReplayPosition] = useState(1);
  const [replayTimestamp, setReplayTimestamp] = useState<number | null>(null);
  const [mapReady, setMapReady] = useState(false);
  const [visibleCount, setVisibleCount] = useState(0);
  const [selectedCategories, setSelectedCategories] = useState<EventCategoryKey[]>(['all']);

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

  const addClusterMarker = useCallback((eventsInCluster: FormattedEvent[], now: number, rangeMs: number) => {
    const L = leafletRef.current;
    if (!L || !markersLayerRef.current || eventsInCluster.length === 0) return;

    let latSum = 0;
    let lngSum = 0;
    let latestTs = 0;

    for (const event of eventsInCluster) {
      const [lat, lng] = event.gps.split(',').map(Number);
      if (isNaN(lat) || isNaN(lng)) continue;
      latSum += lat;
      lngSum += lng;
      const ts = new Date(event.date?.iso || event.datetime).getTime();
      latestTs = Math.max(latestTs, ts);
    }

    const count = eventsInCluster.length;
    const center: [number, number] = [latSum / count, lngSum / count];
    const ageFrac = latestTs ? Math.min(1, (now - latestTs) / rangeMs) : 1;
    const radius = Math.min(22, 11 + Math.log2(count + 1) * 4);

    const clusterMarker = L.circleMarker(center, {
      radius,
      fillColor: '#fbbf24',
      color: '#fff',
      weight: 2,
      fillOpacity: 0.28 - ageFrac * 0.1,
      opacity: 0.9,
      className: 'map-cluster-marker',
    });

    clusterMarker.bindTooltip(String(count), {
      permanent: true,
      direction: 'center',
      className: 'map-cluster-count',
      opacity: 1,
    });

    clusterMarker.bindPopup(`
      <div class="map-popup">
        <span class="badge" style="background:#fbbf2420;color:#fbbf24">🧩 Kluster</span>
        <h3>${count} händelser i området</h3>
        <p>Zooma in för att se varje händelse separat.</p>
      </div>
    `);

    markersLayerRef.current.addLayer(clusterMarker);
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
      return !isNaN(ts) && ts >= windowStart && ts <= now && matchesCategory(e, selectedCategories);
    });

    const zoom = map.getZoom();
    const grouped = clusterEvents(visible, zoom);
    const shouldCluster = zoom <= CLUSTER_ZOOM_THRESHOLD;

    for (const group of grouped) {
      if (shouldCluster && group.length > 1) {
        addClusterMarker(group, now, rangeMs);
        continue;
      }

      const positions = computeMarkerPositions(group);
      for (const event of group) {
        const pos = event.id !== null ? positions.get(event.id) : undefined;
        addMarker(event, now, rangeMs, pos);
      }
    }

    const count = markersLayerRef.current.getLayers().length;
    setVisibleCount(visible.length);
    updateInfoBadge(visible.length, cutoffTs, rangeOverride);

    // Fit bounds once on first meaningful render
    if (!hasFittedBoundsRef.current && !cutoffTs && count > 0) {
      map.fitBounds(markersLayerRef.current.getBounds(), { padding: [40, 40] });
      hasFittedBoundsRef.current = true;
    }
  }, [getRangeMs, clearMarkers, addMarker, addClusterMarker, updateInfoBadge, selectedCategories]);

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

      map.createPane('swedenMaskPane');
      map.getPane('swedenMaskPane')!.style.zIndex = '350';
      map.createPane('swedenBorderPane');
      map.getPane('swedenBorderPane')!.style.zIndex = '450';

      const tileLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 18,
      }).addTo(map);

      // Fallback: if primary tiles fail, switch to OSM
      let hasFallback = false;
      tileLayer.on('tileerror', () => {
        if (hasFallback) return;
        hasFallback = true;
        map.removeLayer(tileLayer);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; OpenStreetMap',
          maxZoom: 19,
        }).addTo(map);
      });

      const worldMask = [
        [[-90, -180], [-90, 180], [90, 180], [90, -180], [-90, -180]],
        SWEDEN_OUTLINE,
      ] as [number, number][][];

      L.polygon(worldMask, {
        pane: 'swedenMaskPane',
        stroke: false,
        fillColor: '#040810',
        fillOpacity: 0.42,
        fillRule: 'evenodd',
        interactive: false,
      }).addTo(map);

      L.polygon(SWEDEN_OUTLINE, {
        pane: 'swedenBorderPane',
        color: '#fbbf24',
        weight: 2,
        opacity: 0.95,
        fill: false,
        interactive: false,
      }).addTo(map);

      mapRef.current = map;
      setMapReady(true);

      map.on('zoomend', () => {
        renderMarkers(replayTimestamp);
      });

      // Make sure tiles render properly — double-nudge for mobile browsers
      setTimeout(() => map.invalidateSize(), 200);
      setTimeout(() => map.invalidateSize(), 600);
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
  }, [isActive, renderMarkers, replayTimestamp]);

  // --- Render markers when data/range changes (non-playing) ---
  useEffect(() => {
    if (mapReady && !isPlaying) {
      renderMarkers(replayTimestamp);
    }
  }, [mapReady, events, timeRange, renderMarkers, isPlaying, replayTimestamp]);

  // --- Fix tile sizing on tab switch and orientation changes ---
  useEffect(() => {
    if (isActive && mapRef.current) {
      requestAnimationFrame(() => mapRef.current?.invalidateSize());
      // Delayed nudge for slower mobile layout reflows
      const t = setTimeout(() => mapRef.current?.invalidateSize(), 300);
      return () => clearTimeout(t);
    }
  }, [isActive]);

  // Re-layout on window resize / orientation change (mobile)
  useEffect(() => {
    if (!mapReady) return;
    const onResize = () => {
      requestAnimationFrame(() => mapRef.current?.invalidateSize());
    };
    window.addEventListener('resize', onResize);
    window.addEventListener('orientationchange', onResize);
    return () => {
      window.removeEventListener('resize', onResize);
      window.removeEventListener('orientationchange', onResize);
    };
  }, [mapReady]);

  // --- Replay engine: animated dot-by-dot playback ---
  useEffect(() => {
    if (!isPlaying || !mapReady) {
      if (replayIntervalRef.current) {
        clearInterval(replayIntervalRef.current);
        replayIntervalRef.current = null;
      }
      return;
    }

    const rangeMs = getRangeMs();
    const now = Date.now();
    const start = now - rangeMs;
    let pos = 0;
    renderMarkers(start);

    replayIntervalRef.current = setInterval(() => {
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
      renderMarkers(ts);
    }, REPLAY_INTERVAL_MS);

    return () => {
      if (replayIntervalRef.current) {
        clearInterval(replayIntervalRef.current);
        replayIntervalRef.current = null;
      }
    };
  }, [isPlaying, mapReady, getRangeMs, renderMarkers, selectedCategories]);

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

  const toggleCategory = useCallback((key: EventCategoryKey) => {
    setSelectedCategories(prev => {
      if (key === 'all') return ['all'];

      const withoutAll = prev.filter(c => c !== 'all');
      const isActive = withoutAll.includes(key);
      const next = isActive ? withoutAll.filter(c => c !== key) : [...withoutAll, key];

      return next.length > 0 ? next : ['all'];
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
        <div className="timeline-chip-row" role="group" aria-label="Filtrera händelser efter typ">
          {EVENT_CATEGORIES.map(category => {
            const isActive = selectedCategories.includes(category.key);
            return (
              <button
                key={category.key}
                className={`timeline-chip${isActive ? ' active' : ''}`}
                onClick={() => toggleCategory(category.key)}
                aria-pressed={isActive}
              >
                <span aria-hidden="true">{category.emoji}</span>
                <span>{category.label}</span>
              </button>
            );
          })}
        </div>

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
