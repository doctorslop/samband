import { NextRequest, NextResponse } from 'next/server';
import { fetchDetailsText } from '@/lib/policeApi';
import { sanitizeInput } from '@/lib/utils';
import { checkRateLimit, rateLimitResponse, addRateLimitHeaders } from '@/lib/rateLimit';

export async function GET(request: NextRequest) {
  // Check rate limit
  const rateLimitResult = checkRateLimit(request);
  if (!rateLimitResult.allowed) {
    return rateLimitResponse(rateLimitResult);
  }

  const searchParams = request.nextUrl.searchParams;
  const url = searchParams.get('url');

  if (!url) {
    return NextResponse.json(
      { success: false, error: 'URL parameter required' },
      { status: 400 }
    );
  }

  const sanitizedUrl = sanitizeInput(url, 500);

  try {
    const details = await fetchDetailsText(sanitizedUrl);

    const response = NextResponse.json({
      success: !!details,
      details: { content: details },
    });
    return addRateLimitHeaders(response, rateLimitResult);
  } catch (error) {
    console.error('Error fetching details:', error);
    return NextResponse.json(
      { success: false, error: 'Failed to fetch details' },
      { status: 500 }
    );
  }
}
