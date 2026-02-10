'use client';

import { useState, useCallback, Suspense, useMemo, useEffect } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Header from './Header';
import Filters from './Filters';
import EventList from './EventList';
import EventMap from './EventMap';
import StatsView from './StatsView';
import Footer from './Footer';
import ScrollToTop from './ScrollToTop';
import MapModal from './MapModal';
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts';
import { FormattedEvent, Statistics } from '@/types';

export type Density = 'comfortable' | 'compact' | 'stream';


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
  highlightedEventId: number | null;
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
  highlightedEventId,
}: ClientAppProps) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [currentView, setCurrentView] = useState(initialView);
  const [density, setDensity] = useState<Density>('comfortable');
  const [expandSummaries, setExpandSummaries] = useState(false);
  const [displayedCount, setDisplayedCount] = useState(initialEvents.length);
  const [lastChecked, setLastChecked] = useState<Date>(new Date());
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

  // Load preferences from localStorage on mount
  useEffect(() => {
    try {
      const saved = localStorage.getItem('density');
      if (saved === 'comfortable' || saved === 'compact' || saved === 'stream') {
        setDensity(saved);
      }
      const savedExpand = localStorage.getItem('expandSummaries');
      if (savedExpand === 'true') {
        setExpandSummaries(true);
      }
    } catch {
      // localStorage unavailable
    }
  }, []);

  const handleDensityChange = useCallback((d: Density) => {
    setDensity(d);
    try {
      localStorage.setItem('density', d);
    } catch {
      // localStorage unavailable
    }
  }, []);

  const handleExpandSummariesChange = useCallback((expand: boolean) => {
    setExpandSummaries(expand);
    try {
      localStorage.setItem('expandSummaries', String(expand));
    } catch {
      // localStorage unavailable
    }
  }, []);

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

  // Navigate to home: reset view to list and clear all filters
  const handleLogoClick = useCallback(() => {
    setCurrentView('list');
    router.push('/', { scroll: false });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, [router]);

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
      <div className={`container view-${currentView} density-${density}${expandSummaries ? ' summaries-expanded' : ''}`}>
        <Header currentView={currentView} onViewChange={handleViewChange} onLogoClick={handleLogoClick} density={density} onDensityChange={handleDensityChange} expandSummaries={expandSummaries} onExpandSummariesChange={handleExpandSummariesChange} />

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
                highlightedEventId={highlightedEventId}
                onEventCountChange={setDisplayedCount}
                onLastCheckedChange={setLastChecked}
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

        <Footer totalStored={stats.totalStored} total={stats.total} shown={displayedCount} lastChecked={lastChecked} />
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
