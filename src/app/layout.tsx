import type { Metadata, Viewport } from 'next';
import './globals.css';
import ServiceWorkerRegistration from '@/components/ServiceWorkerRegistration';

export const metadata: Metadata = {
  title: 'Sambandscentralen - Polishändelser i realtid',
  description: 'Följ polisens händelser i realtid över hela Sverige. Se aktuella polishändelser på karta, filtrera efter plats och händelsetyp.',
  keywords: ['polis', 'polishändelser', 'Sverige', 'realtid', 'brott', 'olyckor', 'karta'],
  manifest: '/manifest.json',
  icons: {
    icon: "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'><rect fill='%230a1628' rx='96' width='512' height='512'/><rect x='136' y='340' width='240' height='40' rx='8' fill='%23cbd5e1'/><rect x='156' y='300' width='200' height='48' rx='6' fill='%2394a3b8'/><path d='M180 308C180 308 190 180 256 160C322 180 332 308 332 308Z' fill='%23e2e8f0'/><circle cx='256' cy='220' r='24' fill='%23ef4444'/><circle cx='256' cy='220' r='14' fill='%23fca5a5'/><line x1='256' y1='140' x2='256' y2='118' stroke='%23ef4444' stroke-width='10' stroke-linecap='round' opacity='0.8'/><line x1='312' y1='168' x2='330' y2='152' stroke='%23ef4444' stroke-width='10' stroke-linecap='round' opacity='0.6'/><line x1='200' y1='168' x2='182' y2='152' stroke='%23ef4444' stroke-width='10' stroke-linecap='round' opacity='0.6'/></svg>",
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
        <script
          dangerouslySetInnerHTML={{
            __html: `(function(){try{var t=localStorage.getItem('theme');if(t==='radar')document.documentElement.setAttribute('data-theme',t)}catch(e){}})()`,
          }}
        />
        <ServiceWorkerRegistration />
        <a href="#eventsGrid" className="skip-link">
          Hoppa till innehåll
        </a>
        {children}
      </body>
    </html>
  );
}
