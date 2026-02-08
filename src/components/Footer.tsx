'use client';

interface FooterProps {
  totalStored: number;
  total: number;
  shown: number;
  lastChecked: Date;
}

export default function Footer({ totalStored, total, shown, lastChecked }: FooterProps) {
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
          <span className="count-item count-item--checked">
            <span className="last-checked-dot" />
            Senast kontrollerat: <span className="count-value">{lastChecked.toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })}</span>
          </span>
        </div>
      </div>
    </footer>
  );
}
