'use client';

import { useEffect, useRef, useState } from 'react';
import type { Density } from './ClientApp';

interface HeaderProps {
  currentView: string;
  onViewChange: (view: string) => void;
  onLogoClick?: () => void;
  density: Density;
  onDensityChange: (density: Density) => void;
}

export default function Header({ currentView, onViewChange, onLogoClick, density, onDensityChange }: HeaderProps) {
  const headerRef = useRef<HTMLElement>(null);
  const [isCompact, setIsCompact] = useState(false);
  const [isCollapsed, setIsCollapsed] = useState(false);
  const lastScrollY = useRef(0);

  useEffect(() => {
    const handleScroll = () => {
      const currentScrollY = window.scrollY;
      const scrollingDown = currentScrollY > lastScrollY.current;
      const isDesktop = window.innerWidth >= 769;

      if (isDesktop) {
        if (currentScrollY <= 10) {
          setIsCompact(false);
          setIsCollapsed(false);
        } else if (scrollingDown) {
          if (currentScrollY > 50) {
            setIsCompact(true);
          }
          if (currentScrollY > 150) {
            setIsCollapsed(true);
          }
        } else {
          if (lastScrollY.current - currentScrollY > 10 || currentScrollY < 150) {
            setIsCollapsed(false);
          }
          if (currentScrollY <= 50) {
            setIsCompact(false);
          }
        }
      } else {
        setIsCompact(false);
        setIsCollapsed(false);
      }

      lastScrollY.current = currentScrollY;
    };

    window.addEventListener('scroll', handleScroll, { passive: true });
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  const headerClasses = [
    isCompact ? 'header-compact' : '',
    isCollapsed ? 'header-collapsed' : '',
    !isCollapsed && isCompact ? 'header-show' : '',
  ].filter(Boolean).join(' ');

  return (
    <header ref={headerRef} className={headerClasses}>
      <div className="header-content">
        <a
          className="logo"
          href="/"
          onClick={(e) => {
            if (onLogoClick) {
              e.preventDefault();
              onLogoClick();
            }
          }}
        >
          <div className="logo-icon">👮</div>
          <div className="logo-text">
            <h1>Sambandscentralen</h1>
            <p>Polisens händelsenotiser i realtid</p>
          </div>
        </a>
        <div className="header-controls">
          <nav className="view-toggle" role="tablist" aria-label="Vy-navigering">
            <button
              type="button"
              role="tab"
              aria-selected={currentView === 'list'}
              data-view="list"
              className={currentView === 'list' ? 'active' : ''}
              onClick={() => onViewChange('list')}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <line x1="8" y1="6" x2="21" y2="6"></line>
                <line x1="8" y1="12" x2="21" y2="12"></line>
                <line x1="8" y1="18" x2="21" y2="18"></line>
                <line x1="3" y1="6" x2="3.01" y2="6"></line>
                <line x1="3" y1="12" x2="3.01" y2="12"></line>
                <line x1="3" y1="18" x2="3.01" y2="18"></line>
              </svg>
              <span className="label">Lista</span>
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={currentView === 'map'}
              data-view="map"
              className={currentView === 'map' ? 'active' : ''}
              onClick={() => onViewChange('map')}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
              </svg>
              <span className="label">Karta</span>
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={currentView === 'stats'}
              data-view="stats"
              className={currentView === 'stats' ? 'active' : ''}
              onClick={() => onViewChange('stats')}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
              </svg>
              <span className="label">Statistik</span>
            </button>
          </nav>
          <button
            type="button"
            className={`density-toggle${density === 'compact' ? ' density-compact' : ''}`}
            onClick={() => onDensityChange(density === 'comfortable' ? 'compact' : 'comfortable')}
            aria-label={density === 'comfortable' ? 'Byt till kompakt vy' : 'Byt till bekväm vy'}
            aria-pressed={density === 'compact'}
            title={density === 'comfortable' ? 'Kompakt vy' : 'Bekväm vy'}
          >
            {density === 'comfortable' ? (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="18" height="4" rx="1"></rect>
                <rect x="3" y="10" width="18" height="4" rx="1"></rect>
                <rect x="3" y="17" width="18" height="4" rx="1"></rect>
              </svg>
            ) : (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="18" height="3" rx="1"></rect>
                <rect x="3" y="8.5" width="18" height="3" rx="1"></rect>
                <rect x="3" y="14" width="18" height="3" rx="1"></rect>
                <rect x="3" y="19.5" width="18" height="3" rx="1"></rect>
              </svg>
            )}
          </button>
          <div className="live-indicator">
            <span className="live-dot" />
            <span className="live-text">Live</span>
          </div>
        </div>
      </div>
    </header>
  );
}
