import type { Metadata, Viewport } from 'next';
import './globals.css';
import ServiceWorkerRegistration from '@/components/ServiceWorkerRegistration';

export const metadata: Metadata = {
  title: 'Sambandscentralen - Polishändelser i realtid',
  description: 'Följ polisens händelser i realtid över hela Sverige. Se aktuella polishändelser på karta, filtrera efter plats och händelsetyp.',
  keywords: ['polis', 'polishändelser', 'Sverige', 'realtid', 'brott', 'olyckor', 'karta'],
  manifest: '/manifest.json',
  icons: {
    icon: "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='0.9em' font-size='90'>👮</text></svg>",
  },
  appleWebApp: {
    capable: true,
    statusBarStyle: 'default',
    title: 'Sambandscentralen',
  },
  openGraph: {
    title: 'Sambandscentralen - Polishändelser i realtid',
    description: 'Följ polisens händelser i realtid över hela Sverige.',
    type: 'website',
    locale: 'sv_SE',
  },
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: '#0a1628',
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="sv">
      <head>
        {/* eslint-disable-next-line @next/next/no-page-custom-font */}
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap"
        />
        <link
          rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossOrigin=""
        />
      </head>
      <body>
        <ServiceWorkerRegistration />
        <a href="#eventsGrid" className="skip-link">
          Hoppa till innehåll
        </a>
        {children}
      </body>
    </html>
  );
}
