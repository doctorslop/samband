'use client';

import { useState, useCallback, useEffect, useMemo } from 'react';
import EventCard from './EventCard';
import { FormattedEvent } from '@/types';

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
  }, [filterKey, initialEvents, initialHasMore]);

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
