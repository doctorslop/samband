'use client';

import { useEffect, useRef, useState, memo } from 'react';
import { FormattedEvent } from '@/types';
import L from 'leaflet';

interface HeatmapViewProps {
  events: FormattedEvent[];
  isActive: boolean;
}

// Extend Window and Leaflet types for heat plugin
declare global {
  interface Window {
    L: typeof L;
  }
}

interface HeatLayerInstance extends L.Layer {
  setLatLngs: (latlngs: [number, number, number][]) => void;
}

type HeatLayerFactory = (latlngs: [number, number, number][], options?: Record<string, unknown>) => HeatLayerInstance;

// Extended L type with heatLayer
interface LeafletWithHeat {
  heatLayer?: HeatLayerFactory;
}

function HeatmapView({ events, isActive }: HeatmapViewProps) {
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<L.Map | null>(null);
  const heatLayerRef = useRef<HeatLayerInstance | null>(null);
  const infoControlRef = useRef<L.Control | null>(null);
  const [isReady, setIsReady] = useState(false);
  const [heatLayerFn, setHeatLayerFn] = useState<HeatLayerFactory | null>(null);

  // Load the heat plugin once
  useEffect(() => {
    // Set window.L for plugins that require it
    if (typeof window !== 'undefined') {
      window.L = L;
    }

    // Load heat plugin script
    const loadHeatPlugin = async () => {
      const LWithHeat = L as unknown as LeafletWithHeat;

      // Check if already loaded
      if (LWithHeat.heatLayer) {
        setHeatLayerFn(() => LWithHeat.heatLayer as HeatLayerFactory);
        setIsReady(true);
        return;
      }

      return new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js';
        script.async = true;
        script.onload = () => {
          const LAfterLoad = L as unknown as LeafletWithHeat;
          if (LAfterLoad.heatLayer) {
            setHeatLayerFn(() => LAfterLoad.heatLayer as HeatLayerFactory);
            setIsReady(true);
            resolve();
          } else {
            reject(new Error('heatLayer not found after loading script'));
          }
        };
        script.onerror = () => reject(new Error('Failed to load leaflet.heat'));
        document.head.appendChild(script);
      });
    };

    loadHeatPlugin().catch(console.error);
  }, []);

  // Initialize map when active and ready
  useEffect(() => {
    if (!isActive || !isReady || !mapContainerRef.current) return;

    // Already initialized
    if (mapInstanceRef.current) {
      mapInstanceRef.current.invalidateSize();
      return;
    }

    // Create map centered on Sweden
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

    mapInstanceRef.current = map;

    // Ensure map is properly sized
    setTimeout(() => {
      map.invalidateSize();
    }, 100);

    return () => {
      if (mapInstanceRef.current) {
        mapInstanceRef.current.remove();
        mapInstanceRef.current = null;
      }
      heatLayerRef.current = null;
      infoControlRef.current = null;
    };
  }, [isActive, isReady]);

  // Update heatmap data when events change
  useEffect(() => {
    const map = mapInstanceRef.current;
    if (!map || !heatLayerFn || !isActive) return;

    // Remove existing layers
    if (heatLayerRef.current) {
      map.removeLayer(heatLayerRef.current);
    }
    if (infoControlRef.current) {
      map.removeControl(infoControlRef.current);
    }

    const now = new Date();
    const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);

    // Filter events with GPS from last 7 days
    const heatData: [number, number, number][] = [];

    events.forEach((e) => {
      if (!e.gps) return;

      const eventTimeStr = e.date?.iso || e.datetime;
      if (!eventTimeStr) return;

      const eventDate = new Date(eventTimeStr);
      if (isNaN(eventDate.getTime()) || eventDate < weekAgo || eventDate > now) return;

      const [lat, lng] = e.gps.split(',').map(Number);
      if (isNaN(lat) || isNaN(lng)) return;

      // Intensity based on recency
      const hoursAgo = (now.getTime() - eventDate.getTime()) / (1000 * 60 * 60);
      const intensity = Math.max(0.3, 1 - (hoursAgo / (7 * 24)));
      heatData.push([lat, lng, intensity]);
    });

    // Create heat layer
    const heatLayer = heatLayerFn(heatData, {
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

    heatLayer.addTo(map);
    heatLayerRef.current = heatLayer;

    // Add legend control
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

    const infoControl = new InfoControl({ position: 'topright' });
    infoControl.addTo(map);
    infoControlRef.current = infoControl;
  }, [events, heatLayerFn, isActive]);

  // Handle visibility changes
  useEffect(() => {
    if (isActive && mapInstanceRef.current) {
      requestAnimationFrame(() => {
        mapInstanceRef.current?.invalidateSize();
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

export default memo(HeatmapView);
