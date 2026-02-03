import { NextRequest, NextResponse } from 'next/server';
import { fetchVmaAlerts } from '@/lib/vmaApi';

export async function GET(request: NextRequest) {
  const searchParams = request.nextUrl.searchParams;
  const forceRefresh = searchParams.get('refresh') === '1';

  try {
    const vmaData = await fetchVmaAlerts(forceRefresh);

    return NextResponse.json(vmaData);
  } catch (error) {
    console.error('Error fetching VMA:', error);
    return NextResponse.json(
      { success: false, current: [], recent: [], error: 'Failed to fetch VMA' },
      { status: 500 }
    );
  }
}
