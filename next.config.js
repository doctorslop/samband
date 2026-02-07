/** @type {import('next').NextConfig} */
const nextConfig = {
  // Transpile leaflet to avoid bundler issues
  transpilePackages: ['leaflet'],
  // Allow Turbopack (default in Next.js 16) with empty config
  turbopack: {},
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
              "script-src 'self' 'unsafe-inline'",
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
  // Webpack fallback config (used with next build --webpack)
  webpack: (config, { isServer }) => {
    if (!isServer) {
      config.resolve.fallback = {
        ...config.resolve.fallback,
        fs: false,
        path: false,
        crypto: false,
      };
    }

    config.module.rules.push({
      test: /leaflet[\\/]dist[\\/]leaflet\.css$/,
      type: 'asset/resource',
      generator: {
        emit: false,
      },
    });

    return config;
  },
};

module.exports = nextConfig;
