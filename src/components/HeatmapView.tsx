'use client';

import { useEffect, useRef, useCallback, memo } from 'react';
import { FormattedEvent } from '@/types';

interface HeatmapViewProps {
  events: FormattedEvent[];
  isActive: boolean;
}

// Extend Leaflet types for heat plugin
declare global {
  interface Window {
    L: typeof import('leaflet') & {
      heatLayer: (
        latlngs: [number, number, number][],
        options?: {
          radius?: number;
          blur?: number;
          maxZoom?: number;
          max?: number;
          minOpacity?: number;
          gradient?: Record<number, string>;
        }
      ) => L.Layer;
    };
  }
}

function HeatmapView({ events, isActive }: HeatmapViewProps) {
  const mapRef = useRef<L.Map | null>(null);
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const heatLayerRef = useRef<L.Layer | null>(null);
  const infoControlRef = useRef<L.Control | null>(null);
  const initializingRef = useRef(false);
  const leafletRef = useRef<typeof import('leaflet') | null>(null);
  const heatPluginLoadedRef = useRef(false);
  const eventsRef = useRef<FormattedEvent[]>(events);

  // Update events ref when events change
  eventsRef.current = events;

  // Load leaflet.heat plugin dynamically
  const loadHeatPlugin = useCallback((): Promise<void> => {
    return new Promise((resolve, reject) => {
      // Check if already loaded
      if (heatPluginLoadedRef.current) {
        resolve();
        return;
      }
      // Check if heatLayer exists on window.L
      if (typeof window !== 'undefined' && window.L && 'heatLayer' in window.L) {
        heatPluginLoadedRef.current = true;
        resolve();
        return;
      }

      const script = document.createElement('script');
      script.src = 'https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js';
      script.async = true;
      script.onload = () => {
        heatPluginLoadedRef.current = true;
        resolve();
      };
      script.onerror = () => reject(new Error('Failed to load leaflet.heat'));
      document.head.appendChild(script);
    });
  }, []);

  // Create heatmap from events
  const updateHeatmap = useCallback(() => {
    const L = leafletRef.current;
    const map = mapRef.current;
    if (!L || !map || !window.L?.heatLayer) return;

    // Remove existing heat layer
    if (heatLayerRef.current) {
      map.removeLayer(heatLayerRef.current);
    }

    // Remove old info control
    if (infoControlRef.current) {
      map.removeControl(infoControlRef.current);
    }

    const now = new Date();
    const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);

    // Get events from last 7 days with GPS coordinates
    const recentEvents = eventsRef.current.filter((e) => {
      const eventTimeStr = e.date?.iso || e.datetime;
      if (!eventTimeStr || !e.gps) return false;
      const eventDate = new Date(eventTimeStr);
      return !isNaN(eventDate.getTime()) && eventDate >= weekAgo && eventDate <= now;
    });

    // Convert events to heatmap data points [lat, lng, intensity]
    const heatData: [number, number, number][] = [];

    recentEvents.forEach((e) => {
      if (e.gps) {
        const [lat, lng] = e.gps.split(',').map(Number);
        if (!isNaN(lat) && !isNaN(lng)) {
          // Calculate intensity based on recency (more recent = higher intensity)
          const eventDate = new Date(e.date?.iso || e.datetime);
          const hoursAgo = (now.getTime() - eventDate.getTime()) / (1000 * 60 * 60);
          const intensity = Math.max(0.3, 1 - (hoursAgo / (7 * 24))); // Scale from 0.3 to 1.0
          heatData.push([lat, lng, intensity]);
        }
      }
    });

    // Create heat layer with custom gradient
    heatLayerRef.current = window.L.heatLayer(heatData, {
      radius: 25,
      blur: 15,
      maxZoom: 10,
      max: 1.0,
      minOpacity: 0.4,
      gradient: {
        0.0: '#0a1628',
        0.2: '#1e3a5f',
        0.4: '#3b82f6',
        0.6: '#f59e0b',
        0.8: '#ef4444',
        1.0: '#dc2626',
      },
    });

    heatLayerRef.current.addTo(map);

    // Add info control
    const InfoControl = L.Control.extend({
      onAdd: function () {
        const div = L.DomUtil.create('div', 'map-info heatmap-info');
        div.innerHTML = `
          <div class="map-info-content">
            <div class="heatmap-legend">
              <span class="legend-gradient"></span>
              <span class="legend-labels">
                <span>Låg</span>
                <span>Hög</span>
              </span>
            </div>
            <div style="margin-top: 8px;">
              ${heatData.length} händelser<br>
              <small>senaste 7 dagarna</small>
            </div>
          </div>
        `;
        return div;
      },
    });

    infoControlRef.current = new InfoControl({ position: 'topright' });
    infoControlRef.current.addTo(map);
  }, []);

  // Initialize map only once
  useEffect(() => {
    if (!isActive) return;
    if (mapRef.current || initializingRef.current) return;

    initializingRef.current = true;

    const initMap = async () => {
      try {
        const L = await import('leaflet');
        leafletRef.current = L;

        // Load heat plugin
        await loadHeatPlugin();

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

        // Add heatmap after map is ready
        updateHeatmap();

        // Invalidate size after a short delay to ensure container is properly sized
        setTimeout(() => {
          map.invalidateSize();
        }, 100);
      } catch (error) {
        console.error('Failed to initialize heatmap:', error);
        initializingRef.current = false;
      }
    };

    initMap();

    return () => {
      if (mapRef.current) {
        mapRef.current.remove();
        mapRef.current = null;
      }
      heatLayerRef.current = null;
      infoControlRef.current = null;
      leafletRef.current = null;
      initializingRef.current = false;
    };
  }, [isActive, updateHeatmap, loadHeatPlugin]);

  // Update heatmap when events change (but don't reinitialize the map)
  useEffect(() => {
    if (mapRef.current && leafletRef.current && heatPluginLoadedRef.current) {
      updateHeatmap();
    }
  }, [events, updateHeatmap]);

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
      className={`map-wrapper heatmap-wrapper${isActive ? ' active' : ''}`}
      aria-hidden={!isActive}
    >
      <div
        id="heatmapContainer"
        className="map-container heatmap-container"
        ref={mapContainerRef}
      />
    </div>
  );
}

// Memoize to prevent unnecessary re-renders from parent
export default memo(HeatmapView);
