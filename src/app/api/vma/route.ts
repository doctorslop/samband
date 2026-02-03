import { NextRequest, NextResponse } from 'next/server';
import { fetchVmaAlerts } from '@/lib/vmaApi';
import { checkRateLimit, rateLimitResponse, addRateLimitHeaders } from '@/lib/rateLimit';

export async function GET(request: NextRequest) {
  // Check rate limit
  const rateLimitResult = checkRateLimit(request);
  if (!rateLimitResult.allowed) {
    return rateLimitResponse(rateLimitResult);
  }

  const searchParams = request.nextUrl.searchParams;
  const forceRefresh = searchParams.get('refresh') === '1';

  try {
    const vmaData = await fetchVmaAlerts(forceRefresh);

    const response = NextResponse.json(vmaData);
    return addRateLimitHeaders(response, rateLimitResult);
  } catch (error) {
    console.error('Error fetching VMA:', error);
    return NextResponse.json(
      { success: false, current: [], recent: [], error: 'Failed to fetch VMA' },
      { status: 500 }
    );
  }
}
