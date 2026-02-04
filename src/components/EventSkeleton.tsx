'use client';

interface EventSkeletonProps {
  count?: number;
}

export default function EventSkeleton({ count = 3 }: EventSkeletonProps) {
  return (
    <>
      {Array.from({ length: count }).map((_, index) => (
        <div key={index} className="skeleton-card" aria-hidden="true">
          <div className="skeleton skeleton-meta" />
          <div>
            <div className="skeleton skeleton-type" />
            <div className="skeleton skeleton-location" />
          </div>
          <div className="skeleton skeleton-summary" />
          <div className="skeleton-actions">
            <div className="skeleton skeleton-btn" />
            <div className="skeleton skeleton-btn" />
          </div>
        </div>
      ))}
    </>
  );
}
