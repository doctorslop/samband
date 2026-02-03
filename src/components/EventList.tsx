'use client';

import { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import EventCard from './EventCard';
import { FormattedEvent } from '@/types';

// Auto-refresh interval: 30 minutes (matches server-side fetch interval)
const AUTO_REFRESH_INTERVAL = 30 * 60 * 1000;

interface EventListProps {
  initialEvents: FormattedEvent[];
  initialHasMore: boolean;
  filters: {
    location: string;
    type: string;
    search: string;
  };
  currentView: string;
  onShowMap?: (lat: number, lng: number, location: string) => void;
}

export default function EventList({
  initialEvents,
  initialHasMore,
  filters,
  currentView,
  onShowMap,
}: EventListProps) {
  const [events, setEvents] = useState<FormattedEvent[]>(initialEvents);
  const [hasMore, setHasMore] = useState(initialHasMore);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [newEventsCount, setNewEventsCount] = useState(0);
  const lastRefreshRef = useRef<number>(Date.now());

  // Create a stable filter key to detect when filters change
  const filterKey = useMemo(
    () => `${filters.location}|${filters.type}|${filters.search}`,
    [filters.location, filters.type, filters.search]
  );

  // Reset state when filters change (new initial events from server)
  useEffect(() => {
    setEvents(initialEvents);
    setHasMore(initialHasMore);
    setPage(1);
    setNewEventsCount(0);
    lastRefreshRef.current = Date.now();
  }, [filterKey, initialEvents, initialHasMore]);

  // Auto-refresh to check for new events every 30 minutes
  useEffect(() => {
    const checkForNewEvents = async () => {
      // Only auto-refresh if user is on the list view and document is visible
      if (currentView !== 'list' || document.hidden) {
        return;
      }

      try {
        const params = new URLSearchParams({
          page: '1',
          location: filters.location,
          type: filters.type,
          search: filters.search,
        });

        const res = await fetch(`/api/events?${params}`);
        const data = await res.json();

        if (data.error || !data.events) {
          return;
        }

        // Check if there are new events by comparing first event IDs
        const currentFirstId = events[0]?.id;
        const newFirstId = data.events[0]?.id;

        if (currentFirstId !== newFirstId && data.events.length > 0) {
          // Count how many new events there are
          const currentIds = new Set(events.map((e) => e.id));
          const newEvents = data.events.filter((e: FormattedEvent) => !currentIds.has(e.id));

          if (newEvents.length > 0) {
            setNewEventsCount(newEvents.length);
          }
        }
      } catch {
        // Silently fail - don't interrupt user experience
      }
    };

    const intervalId = setInterval(checkForNewEvents, AUTO_REFRESH_INTERVAL);

    // Also check when document becomes visible after being hidden
    const handleVisibilityChange = () => {
      if (!document.hidden && Date.now() - lastRefreshRef.current > AUTO_REFRESH_INTERVAL) {
        checkForNewEvents();
      }
    };

    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      clearInterval(intervalId);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [currentView, filters, events]);

  // Refresh events and merge new ones
  const refreshEvents = useCallback(async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams({
        page: '1',
        location: filters.location,
        type: filters.type,
        search: filters.search,
      });

      const res = await fetch(`/api/events?${params}`);
      const data = await res.json();

      if (data.error) {
        console.error(data.error);
        return;
      }

      setEvents(data.events);
      setHasMore(data.hasMore);
      setPage(1);
      setNewEventsCount(0);
      lastRefreshRef.current = Date.now();
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, [filters]);

  const loadMore = useCallback(async () => {
    if (loading || !hasMore) return;

    setLoading(true);
    const nextPage = page + 1;

    try {
      const params = new URLSearchParams({
        page: String(nextPage),
        location: filters.location,
        type: filters.type,
        search: filters.search,
      });

      const res = await fetch(`/api/events?${params}`);
      const data = await res.json();

      if (data.error) {
        console.error(data.error);
        return;
      }

      setEvents(prev => [...prev, ...data.events]);
      setHasMore(data.hasMore);
      setPage(nextPage);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, [loading, hasMore, page, filters]);

  if (events.length === 0) {
    return (
      <section id="eventsGrid" className="events-grid">
        <div className="press-empty">
          <div className="press-empty-icon">📭</div>
          <h3>Inga händelser</h3>
          <p>Inga händelser hittades för dina filter.</p>
        </div>
      </section>
    );
  }

  return (
    <>
      {newEventsCount > 0 && (
        <div className="new-events-banner">
          <button
            type="button"
            onClick={refreshEvents}
            disabled={loading}
            className="new-events-btn"
          >
            {loading ? (
              <>
                <span className="spinner-small" />
                Laddar...
              </>
            ) : (
              `${newEventsCount} nya händelser - Klicka för att uppdatera`
            )}
          </button>
        </div>
      )}

      <section id="eventsGrid" className="events-grid">
        {events.map((event, index) => (
          <EventCard
            key={event.id ?? index}
            event={event}
            currentView={currentView}
            onShowMap={onShowMap}
          />
        ))}
      </section>

      <div className="load-more-container">
        <button
          id="loadMoreBtn"
          className={`load-more-btn${loading ? ' loading' : ''}${!hasMore ? ' hidden' : ''}`}
          type="button"
          onClick={loadMore}
          disabled={loading || !hasMore}
        >
          {loading ? (
            <>
              <span className="spinner-small" />
              Laddar...
            </>
          ) : (
            'Ladda fler'
          )}
        </button>
      </div>
    </>
  );
}
