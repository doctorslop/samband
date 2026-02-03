'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';

interface HeaderProps {
  currentView: string;
  onViewChange: (view: string) => void;
}

export default function Header({ currentView, onViewChange }: HeaderProps) {
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
        <Link className="logo" href="/">
          <div className="logo-icon">👮</div>
          <div className="logo-text">
            <h1>Sambandscentralen</h1>
            <p>Polisens händelsenotiser i realtid</p>
          </div>
        </Link>
        <div className="header-controls">
          <div className="view-toggle">
            <button
              type="button"
              data-view="list"
              className={currentView === 'list' ? 'active' : ''}
              onClick={() => onViewChange('list')}
            >
              📋 <span className="label">Lista</span>
            </button>
            <button
              type="button"
              data-view="map"
              className={currentView === 'map' ? 'active' : ''}
              onClick={() => onViewChange('map')}
            >
              🗺️ <span className="label">Karta</span>
            </button>
            <button
              type="button"
              data-view="stats"
              className={currentView === 'stats' ? 'active' : ''}
              onClick={() => onViewChange('stats')}
            >
              📊 <span className="label">Statistik</span>
            </button>
            <button
              type="button"
              data-view="vma"
              className={currentView === 'vma' ? 'active' : ''}
              onClick={() => onViewChange('vma')}
            >
              ⚠️ <span className="label">VMA</span>
            </button>
          </div>
          <div className="live-indicator">
            <span className="live-dot" />
            Live
          </div>
        </div>
      </div>
    </header>
  );
}
