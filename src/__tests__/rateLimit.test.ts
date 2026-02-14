// Rate limiter tests.
// Since NextRequest isn't available in jsdom, we mock the next/server module.

jest.mock('next/server', () => {
  class MockHeaders {
    private headers: Record<string, string>;
    constructor(init?: Record<string, string>) {
      this.headers = {};
      if (init) {
        for (const [key, value] of Object.entries(init)) {
          this.headers[key.toLowerCase()] = value;
        }
      }
    }
    get(name: string) { return this.headers[name.toLowerCase()] || null; }
    set(name: string, value: string) { this.headers[name.toLowerCase()] = value; }
  }

  class MockNextRequest {
    headers: MockHeaders;
    nextUrl: { searchParams: URLSearchParams };
    constructor(_url: string, init?: { headers?: Record<string, string> }) {
      this.headers = new MockHeaders(init?.headers || {});
      this.nextUrl = { searchParams: new URLSearchParams() };
    }
  }

  class MockNextResponse {
    status: number;
    headers: MockHeaders;
    constructor(_body: unknown, init?: { status?: number; headers?: Record<string, string> }) {
      this.status = init?.status || 200;
      this.headers = new MockHeaders(init?.headers || {});
    }
    static json(body: unknown, init?: { status?: number; headers?: Record<string, string> }) {
      return new MockNextResponse(body, init);
    }
  }

  return {
    NextRequest: MockNextRequest,
    NextResponse: MockNextResponse,
  };
});

import { checkRateLimit, rateLimitResponse, addRateLimitHeaders, RateLimitResult } from '@/lib/rateLimit';

// eslint-disable-next-line @typescript-eslint/no-require-imports
const { NextRequest, NextResponse } = require('next/server');

function createMockRequest(headers: Record<string, string> = {}) {
  return new NextRequest('http://localhost:3000/api/events', { headers });
}

describe('checkRateLimit', () => {
  beforeEach(() => {
    jest.useFakeTimers();
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('allows the first request from an IP', () => {
    const request = createMockRequest({ 'x-forwarded-for': '100.0.0.1' });
    const result = checkRateLimit(request);

    expect(result.allowed).toBe(true);
    expect(result.remaining).toBe(59);
  });

  it('extracts IP from x-forwarded-for header (first IP in chain)', () => {
    const request = createMockRequest({ 'x-forwarded-for': '100.0.0.2, 192.168.1.1' });
    const result = checkRateLimit(request);

    expect(result.allowed).toBe(true);
    expect(result.remaining).toBe(59);
  });

  it('extracts IP from x-real-ip header when x-forwarded-for is absent', () => {
    const request = createMockRequest({ 'x-real-ip': '100.0.0.3' });
    const result = checkRateLimit(request);

    expect(result.allowed).toBe(true);
    expect(result.remaining).toBe(59);
  });

  it('decrements remaining count with each request', () => {
    const request = createMockRequest({ 'x-forwarded-for': '100.0.0.4' });

    const result1 = checkRateLimit(request);
    expect(result1.remaining).toBe(59);

    const result2 = checkRateLimit(request);
    expect(result2.remaining).toBe(58);

    const result3 = checkRateLimit(request);
    expect(result3.remaining).toBe(57);
  });

  it('blocks requests after exceeding the limit', () => {
    const request = createMockRequest({ 'x-forwarded-for': '100.0.0.5' });

    // Make 60 requests (the limit)
    for (let i = 0; i < 60; i++) {
      const result = checkRateLimit(request);
      expect(result.allowed).toBe(true);
    }

    // 61st request should be blocked
    const blocked = checkRateLimit(request);
    expect(blocked.allowed).toBe(false);
    expect(blocked.remaining).toBe(0);
  });

  it('resets the window after the rate limit period expires', () => {
    const request = createMockRequest({ 'x-forwarded-for': '100.0.0.6' });

    // Exhaust the limit
    for (let i = 0; i < 60; i++) {
      checkRateLimit(request);
    }
    const blocked = checkRateLimit(request);
    expect(blocked.allowed).toBe(false);

    // Advance past the 1-minute window
    jest.advanceTimersByTime(61 * 1000);

    // Should be allowed again
    const result = checkRateLimit(request);
    expect(result.allowed).toBe(true);
    expect(result.remaining).toBe(59);
  });

  it('tracks different IPs independently', () => {
    const requestA = createMockRequest({ 'x-forwarded-for': '100.0.0.7' });
    const requestB = createMockRequest({ 'x-forwarded-for': '100.0.0.8' });

    // Exhaust IP A
    for (let i = 0; i < 60; i++) {
      checkRateLimit(requestA);
    }
    expect(checkRateLimit(requestA).allowed).toBe(false);

    // IP B should still be allowed
    expect(checkRateLimit(requestB).allowed).toBe(true);
  });

  it('includes a resetTime in the future', () => {
    const request = createMockRequest({ 'x-forwarded-for': '100.0.0.9' });
    const result = checkRateLimit(request);

    expect(result.resetTime).toBeGreaterThan(Date.now());
  });
});

describe('rateLimitResponse', () => {
  it('returns a 429 response with correct headers', () => {
    const result: RateLimitResult = {
      allowed: false,
      remaining: 0,
      resetTime: Date.now() + 30000,
    };

    const response = rateLimitResponse(result);

    expect(response.status).toBe(429);
    expect(response.headers.get('X-RateLimit-Limit')).toBe('60');
    expect(response.headers.get('X-RateLimit-Remaining')).toBe('0');
    expect(response.headers.get('Retry-After')).toBeTruthy();
  });

  it('includes a Retry-After header with seconds until reset', () => {
    const resetTime = Date.now() + 45000;
    const result: RateLimitResult = {
      allowed: false,
      remaining: 0,
      resetTime,
    };

    const response = rateLimitResponse(result);
    const retryAfter = parseInt(response.headers.get('Retry-After')!);

    expect(retryAfter).toBeGreaterThanOrEqual(44);
    expect(retryAfter).toBeLessThanOrEqual(46);
  });
});

describe('addRateLimitHeaders', () => {
  it('adds rate limit headers to an existing response', () => {
    const response = NextResponse.json({ data: 'test' });
    const result: RateLimitResult = {
      allowed: true,
      remaining: 42,
      resetTime: Date.now() + 30000,
    };

    const enhanced = addRateLimitHeaders(response, result);

    expect(enhanced.headers.get('X-RateLimit-Limit')).toBe('60');
    expect(enhanced.headers.get('X-RateLimit-Remaining')).toBe('42');
    expect(enhanced.headers.get('X-RateLimit-Reset')).toBeTruthy();
  });
});
