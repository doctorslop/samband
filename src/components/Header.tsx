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
            ) : (
              <svg className="logo-icon-default-svg" viewBox="0 0 5080 5517.3335" aria-hidden="true">
                <g transform="matrix(0.13333333,0,0,-0.13333333,0,5517.3333)">
                  <path d="m 28739.4,6775.73 c 53.9,-270.97 291.8,-466.11 568.1,-466.11 h 509.1 c 464,0 867.9,-316.85 978.4,-767.45 l 897.9,-3661.13 c 42.6,-173.61 3,-357.17 -107.3,-497.82 -110.3,-140.66 -279.2,-222.81 -457.9,-222.81 H 6957.65 c -178.76,0 -347.61,82.15 -457.93,222.81 -110.33,140.65 -149.9,324.21 -107.32,497.82 l 897.88,3661.13 c 110.5,450.6 514.46,767.45 978.4,767.45 h 509.18 c 276.29,0 514.13,195.14 568.1,466.11 l 249.34,1251.79 c 93.86,471.25 507.5,810.61 988,810.61 H 27502 c 480.5,0 894.2,-339.36 988,-810.61 l 249.4,-1251.79" fill="#dcdcdc"/>
                  <path d="m 32818.1,2156.99 c 127.3,-519.22 9,-1068.15 -320.9,-1488.791 C 32167.2,247.559 31662.3,1.87891 31127.7,1.87891 H 6957.65 C 6423.05,1.87891 5918.1,247.559 5588.16,668.199 5258.21,1088.84 5139.88,1637.77 5267.21,2156.99 l 897.88,3661.13 c 237.59,968.79 1106.1,1650.03 2103.59,1650.03 H 29816.6 c 997.5,0 1866,-681.24 2103.6,-1650.03 z M 31692.9,1881.04 30795,5542.17 c -110.5,450.6 -514.4,767.45 -978.4,767.45 H 8268.68 c -463.94,0 -867.9,-316.85 -978.4,-767.45 L 6392.4,1881.04 c -42.58,-173.61 -3.01,-357.17 107.32,-497.82 110.32,-140.66 279.17,-222.81 457.93,-222.81 H 31127.7 c 178.7,0 347.6,82.15 457.9,222.81 110.3,140.65 149.9,324.21 107.3,497.82" fill="#000"/>
                  <path d="m 29782.7,7468.15 h 33.9 c 997.5,0 1866,-681.24 2103.6,-1650.03 l 897.9,-3661.13 c 127.3,-519.22 9,-1068.15 -321,-1488.791 C 32167.2,247.559 31662.3,1.87891 31127.7,1.87891 H 6957.65 C 6423.05,1.87891 5918.1,247.559 5588.16,668.199 5258.21,1088.84 5139.88,1637.77 5267.21,2156.99 l 897.88,3661.13 c 237.59,968.79 1106.1,1650.03 2103.59,1650.03 h 33.91 l 156.5,785.69 c 201.81,1013.19 1091.1,1742.82 2124.21,1742.82 H 27502 c 1033.1,0 1922.4,-729.63 2124.2,-1742.82 z M 28739.4,6775.73 28490,8027.52 c -93.8,471.25 -507.5,810.61 -988,810.61 H 10583.3 c -480.5,0 -894.14,-339.36 -988,-810.61 L 9345.96,6775.73 c -53.97,-270.97 -291.81,-466.11 -568.1,-466.11 h -509.18 c -463.94,0 -867.9,-316.85 -978.4,-767.45 L 6392.4,1881.04 c -42.58,-173.61 -3.01,-357.17 107.32,-497.82 110.32,-140.66 279.17,-222.81 457.93,-222.81 H 31127.7 c 178.7,0 347.6,82.15 457.9,222.81 110.3,140.65 149.9,324.21 107.3,497.82 L 30795,5542.17 c -110.5,450.6 -514.4,767.45 -978.4,767.45 h -509.2 c -276.2,0 -514.1,195.14 -568,466.11" fill="#000"/>
                  <path d="M 26498.8,10034.4 H 11586.5 l 1735.4,16021.5 c 69.8,644.7 614.1,1133.2 1262.6,1133.2 h 8916.3 c 648.5,0 1192.8,-488.5 1262.6,-1133.2 l 1735.4,-16021.5" fill="#ff0000"/>
                  <path d="M 27144.2,8875.91 H 10941.1 c -164.5,0 -321.2,69.93 -431.1,192.35 -109.9,122.41 -162.5,285.76 -144.8,449.29 l 1804.9,16663.05 c 133.5,1232.8 1174.4,2167.1 2414.4,2167 h 8916.3 c 1240,0.1 2280.9,-934.2 2414.4,-2167 L 27720.1,9517.55 c 17.7,-163.53 -34.9,-326.88 -144.8,-449.29 -109.9,-122.42 -266.6,-192.35 -431.1,-192.35 z m -645.4,1158.49 -1735.4,16021.5 c -69.8,644.7 -614.1,1133.2 -1262.6,1133.2 h -8916.3 c -648.5,0 -1192.8,-488.5 -1262.6,-1133.2 L 11586.5,10034.4 h 14912.3" fill="#000"/>
                  <path className="siren-ray" d="M 817.461,24459.7 8005.98,22975 c 366.42,-75.7 602.46,-434.6 526.78,-801 -75.68,-366.4 -434.61,-602.5 -801.03,-526.8 L 543.219,23131.9 c -366.418,75.7 -602.4573,434.6 -526.7776,801 75.6797,366.5 434.6096,602.5 801.0196,526.8" fill="#ff0000"/>
                  <path className="siren-ray" d="m 7049.19,35234.8 4659.61,-4542.2 c 270.2,-263.4 275.8,-696.7 12.3,-966.9 -263.4,-270.3 -696.7,-275.8 -966.9,-12.4 l -4659.6,4542.2 c -270.23,263.5 -275.76,696.7 -12.33,967 263.42,270.2 696.69,275.7 966.92,12.3" fill="#ff0000"/>
                  <path className="siren-ray" d="m 19722.9,40693.4 2,-7993.4 c 0.1,-376 -305.1,-681.4 -681.1,-681.5 -376,-0.1 -681.3,305.1 -681.4,681.1 l -2,7993.5 c -0.1,376 305.1,681.3 681.1,681.4 376,0.1 681.3,-305.1 681.4,-681.1" fill="#ff0000"/>
                  <path className="siren-ray" d="M 37283.9,24459.7 30095.4,22975 c -366.4,-75.7 -602.4,-434.6 -526.8,-801 75.7,-366.4 434.7,-602.5 801.1,-526.8 l 7188.5,1484.7 c 366.4,75.7 602.4,434.6 526.8,801 -75.7,366.5 -434.7,602.5 -801.1,526.8" fill="#ff0000"/>
                  <path className="siren-ray" d="m 31052.2,35234.8 -4659.6,-4542.2 c -270.2,-263.4 -275.8,-696.7 -12.3,-966.9 263.4,-270.3 696.6,-275.8 966.9,-12.4 l 4659.6,4542.2 c 270.2,263.5 275.8,696.7 12.3,967 -263.4,270.2 -696.7,275.7 -966.9,12.3" fill="#ff0000"/>
                  <path d="m 19050.7,15770.1 c 1395,0 1868.6,4949.7 1465.4,6828.4 -403.2,1878.8 -2527.6,1878.8 -2930.8,0 -403.2,-1878.7 70.3,-6828.4 1465.4,-6828.4" fill="#fff"/>
                  <path d="m 19050.7,15009.8 c 635.3,0 1151.1,-515.8 1151.1,-1151.1 0,-635.4 -515.8,-1151.2 -1151.1,-1151.2 -635.3,0 -1151.1,515.8 -1151.1,1151.2 0,635.3 515.8,1151.1 1151.1,1151.1" fill="#fff"/>
                </g>
              </svg>
            )}
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
