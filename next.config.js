/** @type {import('next').NextConfig} */
const nextConfig = {
  // Revalidate data every 30 minutes (matches the polisen.se API fetch interval)
  experimental: {
    // Enable server actions
    serverActions: {
      allowedOrigins: ['localhost:3000'],
    },
  },
  // Security headers
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'X-Frame-Options', value: 'SAMEORIGIN' },
          { key: 'X-XSS-Protection', value: '1; mode=block' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          {
            key: 'Content-Security-Policy',
            value: [
              "default-src 'self'",
              "script-src 'self' 'unsafe-eval' 'unsafe-inline'",
              "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com",
              "img-src 'self' data: https://*.tile.openstreetmap.org https://*.basemaps.cartocdn.com blob:",
              "font-src 'self' https://fonts.gstatic.com",
              "connect-src 'self' https://polisen.se",
              "frame-ancestors 'self'",
            ].join('; '),
          },
        ],
      },
    ];
  },
  // Webpack config for better-sqlite3
  webpack: (config, { isServer }) => {
    if (!isServer) {
      // Don't bundle better-sqlite3 on client
      config.resolve.fallback = {
        ...config.resolve.fallback,
        fs: false,
        path: false,
        crypto: false,
      };
    }
    return config;
  },
};

module.exports = nextConfig;
