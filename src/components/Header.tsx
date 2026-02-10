'use client';

import { useEffect, useRef, useState } from 'react';
import type { Density } from './ClientApp';

interface HeaderProps {
  currentView: string;
  onViewChange: (view: string) => void;
  onLogoClick?: () => void;
  density: Density;
  onDensityChange: (density: Density) => void;
  expandSummaries: boolean;
  onExpandSummariesChange: (expand: boolean) => void;
}

export default function Header({ currentView, onViewChange, onLogoClick, density, onDensityChange, expandSummaries, onExpandSummariesChange }: HeaderProps) {
  const headerRef = useRef<HTMLElement>(null);
  const settingsRef = useRef<HTMLDivElement>(null);
  const [isCompact, setIsCompact] = useState(false);
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
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

  // Close settings on click outside
  useEffect(() => {
    if (!settingsOpen) return;
    const handleClickOutside = (e: MouseEvent) => {
      if (settingsRef.current && !settingsRef.current.contains(e.target as Node)) {
        setSettingsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [settingsOpen]);

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
          <div className="settings-wrapper" ref={settingsRef}>
            <button
              type="button"
              className={`settings-toggle${settingsOpen ? ' active' : ''}`}
              onClick={() => setSettingsOpen(!settingsOpen)}
              aria-label="Inställningar"
              aria-expanded={settingsOpen}
              title="Inställningar"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
              </svg>
            </button>
            {settingsOpen && (
              <div className="settings-panel">
                <div className="settings-section">
                  <div className="settings-label">Vy</div>
                  <div className="settings-options">
                    <button
                      type="button"
                      className={`settings-option${density === 'comfortable' ? ' active' : ''}`}
                      onClick={() => onDensityChange('comfortable')}
                    >
                      Bekväm
                    </button>
                    <button
                      type="button"
                      className={`settings-option${density === 'compact' ? ' active' : ''}`}
                      onClick={() => onDensityChange('compact')}
                    >
                      Kompakt
                    </button>
                    <button
                      type="button"
                      className={`settings-option${density === 'stream' ? ' active' : ''}`}
                      onClick={() => onDensityChange('stream')}
                    >
                      Flöde
                    </button>
                  </div>
                </div>
                <div className="settings-divider"></div>
                <div className="settings-section">
                  <div className="settings-row">
                    <span className="settings-label">Läs mer</span>
                    <button
                      type="button"
                      className={`settings-switch${expandSummaries ? ' active' : ''}`}
                      onClick={() => onExpandSummariesChange(!expandSummaries)}
                      role="switch"
                      aria-checked={expandSummaries}
                    >
                      <span className="settings-switch-thumb"></span>
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
          <div className="live-indicator">
            <span className="live-dot" />
            <span className="live-text">Live</span>
          </div>
        </div>
      </div>
    </header>
  );
}
