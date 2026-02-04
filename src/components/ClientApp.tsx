'use client';

import { useState, useCallback, Suspense, useRef, useMemo } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import dynamic from 'next/dynamic';
import Header from './Header';
import Filters from './Filters';
import EventList from './EventList';
import StatsView from './StatsView';
import Footer from './Footer';
import ScrollToTop from './ScrollToTop';
import MapModal from './MapModal';
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts';
import { FormattedEvent, Statistics } from '@/types';

// Dynamic import for EventMap to avoid SSR issues with Leaflet
const EventMap = dynamic(() => import('./EventMap'), {
  ssr: false,
  loading: () => (
    <div className="map-wrapper active">
      <div className="map-container map-loading">
        <div className="map-loading-content">
          <div className="spinner" />
          <span>Laddar karta...</span>
        </div>
      </div>
    </div>
  ),
});

interface ClientAppProps {
  initialEvents: FormattedEvent[];
  mapEvents: FormattedEvent[];
  hasMore: boolean;
  locations: string[];
  types: string[];
  stats: Statistics;
  filters: {
    location: string;
    type: string;
    search: string;
  };
  initialView: string;
}

function ClientAppContent({
  initialEvents,
  mapEvents,
  hasMore,
  locations,
  types,
  stats,
  filters,
  initialView,
}: ClientAppProps) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [currentView, setCurrentView] = useState(initialView);
  const [mapModal, setMapModal] = useState<{
    isOpen: boolean;
    lat: number;
    lng: number;
    location: string;
  }>({
    isOpen: false,
    lat: 0,
    lng: 0,
    location: '',
  });

  const handleViewChange = useCallback(
    (view: string) => {
      setCurrentView(view);
      const params = new URLSearchParams(searchParams.toString());
      params.set('view', view);
      router.push(`/?${params.toString()}`, { scroll: false });
    },
    [router, searchParams]
  );

  const handleShowMap = useCallback((lat: number, lng: number, location: string) => {
    setMapModal({ isOpen: true, lat, lng, location });
  }, []);

  const handleCloseMapModal = useCallback(() => {
    setMapModal((prev) => ({ ...prev, isOpen: false }));
  }, []);

  const handleTypeClick = useCallback(
    (type: string) => {
      setCurrentView('list');
      const params = new URLSearchParams();
      params.set('view', 'list');
      params.set('type', type);
      router.push(`/?${params.toString()}`, { scroll: false });
    },
    [router]
  );

  const handleLocationClick = useCallback(
    (location: string) => {
      setCurrentView('list');
      const params = new URLSearchParams();
      params.set('view', 'list');
      params.set('location', location);
      router.push(`/?${params.toString()}`, { scroll: false });
    },
    [router]
  );

  // Focus search input
  const focusSearch = useCallback(() => {
    const searchInput = document.querySelector('.search-input') as HTMLInputElement;
    if (searchInput) {
      searchInput.focus();
      searchInput.select();
    }
  }, []);

  // Scroll to top
  const scrollToTop = useCallback(() => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, []);

  // Keyboard shortcuts handlers - memoized to prevent re-renders
  const shortcutHandlers = useMemo(() => ({
    onSearch: focusSearch,
    onEscape: handleCloseMapModal,
    onListView: () => handleViewChange('list'),
    onMapView: () => handleViewChange('map'),
    onStatsView: () => handleViewChange('stats'),
    onScrollTop: scrollToTop,
  }), [focusSearch, handleCloseMapModal, handleViewChange, scrollToTop]);

  // Register keyboard shortcuts
  useKeyboardShortcuts(shortcutHandlers);

  return (
    <>
      <div className={`container view-${currentView}`}>
        <Header currentView={currentView} onViewChange={handleViewChange} />

        {currentView !== 'map' && currentView !== 'stats' && (
          <Filters
            locations={locations}
            types={types}
            currentView={currentView}
            filters={filters}
          />
        )}

        <main className="main-content">
          <div className="content-area">
            {currentView === 'list' && (
              <EventList
                initialEvents={initialEvents}
                initialHasMore={hasMore}
                filters={filters}
                currentView={currentView}
                onShowMap={handleShowMap}
              />
            )}

            <EventMap events={mapEvents} isActive={currentView === 'map'} />
          </div>

          <StatsView
              stats={stats}
              isActive={currentView === 'stats'}
              onTypeClick={handleTypeClick}
              onLocationClick={handleLocationClick}
            />
        </main>

        <Footer total={stats.total} shown={initialEvents.length} />
      </div>

      <ScrollToTop />

      <MapModal
        isOpen={mapModal.isOpen}
        lat={mapModal.lat}
        lng={mapModal.lng}
        location={mapModal.location}
        onClose={handleCloseMapModal}
      />
    </>
  );
}

export default function ClientApp(props: ClientAppProps) {
  return (
    <Suspense fallback={<div className="container"><div className="spinner" /></div>}>
      <ClientAppContent {...props} />
    </Suspense>
  );
}
