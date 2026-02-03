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
      <nav className="footer-links">
        <a href="https://polisen.se" target="_blank" rel="noopener noreferrer nofollow">
          Polisen
        </a>
        <a href="https://www.domstol.se" target="_blank" rel="noopener noreferrer nofollow">
          Sveriges Domstolar
        </a>
        <a href="https://www.aklagare.se" target="_blank" rel="noopener noreferrer nofollow">
          Åklagarmyndigheten
        </a>
        <a href="https://www.msb.se" target="_blank" rel="noopener noreferrer nofollow">
          MSB
        </a>
        <a href="https://www.krisinformation.se" target="_blank" rel="noopener noreferrer nofollow">
          Krisinformation
        </a>
        <a href="https://www.svt.se/nyheter/vma" target="_blank" rel="noopener noreferrer nofollow">
          VMA
        </a>
      </nav>
    </footer>
  );
}
