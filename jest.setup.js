// Learn more: https://github.com/testing-library/jest-dom
import '@testing-library/jest-dom';

// Mock the leaflet module to avoid SSR issues in tests
jest.mock('leaflet', () => ({
  map: jest.fn(() => ({
    setView: jest.fn().mockReturnThis(),
    fitBounds: jest.fn().mockReturnThis(),
    addLayer: jest.fn().mockReturnThis(),
  })),
  tileLayer: jest.fn(() => ({
    addTo: jest.fn(),
  })),
  featureGroup: jest.fn(() => ({
    addLayer: jest.fn(),
    getLayers: jest.fn(() => []),
    getBounds: jest.fn(),
  })),
  circleMarker: jest.fn(() => ({
    bindPopup: jest.fn().mockReturnThis(),
    setLatLng: jest.fn(),
    addTo: jest.fn(),
  })),
  Control: {
    extend: jest.fn(() => jest.fn(() => ({
      addTo: jest.fn(),
    }))),
  },
  DomUtil: {
    create: jest.fn(() => document.createElement('div')),
  },
}));

// Mock leaflet CSS
jest.mock('leaflet/dist/leaflet.css', () => ({}));
