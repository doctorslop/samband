'use client';

import { useEffect, useCallback } from 'react';

interface ShortcutHandlers {
  onSearch?: () => void;
  onEscape?: () => void;
  onListView?: () => void;
  onMapView?: () => void;
  onStatsView?: () => void;
  onScrollTop?: () => void;
}

export function useKeyboardShortcuts(handlers: ShortcutHandlers) {
  const handleKeyDown = useCallback((event: KeyboardEvent) => {
    // Don't trigger shortcuts when typing in input fields
    const target = event.target as HTMLElement;
    const isInputField = target.tagName === 'INPUT' ||
                         target.tagName === 'TEXTAREA' ||
                         target.tagName === 'SELECT' ||
                         target.isContentEditable;

    // Escape should always work (for closing modals, etc.)
    if (event.key === 'Escape' && handlers.onEscape) {
      handlers.onEscape();
      return;
    }

    // Don't trigger other shortcuts when in input fields
    if (isInputField) return;

    // Ctrl/Cmd + K or / for search focus
    if ((event.key === 'k' && (event.metaKey || event.ctrlKey)) || event.key === '/') {
      event.preventDefault();
      handlers.onSearch?.();
      return;
    }

    // Number keys for view switching (1 = List, 2 = Map, 3 = Stats)
    if (event.key === '1' && handlers.onListView) {
      event.preventDefault();
      handlers.onListView();
      return;
    }
    if (event.key === '2' && handlers.onMapView) {
      event.preventDefault();
      handlers.onMapView();
      return;
    }
    if (event.key === '3' && handlers.onStatsView) {
      event.preventDefault();
      handlers.onStatsView();
      return;
    }

    // Home key or 't' for scroll to top
    if ((event.key === 'Home' || event.key === 't') && handlers.onScrollTop) {
      event.preventDefault();
      handlers.onScrollTop();
      return;
    }
  }, [handlers]);

  useEffect(() => {
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [handleKeyDown]);
}
