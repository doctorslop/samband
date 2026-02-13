'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';

interface FiltersProps {
  locations: string[];
  types: string[];
  currentView: string;
  filters: {
    location: string;
    type: string;
    search: string;
  };
}

// Custom hook for debouncing
function useDebounce<T>(value: T, delay: number): T {
  const [debouncedValue, setDebouncedValue] = useState<T>(value);

  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedValue(value);
    }, delay);

    return () => {
      clearTimeout(handler);
    };
  }, [value, delay]);

  return debouncedValue;
}

export default function Filters({ locations, types, currentView, filters }: FiltersProps) {
  const router = useRouter();
  const searchParams = useSearchParams();

  const [showCustomLocation, setShowCustomLocation] = useState(false);
  const [customLocation, setCustomLocation] = useState('');
  const [search, setSearch] = useState(filters.search);
  const [location, setLocation] = useState(filters.location);
  const [type, setType] = useState(filters.type);
  const isInitialMount = useRef(true);

  // Debounce search input (300ms delay)
  const debouncedSearch = useDebounce(search, 300);

  // Check if current location is not in dropdown
  useEffect(() => {
    if (filters.location && !locations.includes(filters.location)) {
      setShowCustomLocation(true);
      setCustomLocation(filters.location);
    }
  }, [filters.location, locations]);

  // Auto-search when debounced search value changes
  useEffect(() => {
    // Skip the initial mount to prevent unnecessary navigation
    if (isInitialMount.current) {
      isInitialMount.current = false;
      return;
    }

    // Only auto-search if the debounced value is different from filters.search
    if (debouncedSearch !== filters.search) {
      const params = new URLSearchParams(searchParams.toString());
      params.set('view', currentView);
      if (debouncedSearch) {
        params.set('search', debouncedSearch);
      } else {
        params.delete('search');
      }
      router.push(`/?${params.toString()}`);
    }
  }, [debouncedSearch, currentView, searchParams, router, filters.search]);

  // Check if any filters are active
  const hasActiveFilters = filters.location || filters.type || filters.search;

  // Clear all filters at once
  const clearAllFilters = useCallback(() => {
    const params = new URLSearchParams();
    params.set('view', currentView);
    setSearch('');
    setLocation('');
    setType('');
    setShowCustomLocation(false);
    setCustomLocation('');
    router.push(`/?${params.toString()}`);
  }, [currentView, router]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const params = new URLSearchParams();
    params.set('view', currentView);

    const locationValue = showCustomLocation ? customLocation : location;
    if (locationValue) params.set('location', locationValue);
    if (type) params.set('type', type);
    if (search) params.set('search', search);

    router.push(`/?${params.toString()}`);
  };

  const handleLocationChange = (value: string) => {
    if (value === '__custom__') {
      setShowCustomLocation(true);
      setCustomLocation('');
      setLocation('');
    } else {
      setShowCustomLocation(false);
      setLocation(value);
      // Navigate immediately when location is selected
      const params = new URLSearchParams(searchParams.toString());
      params.set('view', currentView);
      if (value) {
        params.set('location', value);
      } else {
        params.delete('location');
      }
      router.push(`/?${params.toString()}`);
    }
  };

  const handleCancelCustomLocation = () => {
    setShowCustomLocation(false);
    setCustomLocation('');
    setLocation('');
  };

  const handleSelectChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const params = new URLSearchParams(searchParams.toString());
    params.set('view', currentView);
    if (e.target.value) {
      params.set(e.target.name, e.target.value);
    } else {
      params.delete(e.target.name);
    }
    router.push(`/?${params.toString()}`);
  };

  const removeFilter = (filterName: string) => {
    const params = new URLSearchParams(searchParams.toString());
    params.delete(filterName);
    params.set('view', currentView);
    if (filterName === 'search') setSearch('');
    if (filterName === 'location') { setLocation(''); setShowCustomLocation(false); setCustomLocation(''); }
    if (filterName === 'type') setType('');
    router.push(`/?${params.toString()}`);
  };

  return (
    <section className="filters-section" role="search" aria-label="Filtrera händelser">
      <div className="search-bar">
        <form className="search-form" onSubmit={handleSubmit} role="search">
          <input type="hidden" name="view" value={currentView} />
          <div className="search-input-wrapper">
            <input
              className="search-input"
              type="search"
              name="search"
              placeholder="Sök händelser..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Sök händelser"
              title="Sök efter nyckelord i händelser (tryck / för snabbsökning)"
            />
            <span className="keyboard-hint" aria-hidden="true">
              <kbd className="kbd">/</kbd>
            </span>
          </div>

          <div className="filter-selects-row">
            {!showCustomLocation ? (
              <select
                className="filter-select"
                name="location"
                value={location}
                onChange={(e) => handleLocationChange(e.target.value)}
                aria-label="Välj plats"
                title="Filtrera efter plats eller välj 'Annan plats' för fritext"
              >
                <option value="">Välj län</option>
                {locations.map((loc) => (
                  <option key={loc} value={loc}>
                    {loc}
                  </option>
                ))}
                <option value="__custom__">Annan plats...</option>
              </select>
            ) : (
              <div className="custom-location-wrapper" style={{ display: 'flex' }}>
                <input
                  className="filter-input"
                  type="text"
                  name="location"
                  placeholder="Skriv plats"
                  value={customLocation}
                  onChange={(e) => setCustomLocation(e.target.value)}
                  autoFocus
                  aria-label="Ange egen plats"
                  title="Skriv in ett platsnamn, t.ex. stad eller område"
                />
                <button
                  type="button"
                  className="custom-location-cancel"
                  onClick={handleCancelCustomLocation}
                  aria-label="Avbryt anpassad plats"
                  title="Återgå till platslistan"
                >
                  ×
                </button>
              </div>
            )}

            <select
              className="filter-select"
              name="type"
              value={type}
              onChange={(e) => {
                setType(e.target.value);
                handleSelectChange(e);
              }}
              aria-label="Välj händelsetyp"
              title="Filtrera efter typ av händelse"
            >
              <option value="">Välj händelsetyp</option>
              {types.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
          </div>

          <button className="btn" type="submit" title="Tillämpa filter">
            Filtrera
          </button>
        </form>
      </div>

      {hasActiveFilters && (
        <div className="active-filters" role="status" aria-live="polite" aria-label="Aktiva filter">
          {filters.location && (
            <span className="filter-tag">
              {filters.location}{' '}
              <button
                type="button"
                className="filter-tag-remove"
                onClick={() => removeFilter('location')}
                aria-label={`Ta bort platsfilter: ${filters.location}`}
                title="Ta bort detta filter"
              >
                ×
              </button>
            </span>
          )}
          {filters.type && (
            <span className="filter-tag">
              {filters.type}{' '}
              <button
                type="button"
                className="filter-tag-remove"
                onClick={() => removeFilter('type')}
                aria-label={`Ta bort typfilter: ${filters.type}`}
                title="Ta bort detta filter"
              >
                ×
              </button>
            </span>
          )}
          {filters.search && (
            <span className="filter-tag">
              &ldquo;{filters.search}&rdquo;{' '}
              <button
                type="button"
                className="filter-tag-remove"
                onClick={() => removeFilter('search')}
                aria-label={`Ta bort sökfilter: ${filters.search}`}
                title="Ta bort detta filter"
              >
                ×
              </button>
            </span>
          )}
          <button
            type="button"
            className="clear-all-filters"
            onClick={clearAllFilters}
            aria-label="Rensa alla filter"
            title="Ta bort alla aktiva filter"
          >
            Rensa alla
          </button>
        </div>
      )}
    </section>
  );
}
