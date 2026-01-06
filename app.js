/**
 * Sambandscentralen App
 * Version 1.1 - Enhanced user engagement
 */

// App initialization (CONFIG and eventsData must be defined before this script)
(function() {
    'use strict';

    // ========================================
    // Watched Regions System
    // ========================================
    const WatchedRegions = {
        STORAGE_KEY: 'sambandscentralen_watched_regions',

        get() {
            try {
                return JSON.parse(localStorage.getItem(this.STORAGE_KEY)) || [];
            } catch { return []; }
        },

        save(regions) {
            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(regions));
            this.updateUI();
        },

        add(region) {
            const regions = this.get();
            if (!regions.includes(region)) {
                regions.push(region);
                this.save(regions);
                return true;
            }
            return false;
        },

        remove(region) {
            const regions = this.get().filter(r => r !== region);
            this.save(regions);
        },

        toggle(region) {
            if (this.get().includes(region)) {
                this.remove(region);
                return false;
            } else {
                this.add(region);
                return true;
            }
        },

        isWatching(region) {
            return this.get().includes(region);
        },

        updateUI() {
            const regions = this.get();
            const countEl = document.getElementById('watchedCount');
            if (countEl) {
                countEl.textContent = regions.length;
                countEl.dataset.count = regions.length;
            }

            // Update all watch buttons
            document.querySelectorAll('.watch-region-btn').forEach(btn => {
                const region = btn.dataset.region;
                btn.classList.toggle('watching', regions.includes(region));
            });

            // Update modal list
            const listEl = document.getElementById('watchedRegionsList');
            const emptyEl = document.getElementById('watchedEmpty');
            if (listEl && emptyEl) {
                if (regions.length === 0) {
                    listEl.innerHTML = '';
                    emptyEl.style.display = 'block';
                } else {
                    emptyEl.style.display = 'none';
                    listEl.innerHTML = regions.map(region => `
                        <div class="watched-region-item">
                            <span class="watched-region-name">📍 ${escHtml(region)}</span>
                            <button class="watched-region-filter" data-region="${escHtml(region)}">Filtrera</button>
                            <button class="watched-region-remove" data-region="${escHtml(region)}">&times;</button>
                        </div>
                    `).join('');
                }
            }
        }
    };

    // ========================================
    // New Events Checker
    // ========================================
    const NewEventsChecker = {
        lastEventId: null,
        checkInterval: 60000, // Check every 60 seconds
        intervalId: null,

        init() {
            // Store the ID of the first event as reference
            if (window.eventsData && window.eventsData.length > 0) {
                this.lastEventId = this.getEventId(window.eventsData[0]);
            }
            this.startChecking();
        },

        getEventId(event) {
            // Create a unique ID from event properties
            return `${event.datetime}_${event.location}_${event.type}`;
        },

        startChecking() {
            if (this.intervalId) clearInterval(this.intervalId);
            this.intervalId = setInterval(() => this.check(), this.checkInterval);
        },

        async check() {
            if (document.hidden) return; // Don't check when tab is hidden

            try {
                const res = await fetch(`?ajax=events&page=1&location=&type=&search=`);
                const data = await res.json();

                if (data.events && data.events.length > 0) {
                    const newFirstId = this.getEventId(data.events[0]);
                    if (this.lastEventId && newFirstId !== this.lastEventId) {
                        // Count how many new events
                        let newCount = 0;
                        for (const event of data.events) {
                            if (this.getEventId(event) === this.lastEventId) break;
                            newCount++;
                        }
                        if (newCount > 0) {
                            this.showToast(newCount);
                        }
                    }
                }
            } catch (err) {
                console.error('Failed to check for new events:', err);
            }
        },

        showToast(count) {
            const toast = document.getElementById('newEventsToast');
            const message = document.getElementById('toastMessage');
            if (toast && message) {
                message.textContent = `${count} ${count === 1 ? 'ny händelse' : 'nya händelser'}`;
                toast.classList.add('show');
            }
        },

        hideToast() {
            const toast = document.getElementById('newEventsToast');
            if (toast) toast.classList.remove('show');
        },

        refresh() {
            this.hideToast();
            location.reload();
        }
    };

    // ========================================
    // Share Functionality
    // ========================================
    async function shareEvent(title, text, url) {
        const shareData = { title, text };
        if (url) shareData.url = url;

        if (navigator.share) {
            try {
                await navigator.share(shareData);
            } catch (err) {
                if (err.name !== 'AbortError') {
                    fallbackShare(title, text, url);
                }
            }
        } else {
            fallbackShare(title, text, url);
        }
    }

    function fallbackShare(title, text, url) {
        // Fallback: copy to clipboard
        const shareText = `${title}\n${text}${url ? '\n' + url : ''}`;
        navigator.clipboard.writeText(shareText).then(() => {
            showCopiedFeedback();
        }).catch(() => {
            // Last resort: show in prompt
            prompt('Kopiera texten:', shareText);
        });
    }

    function showCopiedFeedback() {
        const feedback = document.createElement('div');
        feedback.className = 'copy-feedback';
        feedback.textContent = 'Kopierat till urklipp!';
        feedback.style.cssText = `
            position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%);
            background: var(--accent); color: var(--primary); padding: 10px 20px;
            border-radius: 8px; font-size: 13px; font-weight: 600; z-index: 1000;
            animation: fadeInOut 2s ease-out forwards;
        `;
        document.body.appendChild(feedback);
        setTimeout(() => feedback.remove(), 2000);
    }

    // Views
    const viewBtns = document.querySelectorAll('.view-toggle button');
    const eventsGrid = document.getElementById('eventsGrid');
    const mapContainer = document.getElementById('mapContainer');
    const statsSidebar = document.getElementById('statsSidebar');
    const pressSection = document.getElementById('pressSection');
    const viewInput = document.getElementById('viewInput');
    let map = null, mapInit = false, pressInit = false;

    const setView = (v) => {
        viewBtns.forEach(b => b.classList.toggle('active', b.dataset.view === v));
        viewInput.value = v;
        document.body.className = 'view-' + v;
        eventsGrid.style.display = v === 'list' ? 'grid' : 'none';
        mapContainer.classList.toggle('active', v === 'map');
        statsSidebar.classList.toggle('active', v === 'stats');
        pressSection.classList.toggle('active', v === 'press');
        if (v === 'map' && !mapInit) initMap();
        if (v === 'press' && !pressInit) loadPressReleases();
        history.replaceState(null, '', `?view=${v}${window.CONFIG.filters.location ? '&location=' + encodeURIComponent(window.CONFIG.filters.location) : ''}${window.CONFIG.filters.type ? '&type=' + encodeURIComponent(window.CONFIG.filters.type) : ''}${window.CONFIG.filters.search ? '&search=' + encodeURIComponent(window.CONFIG.filters.search) : ''}`);
    };
    viewBtns.forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));

    // Map
    function initMap() {
        if (mapInit) return; mapInit = true;
        map = L.map('map').setView([62.5, 17.5], 5);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '© OpenStreetMap', maxZoom: 18 }).addTo(map);

        // Filter events to last 24 hours only
        const now = new Date();
        const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);
        const recentEvents = window.eventsData.filter(e => {
            if (!e.datetime) return false;
            const eventDate = new Date(e.datetime);
            return eventDate >= yesterday && eventDate <= now;
        });

        const markers = L.layerGroup();
        let eventCount = 0;
        recentEvents.forEach(e => {
            if (e.gps) {
                const [lat, lng] = e.gps.split(',').map(Number);
                if (!isNaN(lat) && !isNaN(lng)) {
                    eventCount++;
                    const eventDate = new Date(e.datetime);
                    const diffMs = now - eventDate;
                    const diffMins = Math.floor(diffMs / 60000);
                    const diffHours = Math.floor(diffMs / 3600000);
                    let relTime = diffMins <= 1 ? 'Just nu' : diffMins < 60 ? `${diffMins} min sedan` : `${diffHours} timmar sedan`;

                    const googleMapsUrl = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;

                    const m = L.circleMarker([lat, lng], { radius: 8, fillColor: e.color, color: '#fff', weight: 2, opacity: 1, fillOpacity: 0.85 });
                    m.bindPopup(`<div class="map-popup"><span class="badge" style="background:${e.color}20;color:${e.color}">${e.icon} ${e.type}</span><div class="popup-time">🕐 ${relTime}</div><h3>${e.name}</h3><p>${e.summary.substring(0, 120)}${e.summary.length > 120 ? '...' : ''}</p><p><strong>📍 ${e.location}</strong></p><div class="popup-links"><a href="${googleMapsUrl}" target="_blank" rel="noopener">🗺️ Google Maps</a>${e.url ? `<a href="https://polisen.se${e.url}" target="_blank" rel="noopener">📄 Läs mer</a>` : ''}</div></div>`);
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

    // Infinite Scroll
    let page = 1, loading = false, hasMore = window.CONFIG.hasMore;
    const loadingEl = document.getElementById('loadingMore');

    async function loadMore() {
        if (loading || !hasMore) return;
        loading = true; loadingEl.style.display = 'flex'; page++;
        try {
            const res = await fetch(`?ajax=events&page=${page}&location=${encodeURIComponent(window.CONFIG.filters.location)}&type=${encodeURIComponent(window.CONFIG.filters.type)}&search=${encodeURIComponent(window.CONFIG.filters.search)}`);
            const data = await res.json();
            if (data.error) { console.error(data.error); return; }
            hasMore = data.hasMore;
            data.events.forEach((e, i) => {
                const card = document.createElement('article');
                card.className = 'event-card';
                card.style.animationDelay = `${i * 0.02}s`;
                let gpsBtn = '';
                if (e.gps) {
                    const [lat, lng] = e.gps.split(',').map(s => s.trim());
                    if (lat && lng) {
                        gpsBtn = `<button type="button" class="show-map-btn" data-lat="${lat}" data-lng="${lng}" data-location="${escHtml(e.location)}">🗺️ Visa på karta</button>`;
                    }
                }
                const isWatching = WatchedRegions.isWatching(e.location);
                const shareUrl = e.url ? `https://polisen.se${e.url}` : '';
                card.innerHTML = `<div class="event-card-inner"><div class="event-date"><div class="day">${e.date.day}</div><div class="month">${e.date.month}</div><div class="time">${e.date.time}</div><div class="relative">${e.date.relative}</div></div><div class="event-content"><div class="event-header"><div class="event-title-group"><a href="?type=${encodeURIComponent(e.type)}&view=${viewInput.value}" class="event-type" style="background:${e.color}20;color:${e.color}">${e.icon} ${escHtml(e.type)}</a><div class="event-location-row"><a href="?location=${encodeURIComponent(e.location)}&view=${viewInput.value}" class="event-location-link">${escHtml(e.location)}</a><button type="button" class="watch-region-btn ${isWatching ? 'watching' : ''}" data-region="${escHtml(e.location)}" title="Bevaka ${escHtml(e.location)}">👁️</button></div></div></div><p class="event-summary">${escHtml(e.summary)}</p><div class="event-meta">${e.url ? `<button type="button" class="show-details-btn" data-url="${escHtml(e.url)}">📖 Visa detaljer</button>` : ''}${gpsBtn}${e.url ? `<a href="https://polisen.se${escHtml(e.url)}" target="_blank" rel="noopener noreferrer" class="read-more-link"><span>🔗</span> polisen.se</a>` : ''}<button type="button" class="share-event-btn" data-title="${escHtml(e.type + ' i ' + e.location)}" data-text="${escHtml(e.summary)}" data-url="${escHtml(shareUrl)}" title="Dela händelse">📤</button></div><div class="event-details"></div></div></div>`;
                eventsGrid.appendChild(card);
            });
        } catch (err) { console.error(err); } finally { loading = false; loadingEl.style.display = 'none'; }
    }

    function escHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

    new IntersectionObserver((e) => { if (e[0].isIntersecting && eventsGrid.style.display !== 'none') loadMore(); }, { rootMargin: '150px' }).observe(loadingEl);

    // Press Releases
    const pressGrid = document.getElementById('pressGrid');
    const pressSearch = document.getElementById('pressSearch');
    const pressRegionSelect = document.getElementById('pressRegionSelect');
    const pressLoadMore = document.getElementById('pressLoadMore');
    const pressLoadMoreBtn = document.getElementById('pressLoadMoreBtn');
    let pressPage = 1, pressLoading = false, pressHasMore = false;

    async function loadPressReleases(reset = true) {
        if (pressLoading) return;
        pressLoading = true;

        if (reset) {
            pressPage = 1;
            pressGrid.innerHTML = '<div class="press-loading"><div class="spinner"></div><p>Laddar pressmeddelanden...</p></div>';
            pressLoadMore.style.display = 'none';
        } else {
            pressLoadMoreBtn.disabled = true;
            pressLoadMoreBtn.textContent = 'Laddar...';
        }

        const region = pressRegionSelect.value;
        const search = pressSearch.value.trim();

        try {
            const res = await fetch(`?ajax=press&page=${pressPage}&region=${encodeURIComponent(region)}&search=${encodeURIComponent(search)}`);
            const data = await res.json();

            if (reset) {
                pressGrid.innerHTML = '';
                pressInit = true;
            }

            if (data.items && data.items.length > 0) {
                data.items.forEach((item, i) => {
                    const card = document.createElement('article');
                    card.className = 'press-card';
                    card.style.animationDelay = `${i * 0.03}s`;
                    card.innerHTML = `
                        <div class="press-card-header">
                            <div class="press-card-date">
                                <div class="day">${item.date.day}</div>
                                <div class="month">${item.date.month}</div>
                                <div class="time">${item.date.time}</div>
                            </div>
                            <div class="press-card-content">
                                <button type="button" class="press-card-region" data-region="${escHtml(item.regionSlug)}">📍 ${escHtml(item.region)}</button>
                                <a href="${escHtml(item.link)}" target="_blank" rel="noopener noreferrer" class="press-card-title">${escHtml(item.title)}</a>
                                <p class="press-card-description">${escHtml(item.description)}</p>
                                <div class="press-card-details"></div>
                            </div>
                        </div>
                        <div class="press-card-footer">
                            <span class="press-card-relative">${item.date.relative}</span>
                            <div class="press-card-actions">
                                <button type="button" class="show-press-details-btn" data-url="${escHtml(item.link)}">📖 Visa detaljer</button>
                                <a href="${escHtml(item.link)}" target="_blank" rel="noopener noreferrer" class="press-card-link">🔗 Läs på polisen.se</a>
                            </div>
                        </div>
                    `;
                    pressGrid.appendChild(card);
                });

                pressHasMore = data.hasMore;
                pressLoadMore.style.display = pressHasMore ? 'block' : 'none';
            } else if (reset) {
                pressGrid.innerHTML = `
                    <div class="press-empty">
                        <div class="press-empty-icon">📭</div>
                        <h3>Inga pressmeddelanden</h3>
                        <p>Inga pressmeddelanden hittades${search ? ' för "' + escHtml(search) + '"' : ''}${region ? ' i vald region' : ''}.</p>
                    </div>
                `;
            }
        } catch (err) {
            console.error('Failed to load press releases:', err);
            if (reset) {
                pressGrid.innerHTML = `
                    <div class="press-empty">
                        <div class="press-empty-icon">⚠️</div>
                        <h3>Kunde inte ladda pressmeddelanden</h3>
                        <p>Försök igen senare.</p>
                    </div>
                `;
            }
        } finally {
            pressLoading = false;
            pressLoadMoreBtn.disabled = false;
            pressLoadMoreBtn.textContent = 'Ladda fler';
        }
    }

    // Press filters
    let pressSearchTimeout;
    pressSearch.addEventListener('input', () => {
        clearTimeout(pressSearchTimeout);
        pressSearchTimeout = setTimeout(() => loadPressReleases(true), 400);
    });
    pressRegionSelect.addEventListener('change', () => loadPressReleases(true));
    pressLoadMoreBtn.addEventListener('click', () => {
        pressPage++;
        loadPressReleases(false);
    });

    // Click on region tag to filter
    document.addEventListener('click', (e) => {
        const regionBtn = e.target.closest('.press-card-region');
        if (!regionBtn) return;
        const region = regionBtn.dataset.region;
        if (region) {
            pressRegionSelect.value = region;
            loadPressReleases(true);
            window.scrollTo({ top: document.getElementById('pressSection').offsetTop - 20, behavior: 'smooth' });
        }
    });

    // Press details expansion
    const pressDetailsCache = {};
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.show-press-details-btn');
        if (!btn) return;

        const pressUrl = btn.dataset.url;
        const detailsDiv = btn.closest('.press-card').querySelector('.press-card-details');
        if (!pressUrl || !detailsDiv) return;

        if (detailsDiv.classList.contains('visible')) {
            detailsDiv.classList.remove('visible');
            btn.classList.remove('expanded');
            btn.innerHTML = '📖 Visa detaljer';
            return;
        }

        if (pressDetailsCache[pressUrl]) {
            detailsDiv.textContent = pressDetailsCache[pressUrl];
            detailsDiv.classList.add('visible');
            detailsDiv.classList.remove('error');
            btn.classList.add('expanded');
            btn.innerHTML = '📖 Dölj detaljer';
            return;
        }

        btn.classList.add('loading');
        btn.innerHTML = '⏳ Laddar...';

        try {
            const res = await fetch(`?ajax=pressdetails&url=${encodeURIComponent(pressUrl)}`);
            const data = await res.json();

            if (data.success && data.details?.content) {
                pressDetailsCache[pressUrl] = data.details.content;
                detailsDiv.textContent = data.details.content;
                detailsDiv.classList.add('visible');
                detailsDiv.classList.remove('error');
                btn.classList.add('expanded');
                btn.innerHTML = '📖 Dölj detaljer';
            } else {
                detailsDiv.textContent = 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.';
                detailsDiv.classList.add('visible', 'error');
                btn.innerHTML = '📖 Visa detaljer';
            }
        } catch (err) {
            console.error('Failed to fetch press details:', err);
            detailsDiv.textContent = 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.';
            detailsDiv.classList.add('visible', 'error');
            btn.innerHTML = '📖 Visa detaljer';
        } finally {
            btn.classList.remove('loading');
        }
    });

    // Filter auto-submit
    document.querySelectorAll('.filter-select').forEach(s => s.addEventListener('change', () => s.form.submit()));

    // Scroll to top
    const scrollTop = document.getElementById('scrollTop');
    window.addEventListener('scroll', () => scrollTop.classList.toggle('visible', window.scrollY > 300), { passive: true });
    scrollTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    // Note: Auto-reload replaced with NewEventsChecker toast notification system

    // PWA Install
    let deferredPrompt;
    const installPrompt = document.getElementById('installPrompt');
    window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; if (!localStorage.getItem('installDismissed')) setTimeout(() => installPrompt.classList.add('show'), 20000); });
    document.getElementById('installBtn')?.addEventListener('click', async () => { if (!deferredPrompt) return; deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; installPrompt.classList.remove('show'); });
    document.getElementById('dismissInstall')?.addEventListener('click', () => { installPrompt.classList.remove('show'); localStorage.setItem('installDismissed', 'true'); });

    // Service Worker registration with relative path
    if ('serviceWorker' in navigator) {
        const swPath = (window.CONFIG.basePath || '') + '/sw.js';
        navigator.serviceWorker.register(swPath).catch(() => {});
    }

    // Keyboard
    document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); document.getElementById('searchInput')?.focus(); } });

    // Event details expansion
    const detailsCache = {};
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.show-details-btn');
        if (!btn) return;

        const eventUrl = btn.dataset.url;
        const detailsDiv = btn.closest('.event-content').querySelector('.event-details');
        if (!eventUrl || !detailsDiv) return;

        if (detailsDiv.classList.contains('visible')) {
            detailsDiv.classList.remove('visible');
            btn.classList.remove('expanded');
            btn.innerHTML = '📖 Visa detaljer';
            return;
        }

        if (detailsCache[eventUrl]) {
            detailsDiv.textContent = detailsCache[eventUrl];
            detailsDiv.classList.add('visible');
            detailsDiv.classList.remove('error');
            btn.classList.add('expanded');
            btn.innerHTML = '📖 Dölj detaljer';
            return;
        }

        btn.classList.add('loading');
        btn.innerHTML = '⏳ Laddar...';

        try {
            const res = await fetch(`?ajax=details&url=${encodeURIComponent(eventUrl)}`);
            const data = await res.json();

            if (data.success && data.details?.content) {
                detailsCache[eventUrl] = data.details.content;
                detailsDiv.textContent = data.details.content;
                detailsDiv.classList.add('visible');
                detailsDiv.classList.remove('error');
                btn.classList.add('expanded');
                btn.innerHTML = '📖 Dölj detaljer';
            } else {
                detailsDiv.textContent = 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.';
                detailsDiv.classList.add('visible', 'error');
                btn.innerHTML = '📖 Visa detaljer';
            }
        } catch (err) {
            console.error('Failed to fetch details:', err);
            detailsDiv.textContent = 'Kunde inte hämta detaljer. Klicka på polisen.se-länken för att läsa mer.';
            detailsDiv.classList.add('visible', 'error');
            btn.innerHTML = '📖 Visa detaljer';
        } finally {
            btn.classList.remove('loading');
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
        const btn = e.target.closest('.show-map-btn');
        if (btn) {
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
                audio = new Audio('radio.mp3');
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

    // ========================================
    // Watch Region Event Handlers
    // ========================================
    // Watch region button click
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.watch-region-btn');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            const region = btn.dataset.region;
            if (region) {
                WatchedRegions.toggle(region);
            }
        }
    });

    // Watched regions modal
    const watchedModalOverlay = document.getElementById('watchedModalOverlay');
    const watchedRegionsBtn = document.getElementById('watchedRegionsBtn');
    const watchedModalClose = document.getElementById('watchedModalClose');

    if (watchedRegionsBtn && watchedModalOverlay) {
        watchedRegionsBtn.addEventListener('click', () => {
            watchedModalOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        });

        watchedModalClose?.addEventListener('click', () => {
            watchedModalOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });

        watchedModalOverlay.addEventListener('click', (e) => {
            if (e.target === watchedModalOverlay) {
                watchedModalOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }

    // Watched region modal actions
    document.addEventListener('click', (e) => {
        const filterBtn = e.target.closest('.watched-region-filter');
        if (filterBtn) {
            const region = filterBtn.dataset.region;
            if (region) {
                window.location.href = `?location=${encodeURIComponent(region)}&view=${viewInput.value}`;
            }
            return;
        }

        const removeBtn = e.target.closest('.watched-region-remove');
        if (removeBtn) {
            const region = removeBtn.dataset.region;
            if (region) {
                WatchedRegions.remove(region);
            }
        }
    });

    // ========================================
    // Share Event Handler
    // ========================================
    document.addEventListener('click', (e) => {
        const shareBtn = e.target.closest('.share-event-btn');
        if (shareBtn) {
            const title = shareBtn.dataset.title || 'Polishändelse';
            const text = shareBtn.dataset.text || '';
            const url = shareBtn.dataset.url || '';
            shareEvent(title, text, url);
        }
    });

    // ========================================
    // New Events Toast Handlers
    // ========================================
    const toastRefreshBtn = document.getElementById('toastRefreshBtn');
    const toastDismissBtn = document.getElementById('toastDismissBtn');

    if (toastRefreshBtn) {
        toastRefreshBtn.addEventListener('click', () => NewEventsChecker.refresh());
    }
    if (toastDismissBtn) {
        toastDismissBtn.addEventListener('click', () => NewEventsChecker.hideToast());
    }

    // ========================================
    // Initialize Systems
    // ========================================
    // Initialize watched regions UI
    WatchedRegions.updateUI();

    // Initialize new events checker
    NewEventsChecker.init();

    // Init view
    if (window.CONFIG.currentView !== 'list') setView(window.CONFIG.currentView);
})();
