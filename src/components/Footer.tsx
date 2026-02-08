'use client';

interface FooterProps {
  totalStored: number;
  total: number;
  shown: number;
}

export default function Footer({ totalStored, total, shown }: FooterProps) {
  return (
    <footer>
      <div className="footer-status">
        <div className="status-counts">
          <span className="count-item">
            Lagrade händelser: <span className="count-value" id="storedCount">{totalStored}</span>
          </span>
          <span className="count-item">
            Visar: <span className="count-value" id="shownCount">{shown}</span> av{' '}
            <span className="count-value" id="totalFilteredCount">{total}</span>
          </span>
        </div>
      </div>
    </footer>
  );
}
