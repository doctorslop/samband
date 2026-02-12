'use client';

interface FooterProps {
  lastChecked: Date;
}

export default function Footer({ lastChecked }: FooterProps) {
  return (
    <footer>
      <div className="footer-status">
        <div className="status-counts">
          <span className="count-item count-item--checked">
            <span className="last-checked-dot" />
            Senast uppdaterad: <span className="count-value">{lastChecked.toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })}</span>
          </span>
        </div>
      </div>
    </footer>
  );
}
