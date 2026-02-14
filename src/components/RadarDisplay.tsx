'use client';

/**
 * RadarDisplay - Decorative radar scope component.
 * Pure CSS animation, no external dependencies.
 * Only renders when the radar theme is active.
 *
 * Usage:
 *   <RadarDisplay />
 *   <RadarDisplay size="sm" />
 *   <RadarDisplay size="lg" blips={[{x: 30, y: 45}, {x: 70, y: 20}]} />
 */

interface RadarBlip {
  /** Percentage from left (0-100) */
  x: number;
  /** Percentage from top (0-100) */
  y: number;
}

interface RadarDisplayProps {
  size?: 'sm' | 'md' | 'lg';
  blips?: RadarBlip[];
  className?: string;
}

export default function RadarDisplay({
  size = 'md',
  blips = [],
  className = '',
}: RadarDisplayProps) {
  const sizeClass = size === 'md' ? '' : `radar-scope--${size}`;

  return (
    <div
      className={`radar-scope ${sizeClass} ${className}`.trim()}
      role="img"
      aria-label="Radar display"
    >
      <div className="radar-rings">
        <div className="radar-ring" />
        <div className="radar-ring" />
        <div className="radar-ring" />
        <div className="radar-ring" />
      </div>
      <div className="radar-crosshair" />
      <div className="radar-sweep" />
      <div className="radar-center" />
      {blips.map((blip, i) => (
        <div
          key={i}
          className="radar-blip"
          style={{
            left: `${blip.x}%`,
            top: `${blip.y}%`,
            animationDelay: `${(i * 0.8) % 4}s`,
          }}
        />
      ))}
    </div>
  );
}
