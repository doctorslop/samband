'use client';

interface FooterProps {
  total: number;
  shown: number;
}

export default function Footer({ total, shown }: FooterProps) {
  return (
    <footer>
      <div className="footer-status">
        <div className="status-counts">
          <span className="count-item">
            Lagrade händelser: <span className="count-value" id="storedCount">{total}</span>
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
