'use client';

import Link from 'next/link';
import { useEffect, useRef, useState, useCallback } from 'react';
import type { Density, Theme } from './ClientApp';

interface HeaderProps {
  currentView: string;
  onViewChange: (view: string) => void;
  onLogoClick?: () => void;
  density: Density;
  onDensityChange: (density: Density) => void;
  theme: Theme;
  onThemeChange: (theme: Theme) => void;
  expandSummaries: boolean;
  onExpandSummariesChange: (expand: boolean) => void;
  showDensitySettings?: boolean;
}

export default function Header({ currentView, onViewChange, onLogoClick, density, onDensityChange, theme, onThemeChange, expandSummaries, onExpandSummariesChange, showDensitySettings = true }: HeaderProps) {
  const headerRef = useRef<HTMLElement>(null);
  const settingsRef = useRef<HTMLDivElement>(null);
  const toggleRef = useRef<HTMLButtonElement>(null);
  const [isCompact, setIsCompact] = useState(false);
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [panelTop, setPanelTop] = useState<number | undefined>(undefined);
  const lastScrollY = useRef(0);
  const updatePanelPosition = useCallback(() => {
    if (toggleRef.current) {
      const rect = toggleRef.current.getBoundingClientRect();
      setPanelTop(rect.bottom + 8);
    }
  }, []);

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

  // Close settings on click outside - handle both mouse and touch for mobile
  useEffect(() => {
    if (!settingsOpen) return;
    const handleClickOutside = (e: MouseEvent | TouchEvent) => {
      const target = e.target as Node;
      if (settingsRef.current && !settingsRef.current.contains(target)) {
        setSettingsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('touchstart', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('touchstart', handleClickOutside);
    };
  }, [settingsOpen]);

  const headerClasses = [
    isCompact ? 'header-compact' : '',
    isCollapsed ? 'header-collapsed' : '',
    !isCollapsed && isCompact ? 'header-show' : '',
  ].filter(Boolean).join(' ');

  return (
    <header ref={headerRef} className={headerClasses}>
      <div className="header-content">
        <Link
          className="logo"
          href="/"
          onClick={(e) => {
            if (onLogoClick) {
              e.preventDefault();
              onLogoClick();
            }
          }}
        >
          <div className="logo-icon">
            {theme === 'radar' ? (
              <svg className="logo-icon-radar-svg" width="24" height="24" viewBox="0 0 508 508" fill="currentColor" aria-hidden="true">
                <path d="M475.6,383.8h-55.9V254c0-91.4-74.3-165.7-165.7-165.7S88.3,162.6,88.3,254v129.8H32.4c-7.8,0-14.1,6.3-14.1,14.1v96c0,7.8,6.3,14.1,14.1,14.1h443.2c7.8,0,14.1-6.3,14.1-14.1v-96C489.7,390.1,483.4,383.8,475.6,383.8z M116.5,254c0-75.8,61.7-137.5,137.5-137.5S391.5,178.2,391.5,254v129.8h-275V254z M461.5,479.8h-415V412h415V479.8z"/>
                <path d="M46,239.9H14.1C6.3,239.9,0,246.2,0,254s6.3,14.1,14.1,14.1H46c7.8,0,14.1-6.3,14.1-14.1S53.8,239.9,46,239.9z"/>
                <path d="M67.2,161.4l-29.4-12.2c-7.2-3-15.5,0.4-18.4,7.6c-3,7.2,0.4,15.5,7.6,18.4l29.4,12.2c7.2,3,15.5-0.4,18.4-7.6C77.8,172.6,74.4,164.3,67.2,161.4z"/>
                <path d="M116.9,96.9L94.4,74.4c-5.5-5.5-14.4-5.5-20,0c-5.5,5.5-5.5,14.4,0,20l22.5,22.5c5.5,5.5,14.4,5.5,20,0C122.4,111.4,122.4,102.5,116.9,96.9z"/>
                <path d="M187.4,56.4L175.2,27c-3-7.2-11.2-10.6-18.4-7.6c-7.2,3-10.6,11.2-7.6,18.4l12.2,29.4c3,7.2,11.2,10.6,18.4,7.6S190.4,63.6,187.4,56.4z"/>
                <path d="M254,0c-7.8,0-14.1,6.3-14.1,14.1V46c0,7.8,6.3,14.1,14.1,14.1s14.1-6.3,14.1-14.1V14.1C268.1,6.3,261.8,0,254,0z"/>
                <path d="M351.2,19.4c-7.2-3-15.5,0.4-18.4,7.6l-12.2,29.4c-3,7.2,0.4,15.5,7.6,18.4c7.2,3,15.5-0.4,18.4-7.6l12.2-29.4C361.8,30.6,358.4,22.3,351.2,19.4z"/>
                <path d="M433.6,74.4c-5.5-5.5-14.4-5.5-20,0l-22.5,22.5c-5.5,5.5-5.5,14.5,0,20s14.4,5.5,20,0l22.5-22.5C439.1,88.9,439.1,80,433.6,74.4z"/>
                <path d="M488.6,156.8c-3-7.2-11.2-10.6-18.4-7.6l-29.4,12.2c-7.2,2.9-10.6,11.2-7.6,18.4s11.2,10.6,18.4,7.6l29.4-12.2C488.2,172.2,491.6,164,488.6,156.8z"/>
                <path d="M493.9,239.9H462c-7.8,0-14.1,6.3-14.1,14.1s6.3,14.1,14.1,14.1h31.9c7.8,0,14.1-6.3,14.1-14.1S501.7,239.9,493.9,239.9z"/>
              </svg>
            ) : '🚨'}
          </div>
          <div className="logo-text">
            <h1>Sambandscentralen</h1>
            <p>Polisens händelsenotiser i realtid</p>
          </div>
        </Link>
        <div className="header-controls">
          <nav className="view-toggle" role="tablist" aria-label="Vy-navigering">
            <button
              type="button"
              role="tab"
              aria-selected={currentView === 'list'}
              aria-label="Lista"
              data-view="list"
              className={currentView === 'list' ? 'active' : ''}
              onClick={() => onViewChange('list')}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
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
              aria-label="Karta"
              data-view="map"
              className={currentView === 'map' ? 'active' : ''}
              onClick={() => onViewChange('map')}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                <circle cx="12" cy="10" r="3"></circle>
              </svg>
              <span className="label">Karta</span>
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={currentView === 'stats'}
              aria-label="Statistik"
              data-view="stats"
              className={currentView === 'stats' ? 'active' : ''}
              onClick={() => onViewChange('stats')}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
              </svg>
              <span className="label">Statistik</span>
            </button>
          </nav>
          <div className="settings-wrapper" ref={settingsRef}>
            <button
              ref={toggleRef}
              type="button"
              className={`settings-toggle${settingsOpen ? ' active' : ''}`}
              onClick={(e) => {
                e.stopPropagation();
                if (!settingsOpen) updatePanelPosition();
                setSettingsOpen(!settingsOpen);
              }}
              onTouchEnd={(e) => {
                e.stopPropagation();
              }}
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
              <div
                className="settings-panel"
                style={panelTop !== undefined ? { top: panelTop } : undefined}
                onClick={(e) => e.stopPropagation()}
                onTouchStart={(e) => e.stopPropagation()}
              >
                {showDensitySettings && (
                  <>
                    <div className="settings-section">
                      <span className="settings-section-label">Visningsläge</span>
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
                  </>
                )}
                <div className="settings-section">
                  <div className="settings-row">
                    <span className="settings-label">Expandera notiser</span>
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
                <div className="settings-divider"></div>
                <div className="settings-section">
                  <span className="settings-section-label">Tema</span>
                  <div className="settings-options">
                    <button
                      type="button"
                      className={`settings-option${theme === 'default' ? ' active' : ''}`}
                      onClick={() => onThemeChange('default')}
                    >
                      Standard
                    </button>
                    <button
                      type="button"
                      className={`settings-option${theme === 'radar' ? ' active' : ''}`}
                      onClick={() => onThemeChange('radar')}
                    >
                      Radar
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </header>
  );
}
