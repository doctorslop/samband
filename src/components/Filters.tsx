'use client';

import { useState, useEffect } from 'react';
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

export default function Filters({ locations, types, currentView, filters }: FiltersProps) {
  const router = useRouter();
  const searchParams = useSearchParams();

  const [showCustomLocation, setShowCustomLocation] = useState(false);
  const [customLocation, setCustomLocation] = useState('');
  const [search, setSearch] = useState(filters.search);
  const [location, setLocation] = useState(filters.location);
  const [type, setType] = useState(filters.type);

  // Check if current location is not in dropdown
  useEffect(() => {
    if (filters.location && !locations.includes(filters.location)) {
      setShowCustomLocation(true);
      setCustomLocation(filters.location);
    }
  }, [filters.location, locations]);

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
    router.push(`/?${params.toString()}`);
  };

  return (
    <section className="filters-section">
      <div className="search-bar">
        <form className="search-form" onSubmit={handleSubmit}>
          <input type="hidden" name="view" value={currentView} />
          <div className="search-input-wrapper">
            <input
              className="search-input"
              type="search"
              name="search"
              placeholder="Sök händelser..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>

          {!showCustomLocation ? (
            <select
              className="filter-select"
              name="location"
              value={location}
              onChange={(e) => handleLocationChange(e.target.value)}
            >
              <option value="">Alla platser</option>
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
              />
              <button
                type="button"
                className="custom-location-cancel"
                onClick={handleCancelCustomLocation}
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
          >
            <option value="">Alla typer</option>
            {types.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>

          <button className="btn" type="submit">
            Filtrera
          </button>
        </form>
      </div>

      {(filters.location || filters.type || filters.search) && (
        <div className="active-filters">
          {filters.location && (
            <span className="filter-tag">
              📍 {filters.location}{' '}
              <a href="#" onClick={(e) => { e.preventDefault(); removeFilter('location'); }}>
                ×
              </a>
            </span>
          )}
          {filters.type && (
            <span className="filter-tag">
              🏷️ {filters.type}{' '}
              <a href="#" onClick={(e) => { e.preventDefault(); removeFilter('type'); }}>
                ×
              </a>
            </span>
          )}
          {filters.search && (
            <span className="filter-tag">
              🔍 {filters.search}{' '}
              <a href="#" onClick={(e) => { e.preventDefault(); removeFilter('search'); }}>
                ×
              </a>
            </span>
          )}
        </div>
      )}
    </section>
  );
}
