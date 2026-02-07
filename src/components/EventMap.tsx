'use client';

import { useEffect, useRef, useCallback, memo } from 'react';
import { FormattedEvent } from '@/types';

interface EventMapProps {
  events: FormattedEvent[];
  isActive: boolean;
}

function EventMap({ events, isActive }: EventMapProps) {
  const mapRef = useRef<L.Map | null>(null);
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const markersLayerRef = useRef<L.FeatureGroup | null>(null);
  const infoControlRef = useRef<L.Control | null>(null);
  const initializingRef = useRef(false);
  const leafletRef = useRef<typeof import('leaflet') | null>(null);
  const eventsRef = useRef<FormattedEvent[]>(events);

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

  // Create markers from events
  const updateMarkers = useCallback(() => {
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

    const now = new Date();
    const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);

    const recentEvents = eventsRef.current.filter((e) => {
      const eventTimeStr = e.date?.iso || e.datetime;
      if (!eventTimeStr) return false;
      const eventDate = new Date(eventTimeStr);
      return !isNaN(eventDate.getTime()) && eventDate >= yesterday && eventDate <= now;
    });

    let eventCount = 0;

    recentEvents.forEach((e) => {
      if (e.gps) {
        const [lat, lng] = e.gps.split(',').map(Number);
        if (!isNaN(lat) && !isNaN(lng)) {
          eventCount++;

          const eventTimeStr = e.date?.iso || e.datetime;
          const eventDate = new Date(eventTimeStr);
          const diffMs = now.getTime() - eventDate.getTime();
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

          const m = L.circleMarker([lat, lng], {
            radius: 8,
            fillColor: e.color,
            color: '#fff',
            weight: 2,
            opacity: 1,
            fillOpacity: 0.85,
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
              <div class="popup-time">🕐 ${relTime}</div>
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
    const InfoControl = L.Control.extend({
      onAdd: function () {
        const div = L.DomUtil.create('div', 'map-info');
        div.innerHTML = `<div class="map-info-content">📍 ${eventCount} händelser<br><small>senaste 24 timmarna</small></div>`;
        return div;
      },
    });

    infoControlRef.current = new InfoControl({ position: 'topright' });
    infoControlRef.current.addTo(map);

    // Fit bounds if we have markers
    if (markersLayerRef.current.getLayers().length > 0) {
      map.fitBounds(markersLayerRef.current.getBounds(), { padding: [40, 40] });
    }
  }, [escapeHtml]);

  // Initialize map only once
  useEffect(() => {
    if (!isActive) return;
    if (mapRef.current || initializingRef.current) return;

    initializingRef.current = true;

    const initMap = async () => {
      const L = await import('leaflet');
      // Note: Leaflet CSS is loaded via CDN in layout.tsx to avoid webpack issues

      leafletRef.current = L;

      if (!mapContainerRef.current) {
        initializingRef.current = false;
        return;
      }

      // Double-check the container doesn't already have a map
      if (mapRef.current) {
        initializingRef.current = false;
        return;
      }

      // Create map with Sweden centered
      const map = L.map(mapContainerRef.current, {
        center: [62.5, 17.5],
        zoom: 5,
        zoomControl: true,
        attributionControl: true,
      });

      L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 18,
      }).addTo(map);

      mapRef.current = map;
      initializingRef.current = false;

      // Add markers after map is ready
      updateMarkers();

      // Invalidate size after a short delay to ensure container is properly sized
      setTimeout(() => {
        map.invalidateSize();
      }, 100);
    };

    initMap();

    return () => {
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

  // Update markers when events change (but don't reinitialize the map)
  useEffect(() => {
    if (mapRef.current && leafletRef.current) {
      updateMarkers();
    }
  }, [events, updateMarkers]);

  // Invalidate map size when becoming active (handles visibility changes)
  useEffect(() => {
    if (isActive && mapRef.current) {
      // Use requestAnimationFrame to ensure the container is visible first
      requestAnimationFrame(() => {
        mapRef.current?.invalidateSize();
      });
    }
  }, [isActive]);

  return (
    <div
      className={`map-wrapper${isActive ? ' active' : ''}`}
      aria-hidden={!isActive}
    >
      <div
        id="mapContainer"
        className="map-container"
        ref={mapContainerRef}
      />
    </div>
  );
}

// Memoize to prevent unnecessary re-renders from parent
export default memo(EventMap);
