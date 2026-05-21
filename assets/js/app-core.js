/* Værvakt app core: UI, reports, geolocation and search. Extracted from index.php for cacheable 2026 frontend. */
lucide.createIcons();

        function openModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.modal.active')) {
                document.documentElement.style.overflow = '';
                document.body.style.overflow = '';
            }
        }

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.modal.active').forEach((modal) => {
                modal.classList.remove('active');
                modal.setAttribute('aria-hidden', 'true');
            });
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
        });

        const SUBMIT_DEFAULT_LABEL = 'Send værrapport';

        let serviceWorkerRegistrationPromise = null;

        function getServiceWorkerRegistration() {
            if (!('serviceWorker' in navigator)) {
                return Promise.reject(new Error('Service Worker ikke støttet'));
            }

            if (!serviceWorkerRegistrationPromise) {
                serviceWorkerRegistrationPromise = navigator.serviceWorker
                    .register('/service-worker.js', { scope: '/' })
                    .then((registration) => navigator.serviceWorker.ready.then(() => registration))
                    .catch((error) => {
                        serviceWorkerRegistrationPromise = null;
                        throw error;
                    });
            }

            return serviceWorkerRegistrationPromise;
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (match) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            }[match]));
        }

        function getWeatherEmoji(type) {
            const normalized = String(type || '').toLowerCase();
            if (normalized.includes('snø') || normalized.includes('sno')) return '❄️';
            if (normalized.includes('regn') || normalized.includes('rain') || normalized.includes('byge')) return '🌧️';
            if (normalized.includes('vind') || normalized.includes('storm')) return '⛈️';
            if (normalized.includes('tåke') || normalized.includes('taake') || normalized.includes('fog')) return '🌫️';
            if (normalized.includes('sky') || normalized.includes('cloud')) return '☁️';
            return '☀️';
        }

        function formatRelativeTimeLabel(dateString) {
            if (!dateString) return 'Nå nettopp';
            const ts = new Date(dateString);
            if (Number.isNaN(ts.getTime())) return 'Nylig';
            const diffSeconds = Math.max(0, Math.round((Date.now() - ts.getTime()) / 1000));
            if (diffSeconds < 45) return 'Nå nettopp';
            if (diffSeconds < 3600) return `${Math.max(1, Math.floor(diffSeconds / 60))} min siden`;
            if (diffSeconds < 86400) return `${Math.floor(diffSeconds / 3600)} t siden`;
            if (diffSeconds < 604800) return `${Math.floor(diffSeconds / 86400)} d siden`;
            return ts.toLocaleString('nb-NO', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        }

        function renderObservationCard(report) {
            const type = report.weather_condition || report.weather || '';
            const temperatureRaw = report.temperature ?? report.temp ?? 0;
            const temperature = Number(temperatureRaw);
            const username = report.username || report.user || 'Noen';
            const location = report.location || report.loc || 'Ukjent sted';
            const createdAt = report.created_at || new Date().toISOString();

            return `
                <div class="obs-item flex items-center justify-between gap-4 bg-slate-950/30 p-4 rounded-2xl border border-white/5" data-type="${escapeHtml(type)}">
                    <div class="flex min-w-0 items-center gap-4 text-left">
                        <div class="text-3xl leading-none" aria-hidden="true">${getWeatherEmoji(type)}</div>
                        <div class="min-w-0">
                            <p class="text-[10px] uppercase font-black tracking-[0.16em] text-slate-500">${escapeHtml(formatRelativeTimeLabel(createdAt))}</p>
                            <p class="truncate text-sm font-bold">${escapeHtml(username)} i ${escapeHtml(location)}</p>
                            <p class="text-[10px] uppercase font-black text-sky-400">${escapeHtml(type)}</p>
                        </div>
                    </div>
                    <div class="shrink-0 text-right font-black italic text-xl">${Math.round(Number.isFinite(temperature) ? temperature : 0)}°</div>
                </div>
            `;
        }

        function prependObservationCard(report) {
            const list = document.getElementById('observationList');
            if (!list) return;
            const empty = document.getElementById('noObservationsMsg');
            if (empty) empty.remove();
            list.insertAdjacentHTML('afterbegin', renderObservationCard(report));
            if (!document.getElementById('emptyFilterMsg')) {
                list.insertAdjacentHTML('beforeend', '<p id="emptyFilterMsg" class="text-xs text-slate-500 italic py-4 text-center" style="display: none;">Ingen kritiske forhold rapportert akkurat nå...</p>');
            }
            const fresh = list.querySelector('.obs-item');
            if (fresh) {
                fresh.classList.add('is-fresh');
                setTimeout(() => fresh.classList.remove('is-fresh'), 2600);
            }
            const items = list.querySelectorAll('.obs-item');
            if (items.length > 15) {
                items[items.length - 1].remove();
            }
        }

        let feedStatusResetTimer = null;

        function setFeedStatus(message, tone = 'neutral', options = {}) {
            const pill = document.getElementById('feedStatusPill');
            if (!pill) return;
            pill.dataset.tone = tone;
            pill.textContent = message;
            if (feedStatusResetTimer) {
                clearTimeout(feedStatusResetTimer);
                feedStatusResetTimer = null;
            }
            if (options.autoReset !== false && tone !== 'neutral') {
                feedStatusResetTimer = setTimeout(() => {
                    pill.dataset.tone = 'neutral';
                    pill.textContent = 'Live nå';
                }, options.resetAfter ?? 4200);
            }
        }

        function setLocationAssist(message, tone = 'neutral') {
            const el = document.getElementById('locationAssistText');
            if (!el) return;
            const tones = {
                neutral: 'text-slate-300',
                success: 'text-emerald-300',
                warning: 'text-amber-300',
                error: 'text-rose-300',
            };
            el.className = `status-hint mt-1 text-sm ${tones[tone] || tones.neutral}`;
            el.textContent = message;
        }

        function getFriendlyGeolocationError(error) {
            if (!error || typeof error.code === 'undefined') {
                return 'Fant ikke posisjon akkurat nå. Skriv stedet manuelt.';
            }
            if (error.code === error.PERMISSION_DENIED) {
                return 'Posisjon ble avslått. Skriv stedet manuelt eller tillat GPS.';
            }
            if (error.code === error.POSITION_UNAVAILABLE) {
                return 'Fant ikke posisjon akkurat nå. Skriv stedet manuelt.';
            }
            if (error.code === error.TIMEOUT) {
                return 'Posisjonstjenesten brukte for lang tid. Prøv igjen eller skriv stedet manuelt.';
            }
            return 'Fant ikke posisjon akkurat nå. Skriv stedet manuelt.';
        }

        function requestCurrentPosition(options = {}) {
            return new Promise((resolve, reject) => {
                if (!navigator.geolocation) {
                    reject(new Error('Geolocation unsupported'));
                    return;
                }
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: options.enableHighAccuracy ?? true,
                    timeout: options.timeout ?? 7000,
                    maximumAge: options.maximumAge ?? 120000,
                });
            });
        }

        function preferredLanguage() {
            if (Array.isArray(navigator.languages) && navigator.languages.length) {
                return navigator.languages[0];
            }
            return navigator.language || 'nb-NO';
        }

        function buildLocationLabel(address) {
            if (!address) return '';
            const primary = [
                address.suburb,
                address.neighbourhood,
                address.neighborhood,
                address.borough,
                address.residential,
                address.quarter,
                address.city_district,
                address.village,
                address.hamlet,
                address.town,
                address.city,
            ].find(Boolean);
            const secondary = [
                address.city,
                address.town,
                address.municipality,
                address.county,
                address.state_district,
                address.state,
                address.province,
                address.region,
                address.country,
            ].find((value) => value && value !== primary);

            if (primary && secondary) return `${primary}, ${secondary}`;
            return primary || secondary || address.country || '';
        }

        async function reverseGeocode(lat, lon) {
            const url = new URL('https://nominatim.openstreetmap.org/reverse');
            url.search = new URLSearchParams({
                format: 'jsonv2',
                lat: String(lat),
                lon: String(lon),
                zoom: '13',
                'accept-language': preferredLanguage(),
            }).toString();

            const res = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) {
                throw new Error(`Reverse geocode failed: ${res.status}`);
            }
            const data = await res.json();
            return buildLocationLabel(data.address || {}) || data.display_name || '';
        }

        async function hydrateLocationFromCoords(lat, lon, options = {}) {
            const locInput = document.getElementById('locInput');
            const latInput = document.getElementById('formLat');
            const lonInput = document.getElementById('formLon');
            const shouldUpdateField = options.updateField !== false;
            const shouldToast = options.toastOnSuccess === true;

            if (latInput) latInput.value = lat;
            if (lonInput) lonInput.value = lon;

            try {
                const label = await reverseGeocode(lat, lon);
                if (label && locInput && (shouldUpdateField || !locInput.value.trim())) {
                    locInput.value = label;
                }
                if (label) {
                    setLocationAssist(`Fant ${label}.`, 'success');
                    if (shouldToast) showToast('Sted oppdatert');
                } else {
                    setLocationAssist('Fant posisjon, men ikke sted. Du kan skrive stedet manuelt.', 'warning');
                }
                return label;
            } catch (error) {
                console.warn('Reverse geocoding feilet:', error);
                setLocationAssist('Fant posisjon, men ikke sted. Du kan skrive stedet manuelt.', 'warning');
                return '';
            }
        }

        async function handleUseCurrentLocation() {
            const button = document.getElementById('useLocationBtn');
            if (button) button.disabled = true;
            setLocationAssist('Henter posisjon …', 'neutral');

            try {
                const pos = await requestCurrentPosition({ timeout: 8000, enableHighAccuracy: true });
                const lat = pos.coords.latitude;
                const lon = pos.coords.longitude;
                await hydrateLocationFromCoords(lat, lon, { updateField: true, toastOnSuccess: true });
                if (window.__vaervakt_map) {
                    window.__vaervakt_map.setView([lat, lon], 11);
                }
                if (typeof window.fetchReportsNearby === 'function') {
                    window.fetchReportsNearby(lat, lon);
                }
                setFeedStatus('Ser på området ditt', 'neutral', { autoReset: false });
                const url = new URL(window.location.href);
                url.searchParams.set('lat', lat);
                url.searchParams.set('lon', lon);
                window.history.replaceState({}, '', url.toString());
            } catch (error) {
                const message = getFriendlyGeolocationError(error);
                setLocationAssist(message, 'error');
                showToast(message);
            } finally {
                if (button) button.disabled = false;
            }
        }

        function resetSubmitButton() {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            if (submitBtn) submitBtn.disabled = false;
            if (submitText) submitText.innerHTML = SUBMIT_DEFAULT_LABEL;
        }

        function stampReportFormStart() {
            const form = document.getElementById('reportForm');
            if (!form) return;
            const field = form.querySelector('input[name="form_started_at"]');
            if (field) {
                field.value = String(Math.floor(Date.now() / 1000));
            }
        }

        function setPushUiState(state, message) {
            const pushBtn = document.getElementById('pushBtn');
            const pushStatus = document.getElementById('pushStatus');
            if (!pushBtn || !pushStatus) return;

            const disabled = state === 'active' || state === 'unsupported' || state === 'busy' || state === 'denied';
            pushBtn.disabled = disabled;
            pushBtn.classList.toggle('bg-sky-500', !disabled);
            pushBtn.classList.toggle('bg-slate-800', disabled);
            pushBtn.classList.toggle('text-slate-400', disabled);
            pushBtn.classList.toggle('cursor-not-allowed', disabled);

            if (state === 'active') {
                pushBtn.textContent = 'Varsler aktivert';
                pushStatus.textContent = 'Push-varsler: abonnert';
                return;
            }
            if (state === 'busy') {
                pushBtn.textContent = 'Aktiverer...';
                pushStatus.textContent = 'Push-varsler: aktiverer';
                return;
            }
            if (state === 'denied') {
                pushBtn.textContent = 'Varsler blokkert';
                pushStatus.textContent = message || 'Push-varsler: blokkert i nettleseren';
                return;
            }
            if (state === 'unsupported') {
                pushBtn.textContent = 'Push ikke støttet';
                pushStatus.textContent = message || 'Push-varsler: ikke støttet';
                return;
            }

            pushBtn.textContent = 'Aktiver varsler';
            pushStatus.textContent = message || 'Push-varsler: ikke abonnert';
        }

        async function syncPushUi() {
            if (!PUSH_NOTIFICATIONS_READY || !('PushManager' in window)) {
                setPushUiState('unsupported', PUSH_NOTIFICATIONS_READY ? 'Push-varsler: ikke støttet' : 'Push-varsler: kommer snart');
                return;
            }

            try {
                const reg = await getServiceWorkerRegistration();
                const existing = await reg.pushManager.getSubscription();
                if (existing) {
                    setPushUiState('active');
                    return;
                }

                if ('permissions' in navigator && typeof navigator.permissions.query === 'function') {
                    try {
                        const permission = await navigator.permissions.query({ name: 'notifications' });
                        if (permission.state === 'denied') {
                            setPushUiState('denied');
                            return;
                        }
                    } catch (permissionError) {
                        // Enkelte nettlesere støtter PushManager uten Permissions API for notifications.
                    }
                }

                setPushUiState('ready');
            } catch (error) {
                console.warn('Kunne ikke lese push-status:', error);
                setPushUiState('unsupported', 'Push-varsler: kunne ikke klargjøres');
            }
        }

        async function handleSubmit(event) {
            event.preventDefault();
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            submitBtn.disabled = true;
            submitText.innerHTML = '<span class="spinner"></span> Sender...';

            const form = document.getElementById('reportForm');
            const userInput = document.getElementById('userInput');
            const weatherInput = document.getElementById('weatherInput');
            const locInput = document.getElementById('locInput');
            const tempInput = document.getElementById('tempInput');
            const honeypotInput = document.getElementById('companyWebsite');
            const formStartedAtInput = form.querySelector('input[name="form_started_at"]');
            const latInput = document.getElementById('formLat');
            const lonInput = document.getElementById('formLon');
            const user = userInput.value.trim();
            const weather = weatherInput.value;
            const temp = tempInput.value;
            let loc = locInput.value.trim();

            if (!user || !weather || temp === '') {
                showToast('Fyll inn navn, værtype og temperatur.');
                setFeedStatus('Mangler felt', 'warning');
                resetSubmitButton();
                return;
            }

            if ((!latInput.value || !lonInput.value) && navigator.geolocation) {
                try {
                    const pos = await requestCurrentPosition({ timeout: 5000, enableHighAccuracy: false });
                    await hydrateLocationFromCoords(pos.coords.latitude, pos.coords.longitude, { updateField: !loc, toastOnSuccess: false });
                    loc = locInput.value.trim();
                } catch (error) {
                    if (!loc) {
                        const message = getFriendlyGeolocationError(error);
                        setLocationAssist(message, 'error');
                        showToast(message);
                        setFeedStatus('Posisjon mangler', 'warning');
                        resetSubmitButton();
                        return;
                    }
                }
            }

            if (!loc) {
                showToast('Legg til sted eller bruk posisjon før du sender.');
                setFeedStatus('Sted mangler', 'warning');
                resetSubmitButton();
                return;
            }

            const fd = new FormData();
            fd.append('user', user);
            fd.append('weather_type', weather);
            fd.append('loc', loc);
            fd.append('temp', temp);
            fd.append('company_website', honeypotInput ? honeypotInput.value : '');
            fd.append('form_started_at', formStartedAtInput ? formStartedAtInput.value : '');
            if (latInput && latInput.value) fd.append('lat', latInput.value);
            if (lonInput && lonInput.value) fd.append('lon', lonInput.value);

            try {
                const res = await fetch('save.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                const json = await res.json().catch(() => null);
                if (res.ok && json && json.success) {
                    const savedReport = json.report || {
                        username: user,
                        weather_condition: weather,
                        location: loc,
                        temperature: temp,
                        created_at: new Date().toISOString(),
                        latitude: latInput && latInput.value ? latInput.value : null,
                        longitude: lonInput && lonInput.value ? lonInput.value : null,
                    };

                    prependObservationCard(savedReport);
                    if (typeof window.addMapReportMarker === 'function') {
                        window.addMapReportMarker(savedReport, true);
                    }
                    if (navigator.vibrate) {
                        navigator.vibrate(12);
                    }

                    localStorage.setItem('vaervakt_username', user);
                    if (loc) {
                        localStorage.setItem('vaervakt_last_location', loc);
                    }

                    const preservedUser = user;
                    const preservedLocation = loc;
                    const preservedLat = latInput ? latInput.value : '';
                    const preservedLon = lonInput ? lonInput.value : '';

                    form.reset();
                    userInput.value = preservedUser;
                    locInput.value = preservedLocation;
                    if (latInput) latInput.value = preservedLat;
                    if (lonInput) lonInput.value = preservedLon;
                    weatherInput.value = '';
                    tempInput.value = '';
                    stampReportFormStart();

                    showToast('Værrapport sendt');
                    setFeedStatus('Ny rapport sendt', 'success');
                    setLocationAssist(preservedLocation ? `Klar med ${preservedLocation}.` : 'Trykk for å hente sted automatisk, også utenfor Norge.', preservedLocation ? 'success' : 'neutral');
                    resetSubmitButton();
                    return;
                }

                throw new Error((json && json.message) ? json.message : ((json && json.error) ? json.error : 'Server returned non-OK'));
            } catch (err) {
                const message = String((err && err.message) || '');
                const isLikelyOffline = !navigator.onLine || message.includes('Failed to fetch') || message.includes('NetworkError');
                if (!isLikelyOffline) {
                    console.error('Kunne ikke sende rapport:', err);
                    showToast(message || 'Kunne ikke lagre værrapporten akkurat nå.');
                    setFeedStatus('Kunne ikke sende', 'warning');
                    resetSubmitButton();
                    return;
                }

                const report = {
                    user,
                    weather,
                    loc,
                    temp: parseFloat(temp) || 0,
                    lat: (latInput && latInput.value) ? parseFloat(latInput.value) : null,
                    lon: (lonInput && lonInput.value) ? parseFloat(lonInput.value) : null,
                    created_at: new Date().toISOString(),
                    form_started_at: formStartedAtInput ? formStartedAtInput.value : '',
                };
                try {
                    await addToOutbox(report);
                    showToast('Ingen nett. Rapporten ligger i kø og sendes automatisk ved tilkobling.');
                    setFeedStatus('Lagret offline', 'warning');
                    updateQueueUI();
                } catch (e) {
                    console.error('Kunne ikke lagre i outbox:', e);
                    showToast('Kunne ikke lagre rapport lokalt.');
                    setFeedStatus('Lagring feilet', 'warning');
                }
                resetSubmitButton();
            }
        }

        function filterWeather(mode) {
            const items = document.querySelectorAll('.obs-item');
            const title = document.getElementById('obsTitle');
            const resetBtn = document.getElementById('resetFilter');
            const navAll = document.getElementById('nav-all');
            const navVann = document.getElementById('nav-vann');
            const emptyMsg = document.getElementById('emptyFilterMsg');
            let found = 0;

            items.forEach(item => {
                const type = item.getAttribute('data-type');
                if (mode === 'all') {
                    item.style.display = 'flex';
                    found++;
                } else {
                    if (['Regn', 'Snø', 'Vind'].includes(type)) {
                        item.style.display = 'flex';
                        found++;
                    } else {
                        item.style.display = 'none';
                    }
                }
            });

            if (mode === 'vann') {
                title.innerText = "Varslede vann- & snøforhold";
                resetBtn.classList.remove('hidden');
                navVann.classList.replace('text-slate-500', 'text-sky-400');
                navAll.classList.replace('text-sky-400', 'text-slate-500');
                if (emptyMsg) emptyMsg.style.display = (found === 0) ? 'block' : 'none';
            } else {
                title.innerText = "Siste observasjoner";
                resetBtn.classList.add('hidden');
                navAll.classList.replace('text-slate-500', 'text-sky-400');
                navVann.classList.replace('text-sky-400', 'text-slate-500');
                if (emptyMsg) emptyMsg.style.display = 'none';
            }
        }

        (function(){
            const input = document.getElementById('placeSearch');
            const results = document.getElementById('searchResults');
            let timer = null;
            const cache = new Map();
            function clearResults(){ results.style.display='none'; results.innerHTML=''; }
            function showResults(items){
                results.innerHTML = '';
                if (!items || !items.length) { clearResults(); return; }
                items.forEach(it => {
                    const el = document.createElement('button');
                    el.type = 'button';
                    el.className = 'w-full text-left p-3 hover:bg-white/10 border-b border-white/5';
                    el.innerHTML = `<div class="text-sm font-semibold">${it.display}</div><div class="text-[11px] text-slate-400">${it.type||''} ${it.class||''}</div>`;
                    el.addEventListener('click', ()=>{
                        input.value = it.display;
                        const locInput = document.getElementById('locInput');
                        const latInput = document.getElementById('formLat');
                        const lonInput = document.getElementById('formLon');
                        if (locInput) locInput.value = it.display;
                        if (latInput && it.lat) latInput.value = it.lat;
                        if (lonInput && it.lon) lonInput.value = it.lon;
                        clearResults();
                        if (it.lat && it.lon) {
                            if (window.__vaervakt_map) {
                                window.__vaervakt_map.setView([parseFloat(it.lat), parseFloat(it.lon)], 12);
                            } else {
                                window.location.href = `index.php?lat=${it.lat}&lon=${it.lon}`;
                                return;
                            }
                            fetchReportsNearby(it.lat, it.lon);
                        }
                    });
                    results.appendChild(el);
                });
                results.style.display = 'block';
            }

            const loader = document.createElement('span'); loader.className='small-spinner'; loader.style.display='none';
            input.parentNode.appendChild(loader);

            input && input.addEventListener('input', (e)=>{
                const q = e.target.value.trim();
                if (timer) clearTimeout(timer);
                if (!q) { clearResults(); loader.style.display='none'; return; }
                timer = setTimeout(async ()=>{
                    try {
                        if (cache.has(q)) { showResults(cache.get(q)); loader.style.display='none'; return; }
                        loader.style.display='inline-block';
                        const res = await fetch('search.php?q='+encodeURIComponent(q));
                        loader.style.display='none';
                        if (!res.ok) { clearResults(); return; }
                        const json = await res.json();
                        cache.set(q, json);
                        showResults(json);
                    } catch (e) { clearResults(); loader.style.display='none'; }
                }, 280);
            });
            document.addEventListener('click', (ev)=>{ if (!results.contains(ev.target) && ev.target !== input) clearResults(); });
        })();
