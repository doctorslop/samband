'use client';

import { useEffect, useRef, useState } from 'react';
import { FormattedEvent } from '@/types';

interface EventMapProps {
  events: FormattedEvent[];
  isActive: boolean;
}

export default function EventMap({ events, isActive }: EventMapProps) {
  const mapRef = useRef<L.Map | null>(null);
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const [initialized, setInitialized] = useState(false);

  useEffect(() => {
    if (!isActive || initialized) return;

    const initMap = async () => {
      const L = await import('leaflet');
      await import('leaflet/dist/leaflet.css');

      if (!mapContainerRef.current) return;

      const map = L.map(mapContainerRef.current).setView([62.5, 17.5], 5);
      L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 18,
      }).addTo(map);

      // Filter events to last 24 hours
      const now = new Date();
      const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);

      const recentEvents = events.filter((e) => {
        const eventTimeStr = e.date?.iso || e.datetime;
        if (!eventTimeStr) return false;
        const eventDate = new Date(eventTimeStr);
        return !isNaN(eventDate.getTime()) && eventDate >= yesterday && eventDate <= now;
      });

      const markers = L.layerGroup();
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

            m.bindPopup(`
              <div class="map-popup">
                <span class="badge" style="background:${e.color}20;color:${e.color}">${e.icon} ${e.type}</span>
                <div class="popup-time">🕐 ${relTime}</div>
                <h3>${e.name}</h3>
                <p>${summaryPreview}</p>
                <p><strong>📍 ${e.location}</strong></p>
                <div class="popup-links">
                  <a href="${googleMapsUrl}" target="_blank" rel="noopener noreferrer">🗺️ Google Maps</a>
                  ${e.url ? `<a href="https://polisen.se${e.url}" target="_blank" rel="noopener noreferrer nofollow">📄 Läs mer</a>` : ''}
                </div>
              </div>
            `);

            markers.addLayer(m);
          }
        }
      });

      map.addLayer(markers);

      // Add info control
      const InfoControl = L.Control.extend({
        onAdd: function () {
          const div = L.DomUtil.create('div', 'map-info');
          div.innerHTML = `<div class="map-info-content">📍 ${eventCount} händelser<br><small>senaste 24 timmarna</small></div>`;
          return div;
        },
      });

      new InfoControl({ position: 'topright' }).addTo(map);

      // Fit bounds if we have markers
      if (markers.getLayers().length) {
        map.fitBounds(markers.getBounds(), { padding: [40, 40] });
      }

      mapRef.current = map;
      setInitialized(true);
    };

    initMap();
  }, [isActive, initialized, events]);

  return (
    <div id="mapContainer" className={`map-container${isActive ? ' active' : ''}`}>
      <div id="map" ref={mapContainerRef} style={{ height: '100%' }} />
    </div>
  );
}
