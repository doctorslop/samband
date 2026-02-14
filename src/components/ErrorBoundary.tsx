'use client';

import { Component, ReactNode } from 'react';

interface ErrorBoundaryProps {
  children: ReactNode;
  fallback?: ReactNode;
}

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

export default class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    console.error('ErrorBoundary caught an error:', error, info.componentStack);
  }

  handleRetry = () => {
    this.setState({ hasError: false, error: null });
  };

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback;
      }

      return (
        <div className="error-boundary">
          <div className="error-boundary-content">
            <h2>Något gick fel</h2>
            <p>Ett oväntat fel uppstod. Försök ladda om sidan.</p>
            <div className="error-boundary-actions">
              <button onClick={this.handleRetry} className="error-boundary-btn">
                Försök igen
              </button>
              <button onClick={() => window.location.reload()} className="error-boundary-btn error-boundary-btn-secondary">
                Ladda om sidan
              </button>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
