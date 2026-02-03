import { Suspense } from 'react';
import { getEventsFromDb, countEventsInDb, getFilterOptions, getStatsSummary } from '@/lib/db';
import { refreshEventsIfNeeded } from '@/lib/policeApi';
import { formatEventForUi, sanitizeLocation, sanitizeType, sanitizeSearch } from '@/lib/utils';
import ClientApp from '@/components/ClientApp';

const EVENTS_PER_PAGE = 40;
const ALLOWED_VIEWS = ['list', 'map', 'stats', 'vma'];

// Revalidate every 30 minutes to match the polisen.se API fetch interval
export const revalidate = 1800;

interface PageProps {
  searchParams: Promise<{
    view?: string;
    location?: string;
    type?: string;
    search?: string;
    page?: string;
  }>;
}

async function HomeContent({ searchParams }: PageProps) {
  // Await the searchParams
  const params = await searchParams;

  // Refresh events from API if needed
  await refreshEventsIfNeeded();

  // Sanitize and validate inputs
  const filters = {
    location: params.location ? sanitizeLocation(params.location) : '',
    type: params.type ? sanitizeType(params.type) : '',
    search: params.search ? sanitizeSearch(params.search) : '',
  };

  let currentView = params.view || 'list';
  if (!ALLOWED_VIEWS.includes(currentView)) {
    currentView = 'list';
  }

  // Fetch data
  const page = Math.max(1, parseInt(params.page || '1', 10));
  const offset = (page - 1) * EVENTS_PER_PAGE;

  const events = getEventsFromDb(filters, EVENTS_PER_PAGE, offset);
  const totalEvents = countEventsInDb(filters);
  const hasMore = offset + EVENTS_PER_PAGE < totalEvents;

  // Get map events (more for the map view)
  const mapEvents = getEventsFromDb(filters, 500, 0);

  // Format events for UI
  const formattedEvents = events.map(formatEventForUi);
  const formattedMapEvents = mapEvents.map(formatEventForUi);

  // Get filter options and stats
  const locations = getFilterOptions('location_name');
  const types = getFilterOptions('type');
  const stats = getStatsSummary();

  return (
    <ClientApp
      initialEvents={formattedEvents}
      mapEvents={formattedMapEvents}
      hasMore={hasMore}
      locations={locations}
      types={types}
      stats={stats}
      filters={filters}
      initialView={currentView}
    />
  );
}

export default function Home(props: PageProps) {
  return (
    <Suspense
      fallback={
        <div className="container">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '100vh' }}>
            <div className="spinner" />
          </div>
        </div>
      }
    >
      <HomeContent {...props} />
    </Suspense>
  );
}
