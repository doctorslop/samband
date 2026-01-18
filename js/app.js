/**
 * Sambandscentralen App
 * Optimized for caching - version 1.0
 */

// App initialization (CONFIG and eventsData must be defined before this script)
(function() {
    'use strict';

    // Views
    const viewBtns = document.querySelectorAll('.view-toggle button');
    const eventsGrid = document.getElementById('eventsGrid');
    const mapContainer = document.getElementById('mapContainer');
    const statsSidebar = document.getElementById('statsSidebar');
    const vmaContainer = document.getElementById('vmaContainer');
    const viewInput = document.getElementById('viewInput');
    let map = null, mapInit = false;
    let vmaInit = false, vmaLoading = false;

    const setView = (v) => {
        viewBtns.forEach(b => b.classList.toggle('active', b.dataset.view === v));
        viewInput.value = v;
        document.body.className = 'view-' + v;
        eventsGrid.style.display = v === 'list' ? 'grid' : 'none';
        mapContainer.classList.toggle('active', v === 'map');
        statsSidebar.classList.toggle('active', v === 'stats');
        vmaContainer.classList.toggle('active', v === 'vma');
        if (v === 'map' && !mapInit) initMap();
        if (v === 'vma' && !vmaInit) loadVmaAlerts();
        history.replaceState(null, '', `?view=${v}${window.CONFIG.filters.location ? '&location=' + encodeURIComponent(window.CONFIG.filters.location) : ''}${window.CONFIG.filters.type ? '&type=' + encodeURIComponent(window.CONFIG.filters.type) : ''}${window.CONFIG.filters.search ? '&search=' + encodeURIComponent(window.CONFIG.filters.search) : ''}`);
    };
    viewBtns.forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));

    // Custom location input toggle
    const locationSelect = document.getElementById('locationSelect');
    const customLocationWrapper = document.getElementById('customLocationWrapper');
    const customLocationInput = document.getElementById('customLocationInput');
    const customLocationCancel = document.getElementById('customLocationCancel');

    if (locationSelect && customLocationWrapper && customLocationInput && customLocationCancel) {
        // Fix: Disable name on custom input initially so only select is submitted
        customLocationInput.removeAttribute('name');

        locationSelect.addEventListener('change', function() {
            if (this.value === '__custom__') {
                // Switch to custom input mode
                locationSelect.style.display = 'none';
                locationSelect.removeAttribute('name');
                customLocationWrapper.style.display = 'flex';
                customLocationInput.setAttribute('name', 'location');
                customLocationInput.value = '';
                customLocationInput.focus();
            } else {
                // Ensure select has name and custom input doesn't
                locationSelect.setAttribute('name', 'location');
                customLocationInput.removeAttribute('name');
            }
        });

        customLocationCancel.addEventListener('click', function() {
            // Switch back to select mode
            customLocationWrapper.style.display = 'none';
            customLocationInput.removeAttribute('name');
            customLocationInput.value = '';
            locationSelect.style.display = '';
            locationSelect.setAttribute('name', 'location');
            locationSelect.value = '';
        });

        // Handle case where page loads with a custom location (not in dropdown)
        if (locationSelect.value === '' && customLocationInput.dataset.initialValue) {
            const initialValue = customLocationInput.dataset.initialValue;
            // Check if value exists in select options
            const optionExists = Array.from(locationSelect.options).some(opt => opt.value === initialValue);
            if (!optionExists && initialValue) {
                // Show custom input with the initial value
                locationSelect.style.display = 'none';
                locationSelect.removeAttribute('name');
                customLocationWrapper.style.display = 'flex';
                customLocationInput.setAttribute('name', 'location');
                customLocationInput.value = initialValue;
            }
        }
    }

    // Map
    function initMap() {
        if (mapInit) return; mapInit = true;
        map = L.map('map').setView([62.5, 17.5], 5);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '© OpenStreetMap', maxZoom: 18 }).addTo(map);

        // Filter events to last 24 hours only using the correct event time
        const now = new Date();
        const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);
        const recentEvents = window.eventsData.filter(e => {
            // Use date.iso for consistent event time, fallback to datetime
            const eventTimeStr = (e.date && e.date.iso) || e.datetime;
            if (!eventTimeStr) return false;
            const eventDate = new Date(eventTimeStr);
            return !isNaN(eventDate.getTime()) && eventDate >= yesterday && eventDate <= now;
        });

        const markers = L.layerGroup();
        let eventCount = 0;
        recentEvents.forEach(e => {
            if (e.gps) {
                const [lat, lng] = e.gps.split(',').map(Number);
                if (!isNaN(lat) && !isNaN(lng)) {
                    eventCount++;
                    const eventTimeStr = (e.date && e.date.iso) || e.datetime;
                    const eventDate = new Date(eventTimeStr);
                    const diffMs = now - eventDate;
                    const diffMins = Math.floor(diffMs / 60000);
                    const diffHours = Math.floor(diffMs / 3600000);
                    let relTime = diffMins <= 1 ? 'Just nu' : diffMins < 60 ? `${diffMins} min sedan` : `${diffHours} timmar sedan`;

                    const googleMapsUrl = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
                    const summaryText = escHtml(e.summary || '');
                    const summaryPreview = summaryText.length > 120 ? `${summaryText.substring(0, 120)}...` : summaryText;
                    const safeType = escHtml(e.type || '');
                    const safeName = escHtml(e.name || '');
                    const safeLocation = escHtml(e.location || '');

                    const m = L.circleMarker([lat, lng], { radius: 8, fillColor: e.color, color: '#fff', weight: 2, opacity: 1, fillOpacity: 0.85 });
                    m.bindPopup(`<div class="map-popup"><span class="badge" style="background:${e.color}20;color:${e.color}">${e.icon} ${safeType}</span><div class="popup-time">🕐 ${relTime}</div><h3>${safeName}</h3><p>${summaryPreview}</p><p><strong>📍 ${safeLocation}</strong></p><div class="popup-links"><a href="${googleMapsUrl}" target="_blank" rel="noopener noreferrer">🗺️ Google Maps</a>${e.url ? `<a href="https://polisen.se${e.url}" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer">📄 Läs mer</a>` : ''}</div></div>`);
                    markers.addLayer(m);
                }
            }
        });
        map.addLayer(markers);

        const info = L.control({position: 'topright'});
        info.onAdd = function() {
            const div = L.DomUtil.create('div', 'map-info');
            div.innerHTML = `<div class="map-info-content">📍 ${eventCount} händelser<br><small>senaste 24 timmarna</small></div>`;
            return div;
        };
        info.addTo(map);

        if (markers.getLayers().length) map.fitBounds(markers.getBounds(), { padding: [40, 40] });
    }

    // VMA (Viktigt Meddelande till Allmänheten) functionality
    const vmaContent = document.getElementById('vmaContent');
    const vmaRefreshBtn = document.getElementById('vmaRefreshBtn');

    async function loadVmaAlerts(forceRefresh = false) {
        if (vmaLoading) return;
        if (vmaInit && !forceRefresh) return;

        vmaLoading = true;
        vmaInit = true;

        // Show loading state
        vmaContent.innerHTML = `
            <div class="vma-loading">
                <div class="spinner"></div>
                <p>Hämtar VMA-meddelanden...</p>
            </div>
        `;

        if (vmaRefreshBtn) {
            vmaRefreshBtn.disabled = true;
            vmaRefreshBtn.innerHTML = '<span class="spinner-small"></span> Laddar...';
        }

        try {
            const res = await fetch('?ajax=vma');
            const data = await res.json();

            if (!data.success) {
                throw new Error(data.error || 'Kunde inte hämta VMA');
            }

            renderVmaAlerts(data);
        } catch (err) {
            console.error('VMA fetch error:', err);
            vmaContent.innerHTML = `
                <div class="vma-error">
                    <div class="vma-error-icon">⚠️</div>
                    <h3>Kunde inte hämta VMA</h3>
                    <p>${escHtml(err.message || 'Ett fel uppstod vid hämtning av VMA-meddelanden.')}</p>
                    <button type="button" class="btn" onclick="window.retryVma()">Försök igen</button>
                </div>
            `;
        } finally {
            vmaLoading = false;
            if (vmaRefreshBtn) {
                vmaRefreshBtn.disabled = false;
                vmaRefreshBtn.innerHTML = '🔄 Uppdatera';
            }
        }
    }

    // Expose retry function globally
    window.retryVma = () => loadVmaAlerts(true);

    function renderVmaAlerts(data) {
        const { current, recent } = data;
        const hasCurrentAlerts = current && current.length > 0;
        const hasRecentAlerts = recent && recent.length > 0;

        if (!hasCurrentAlerts && !hasRecentAlerts) {
            vmaContent.innerHTML = `
                <div class="vma-empty">
                    <div class="vma-empty-icon">✓</div>
                    <h3>Inga VMA-meddelanden</h3>
                    <p>Det finns inga aktiva eller nyliga VMA-meddelanden just nu. Systemet övervakas kontinuerligt.</p>
                </div>
            `;
            return;
        }

        let html = '';

        // Current/Active alerts section
        if (hasCurrentAlerts) {
            html += `
                <div class="vma-section vma-active-section">
                    <div class="vma-section-header">
                        <h3>🚨 Aktiva VMA</h3>
                        <span class="vma-section-count">${current.length}</span>
                    </div>
                    <div class="vma-alerts-list">
                        ${current.map(alert => renderVmaAlertCard(alert, true)).join('')}
                    </div>
                </div>
            `;
        } else {
            html += `
                <div class="vma-no-active">
                    <div class="vma-no-active-icon">✓</div>
                    <h4>Inga aktiva VMA</h4>
                    <p>Det finns inga pågående VMA-meddelanden just nu.</p>
                </div>
            `;
        }

        // Recent/Historical alerts section
        if (hasRecentAlerts) {
            html += `
                <div class="vma-section">
                    <div class="vma-section-header">
                        <h3>📜 Senaste VMA</h3>
                        <span class="vma-section-count">${recent.length}</span>
                    </div>
                    <div class="vma-alerts-list">
                        ${recent.map(alert => renderVmaAlertCard(alert, false)).join('')}
                    </div>
                </div>
            `;
        }

        vmaContent.innerHTML = html;
    }

    function renderVmaAlertCard(alert, isActive) {
        const statusClass = isActive ? 'vma-alert-status--active' : 'vma-alert-status--inactive';
        const statusText = isActive ? '🔴 Aktiv' : 'Avslutad';
        const cardClass = isActive ? 'vma-alert vma-alert--active' : 'vma-alert';

        const instructionHtml = alert.instruction ? `
            <div class="vma-alert-instruction">
                <div class="vma-alert-instruction-title">📋 Instruktioner</div>
                <div class="vma-alert-instruction-text">${escHtml(alert.instruction)}</div>
            </div>
        ` : '';

        const webLinkHtml = alert.web ? `
            <a href="${escHtml(alert.web)}" target="_blank" rel="noopener noreferrer" class="vma-alert-link">
                🔗 Läs mer på SR
            </a>
        ` : '';

        return `
            <article class="${cardClass}" data-id="${escHtml(alert.id)}">
                <div class="vma-alert-header" tabindex="0" role="button" aria-expanded="false">
                    <div class="vma-alert-meta">
                        <span class="vma-alert-status ${statusClass}">${statusText}</span>
                        <span class="vma-severity ${escHtml(alert.severityClass)}">${escHtml(alert.severityLabel)}</span>
                        <span class="vma-alert-time">
                            ${alert.sentDate ? escHtml(alert.sentDate) : ''}
                            ${alert.relativeTime ? `<span class="vma-alert-time-relative"> • ${escHtml(alert.relativeTime)}</span>` : ''}
                        </span>
                    </div>
                    <h3 class="vma-alert-headline">${escHtml(alert.headline)}</h3>
                    <div class="vma-alert-areas">
                        <span class="vma-alert-areas-icon">📍</span>
                        <span>${escHtml(alert.areaText)}</span>
                    </div>
                    ${alert.description ? `<p class="vma-alert-description">${escHtml(alert.description)}</p>` : ''}
                </div>
                <div class="vma-alert-body">
                    ${alert.description ? `<div class="vma-alert-full-description">${escHtml(alert.description)}</div>` : ''}
                    ${instructionHtml}
                    <div class="vma-alert-footer">
                        <div class="vma-alert-info">
                            <span class="vma-alert-info-item">📝 ${escHtml(alert.msgTypeLabel)}</span>
                            ${alert.urgency && alert.urgency !== 'Unknown' ? `<span class="vma-alert-info-item">⏱️ ${escHtml(alert.urgency)}</span>` : ''}
                            ${alert.certainty && alert.certainty !== 'Unknown' ? `<span class="vma-alert-info-item">📊 ${escHtml(alert.certainty)}</span>` : ''}
                        </div>
                        ${webLinkHtml}
                    </div>
                </div>
            </article>
        `;
    }

    // VMA alert accordion click handler
    document.addEventListener('click', function(e) {
        const header = e.target.closest('.vma-alert-header');
        if (!header) return;
        if (e.target.closest('a')) return;

        const alert = header.closest('.vma-alert');
        if (alert) {
            const isExpanded = alert.classList.contains('expanded');
            alert.classList.toggle('expanded');
            header.setAttribute('aria-expanded', !isExpanded);
        }
    });

    // VMA refresh button handler
    if (vmaRefreshBtn) {
        vmaRefreshBtn.addEventListener('click', () => loadVmaAlerts(true));
    }

    // Type class mapping for dynamic cards
    const typeClassMap = {
        'Inbrott': 'event-type--inbrott',
        'Brand': 'event-type--brand',
        'Rån': 'event-type--ran',
        'Trafikolycka': 'event-type--trafikolycka',
        'Misshandel': 'event-type--misshandel',
        'Skadegörelse': 'event-type--skadegorelse',
        'Bedrägeri': 'event-type--bedrageri',
        'Narkotikabrott': 'event-type--narkotikabrott',
        'Ofredande': 'event-type--ofredande',
        'Sammanfattning': 'event-type--sammanfattning',
        'Stöld': 'event-type--stold',
        'Stöld/inbrott': 'event-type--stold',
        'Mord/dråp': 'event-type--mord',
        'Rattfylleri': 'event-type--ratta'
    };
    function getTypeClass(type) {
        if (typeClassMap[type]) return typeClassMap[type];
        for (const key in typeClassMap) {
            if (type.toLowerCase().includes(key.toLowerCase())) return typeClassMap[key];
        }
        return 'event-type--default';
    }

    // Load More Button
    let page = 1, loading = false, hasMore = window.CONFIG.hasMore;
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    const shownCountEl = document.getElementById('shownCount');

    function updateLoadMoreButton() {
        if (!hasMore) {
            loadMoreBtn.classList.add('hidden');
        } else {
            loadMoreBtn.classList.remove('hidden');
        }
    }

    async function loadMore() {
        if (loading || !hasMore) return;
        loading = true;
        loadMoreBtn.disabled = true;
        loadMoreBtn.classList.add('loading');
        loadMoreBtn.innerHTML = '<span class="spinner-small"></span> Laddar...';
        page++;
        try {
            const res = await fetch(`?ajax=events&page=${page}&location=${encodeURIComponent(window.CONFIG.filters.location)}&type=${encodeURIComponent(window.CONFIG.filters.type)}&search=${encodeURIComponent(window.CONFIG.filters.search)}`);
            const data = await res.json();
            if (data.error) { console.error(data.error); return; }
            hasMore = data.hasMore;
            data.events.forEach((e, i) => {
                const card = document.createElement('article');
                card.className = 'event-card';
                card.dataset.url = e.url || '';
                card.style.animationDelay = `${i * 0.02}s`;
                let gpsBtn = '';
                if (e.gps) {
                    const [lat, lng] = e.gps.split(',').map(s => parseFloat(s.trim()));
                    if (!isNaN(lat) && !isNaN(lng)) {
                        gpsBtn = `<button type="button" class="show-map-link" data-lat="${lat}" data-lng="${lng}" data-location="${escHtml(e.location)}">🗺️ Visa på karta</button>`;
                    }
                }
                const updatedHtml = e.wasUpdated && e.updated ? `<span class="updated-indicator" title="Uppdaterad ${escHtml(e.updated)}">✎ uppdaterad</span>` : '';
                const typeClass = getTypeClass(e.type);
                const sourceHtml = e.url ? `<span class="meta-separator">•</span><a class="event-source" href="https://polisen.se${escHtml(e.url)}" target="_blank" rel="noopener noreferrer nofollow" referrerpolicy="no-referrer" onclick="event.stopPropagation()">🔗 polisen.se</a>` : '';
                const expandBtn = `<button type="button" class="expand-details-btn">📖 Läs mer</button>`;
                card.innerHTML = `<div class="event-card-header" tabindex="0" role="button" aria-expanded="false" aria-label="Expandera händelse: ${escHtml(e.type)} i ${escHtml(e.location)}"><div class="event-header-content"><div class="event-meta-row"><span class="event-datetime">${e.date.day} ${e.date.month} ${e.date.time}</span><span class="meta-separator">•</span><span class="event-relative">${e.date.relative}</span>${sourceHtml}${updatedHtml ? `<span class="meta-separator">•</span>${updatedHtml}` : ''}</div><div class="event-title-group"><a href="?type=${encodeURIComponent(e.type)}&view=${viewInput.value}" class="event-type ${typeClass}" onclick="event.stopPropagation()">${e.icon} ${escHtml(e.type)}</a><a href="?location=${encodeURIComponent(e.location)}&view=${viewInput.value}" class="event-location-link" onclick="event.stopPropagation()">${escHtml(e.location)}</a></div><p class="event-summary">${escHtml(e.summary)}</p><div class="event-header-actions">${expandBtn}${gpsBtn}</div></div><span class="accordion-chevron"></span></div><div class="event-card-body"><div class="event-details"></div></div>`;
                eventsGrid.appendChild(card);
            });
            // Update shown count
            if (shownCountEl) {
                const currentCount = eventsGrid.querySelectorAll('.event-card').length;
                shownCountEl.textContent = currentCount;
            }
            updateLoadMoreButton();
        } catch (err) { console.error(err); } finally {
            loading = false;
            loadMoreBtn.disabled = false;
            loadMoreBtn.classList.remove('loading');
            loadMoreBtn.textContent = 'Ladda fler';
        }
    }

    function escHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

    // Load more button click handler
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', loadMore);
        updateLoadMoreButton();
    }

    // Filter auto-submit
    document.querySelectorAll('.filter-select').forEach(s => s.addEventListener('change', () => s.form.submit()));

    // Scroll & Refresh
    const scrollTop = document.getElementById('scrollTop');
    const header = document.querySelector('header');
    let lastScrollY = 0;
    let ticking = false;
    const compactThreshold = 50;   // Start compacting after 50px scroll
    const collapseThreshold = 150; // Start hiding after 150px scroll
    const scrollUpBuffer = 10;     // Scroll up this much before showing header

    function updateHeader() {
        const currentScrollY = window.scrollY;
        const scrollingDown = currentScrollY > lastScrollY;
        const isDesktop = window.innerWidth >= 769;

        // Show scroll-to-top button
        scrollTop.classList.toggle('visible', currentScrollY > 300);

        // Header behavior only on desktop
        if (isDesktop) {
            if (currentScrollY <= 10) {
                // At top of page - full header
                header.classList.remove('header-compact', 'header-collapsed');
            } else if (scrollingDown) {
                // Scrolling down
                if (currentScrollY > compactThreshold) {
                    header.classList.add('header-compact');
                }
                if (currentScrollY > collapseThreshold) {
                    header.classList.add('header-collapsed');
                    header.classList.remove('header-show');
                }
            } else {
                // Scrolling up - show header
                if (lastScrollY - currentScrollY > scrollUpBuffer || currentScrollY < collapseThreshold) {
                    header.classList.remove('header-collapsed');
                    header.classList.add('header-show');
                }
                // Only remove compact when near top
                if (currentScrollY <= compactThreshold) {
                    header.classList.remove('header-compact');
                }
            }
        } else {
            // On mobile, remove desktop scroll classes
            header.classList.remove('header-compact', 'header-collapsed', 'header-show');
        }

        lastScrollY = currentScrollY;
        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    }, { passive: true });

    scrollTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    setInterval(() => { if (!document.hidden) location.reload(); }, 300000);

    // PWA Install
    let deferredPrompt;
    const installPrompt = document.getElementById('installPrompt');
    window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; if (!localStorage.getItem('installDismissed')) setTimeout(() => installPrompt.classList.add('show'), 20000); });
    document.getElementById('installBtn')?.addEventListener('click', async () => { if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; installPrompt.classList.remove('show'); });
    document.getElementById('dismissInstall')?.addEventListener('click', () => { installPrompt.classList.remove('show'); localStorage.setItem('installDismissed', 'true'); });

    // Service Worker registration with relative path
    if ('serviceWorker' in navigator) {
        const swPath = (window.CONFIG.basePath || '') + '/js/sw.js';
        navigator.serviceWorker.register(swPath).catch(() => {});
    }

    // Keyboard
    document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); document.getElementById('searchInput')?.focus(); } });

    // Event accordion - details cache keyed by URL
    const detailsCache = {};

    async function toggleAccordion(card) {
        if (!card) return;
        const header = card.querySelector('.event-card-header');
        const detailsDiv = card.querySelector('.event-details');
        const expandBtn = card.querySelector('.expand-details-btn');
        if (!header || !detailsDiv) return;

        const eventUrl = card.dataset.url;
        const isExpanded = card.classList.contains('expanded');

        // Toggle expanded state
        if (isExpanded) {
            card.classList.remove('expanded');
            detailsDiv.classList.remove('visible');
            header.setAttribute('aria-expanded', 'false');
            if (expandBtn) expandBtn.innerHTML = '📖 Läs mer';
            return;
        }

        // Expand the card
        card.classList.add('expanded');
        detailsDiv.classList.add('visible');
        header.setAttribute('aria-expanded', 'true');
        if (expandBtn) expandBtn.innerHTML = '📖 Dölj';

        // Skip fetching if no URL or already has content
        if (!eventUrl || detailsDiv.textContent.trim()) return;

        // Check cache first
        if (detailsCache[eventUrl]) {
            detailsDiv.textContent = detailsCache[eventUrl];
            detailsDiv.classList.remove('error');
            return;
        }

        // Fetch details from server (lazy load on first expand)
        detailsDiv.textContent = 'Laddar detaljer...';
        detailsDiv.classList.add('loading');
        detailsDiv.classList.remove('error');

        // Add loading state to button
        if (expandBtn) expandBtn.classList.add('loading');

        try {
            const res = await fetch('?ajax=details&url=' + encodeURIComponent(eventUrl));
            const data = await res.json();

            if (data.success && data.details && data.details.content) {
                detailsCache[eventUrl] = data.details.content;
                detailsDiv.textContent = data.details.content;
                detailsDiv.classList.remove('error', 'loading');
            } else {
                detailsDiv.textContent = 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.';
                detailsDiv.classList.add('error');
                detailsDiv.classList.remove('loading');
            }
        } catch (err) {
            detailsDiv.textContent = 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.';
            detailsDiv.classList.add('error');
            detailsDiv.classList.remove('loading');
        } finally {
            if (expandBtn) expandBtn.classList.remove('loading');
        }
    }

    // Click handler for expand button and accordion headers
    document.addEventListener('click', function(e) {
        // Handle expand button clicks
        const expandBtn = e.target.closest('.expand-details-btn');
        if (expandBtn) {
            e.stopPropagation();
            const card = expandBtn.closest('.event-card');
            if (card) toggleAccordion(card);
            return;
        }

        // Handle header clicks (but not on buttons/links)
        const header = e.target.closest('.event-card-header');
        if (!header) return;
        if (e.target.closest('a, button')) return;

        const card = header.closest('.event-card');
        if (card) toggleAccordion(card);
    });

    // Keyboard handler for expand button
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;

        const expandBtn = e.target.closest('.expand-details-btn');
        if (expandBtn) {
            if (e.key === ' ') e.preventDefault();
            const card = expandBtn.closest('.event-card');
            if (card) toggleAccordion(card);
        }
    });

    // Map Modal
    const mapModalOverlay = document.getElementById('mapModalOverlay');
    const mapModalTitle = document.getElementById('mapModalTitle');
    const mapModalCoords = document.getElementById('mapModalCoords');
    const mapModalGoogleLink = document.getElementById('mapModalGoogleLink');
    const mapModalAppleLink = document.getElementById('mapModalAppleLink');
    const mapModalClose = document.getElementById('mapModalClose');
    let modalMap = null;
    let modalMarker = null;

    function openMapModal(lat, lng, location) {
        mapModalTitle.textContent = '📍 ' + (location || 'Plats');
        mapModalCoords.textContent = `${lat}, ${lng}`;
        mapModalGoogleLink.href = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
        mapModalAppleLink.href = `https://maps.apple.com/?ll=${lat},${lng}&q=${encodeURIComponent(location || 'Plats')}`;
        mapModalOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';

        setTimeout(() => {
            if (!modalMap) {
                modalMap = L.map('modalMap').setView([lat, lng], 14);
                L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                    attribution: '© OpenStreetMap', maxZoom: 18
                }).addTo(modalMap);
            } else {
                modalMap.setView([lat, lng], 14);
            }

            if (modalMarker) {
                modalMarker.setLatLng([lat, lng]);
            } else {
                modalMarker = L.circleMarker([lat, lng], {
                    radius: 12, fillColor: '#3b82f6', color: '#fff', weight: 3, opacity: 1, fillOpacity: 0.9
                }).addTo(modalMap);
            }
            modalMap.invalidateSize();
        }, 50);
    }

    function closeMapModal() {
        mapModalOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.show-map-link');
        if (btn) {
            e.stopPropagation(); // Prevent accordion toggle
            const lat = parseFloat(btn.dataset.lat);
            const lng = parseFloat(btn.dataset.lng);
            const location = btn.dataset.location || '';
            if (!isNaN(lat) && !isNaN(lng)) {
                openMapModal(lat, lng, location);
            }
        }
    });

    mapModalClose.addEventListener('click', closeMapModal);
    mapModalOverlay.addEventListener('click', (e) => {
        if (e.target === mapModalOverlay) closeMapModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && mapModalOverlay.classList.contains('active')) closeMapModal();
    });

    // Radio easter egg (only on logo icon, not text)
    (function() {
        const logoIcon = document.querySelector('.logo-icon');
        let audio = null;
        logoIcon.style.cursor = 'pointer';
        logoIcon.title = '';
        logoIcon.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!audio) {
                audio = new Audio('sound/radio.mp3');
                audio.volume = 0.5;
                audio.addEventListener('ended', () => logoIcon.classList.remove('radio-playing'));
            }
            if (audio.paused) {
                audio.play().then(() => logoIcon.classList.add('radio-playing')).catch(() => {});
            } else {
                audio.pause();
                logoIcon.classList.remove('radio-playing');
            }
        });
    })();

    // Init view
    if (window.CONFIG.currentView !== 'list') setView(window.CONFIG.currentView);

    // Load VMA on page load if vma view is active
    if (window.CONFIG.currentView === 'vma') {
        loadVmaAlerts();
    }
})();
