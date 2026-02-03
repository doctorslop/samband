import { NextRequest, NextResponse } from 'next/server';
import { getEventsFromDb, countEventsInDb } from '@/lib/db';
import { refreshEventsIfNeeded } from '@/lib/policeApi';
import { formatEventForUi, sanitizeLocation, sanitizeType, sanitizeSearch } from '@/lib/utils';
import { checkRateLimit, rateLimitResponse, addRateLimitHeaders } from '@/lib/rateLimit';

const EVENTS_PER_PAGE = 40;

export async function GET(request: NextRequest) {
  // Check rate limit
  const rateLimitResult = checkRateLimit(request);
  if (!rateLimitResult.allowed) {
    return rateLimitResponse(rateLimitResult);
  }

  // Refresh events if needed
  await refreshEventsIfNeeded();

  const searchParams = request.nextUrl.searchParams;
  const page = Math.max(1, parseInt(searchParams.get('page') || '1', 10));
  const offset = (page - 1) * EVENTS_PER_PAGE;

  const filters = {
    location: searchParams.get('location') ? sanitizeLocation(searchParams.get('location')!) : undefined,
    type: searchParams.get('type') ? sanitizeType(searchParams.get('type')!) : undefined,
    search: searchParams.get('search') ? sanitizeSearch(searchParams.get('search')!) : undefined,
  };

  try {
    const events = getEventsFromDb(filters, EVENTS_PER_PAGE, offset);
    const total = countEventsInDb(filters);
    const formattedEvents = events.map(formatEventForUi);

    const response = NextResponse.json({
      events: formattedEvents,
      hasMore: (offset + EVENTS_PER_PAGE) < total,
      total,
    });
    return addRateLimitHeaders(response, rateLimitResult);
  } catch (error) {
    console.error('Error fetching events:', error);
    return NextResponse.json(
      { error: 'Failed to fetch events' },
      { status: 500 }
    );
  }
}
