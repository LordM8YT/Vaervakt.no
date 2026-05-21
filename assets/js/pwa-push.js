/* Værvakt PWA, offline queue, favorites, share and push flows. */
const VAPID_PUBLIC = window.VAERVAKT_CONFIG?.vapidPublicKey || '';
        const PUSH_NOTIFICATIONS_READY = Boolean(VAPID_PUBLIC);

        function openDB() {
            return new Promise((resolve, reject) => {
                if (!('indexedDB' in window)) return reject(new Error('IndexedDB ikke støttet'));
                const req = indexedDB.open('vaervakt', 1);
                req.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
                };
                req.onsuccess = (e) => resolve(e.target.result);
                req.onerror = (e) => reject(e.target.error);
            });
        }

        async function addToOutbox(item) {
            const db = await openDB();
            return new Promise((res, rej) => {
                const tx = db.transaction('outbox', 'readwrite');
                const store = tx.objectStore('outbox');
                const r = store.add(item);
                r.onsuccess = () => res(r.result);
                r.onerror = () => rej(r.error);
            });
        }

        async function getOutbox() {
            try {
                const db = await openDB();
                return new Promise((res, rej) => {
                    const tx = db.transaction('outbox', 'readonly');
                    const store = tx.objectStore('outbox');
                    const req = store.getAll();
                    req.onsuccess = () => res(req.result || []);
                    req.onerror = () => rej(req.error);
                });
            } catch (e) { return []; }
        }

        async function deleteOutbox(id) {
            const db = await openDB();
            return new Promise((res, rej) => {
                const tx = db.transaction('outbox', 'readwrite');
                const store = tx.objectStore('outbox');
                const req = store.delete(id);
                req.onsuccess = () => res();
                req.onerror = () => rej(req.error);
            });
        }

        async function sendQueuedReports() {
            const items = await getOutbox();
            for (const item of items) {
                try {
                    const fd = new FormData();
                    fd.append('user', item.user);
                    fd.append('weather_type', item.weather);
                    fd.append('loc', item.loc);
                    fd.append('temp', item.temp);
                    fd.append('queued_replay', '1');
                    if (item.lat !== null && item.lat !== undefined && item.lat !== '') fd.append('lat', item.lat);
                    if (item.lon !== null && item.lon !== undefined && item.lon !== '') fd.append('lon', item.lon);
                    const res = await fetch('save.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    if (res.ok) {
                        const json = await res.json().catch(() => null);
                        if (json && json.success) {
                            await deleteOutbox(item.id);
                            if (json.report) {
                                prependObservationCard(json.report);
                                if (typeof window.addMapReportMarker === 'function') {
                                    window.addMapReportMarker(json.report, false);
                                }
                            }
                            showToast('Offline-rapport sendt.');
                            updateQueueUI();
                        }
                    }
                } catch (e) {
                    console.warn('Kunne ikke sende queued report:', e);
                }
            }
        }

        function showToast(msg, timeout = 3500) {
            const el = document.createElement('div');
            el.textContent = msg;
            el.style.position = 'fixed';
            el.style.left = '50%';
            el.style.transform = 'translateX(-50%)';
            el.style.bottom = 'calc(90px + env(safe-area-inset-bottom, 0px))';
            el.style.background = 'rgba(2,6,23,0.9)';
            el.style.color = 'white';
            el.style.padding = '8px 14px';
            el.style.borderRadius = '12px';
            el.style.zIndex = 2000;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), timeout);
        }

        async function updateQueueUI() {
            const items = await getOutbox();
            const pushStatus = document.getElementById('pushStatus');
            if (!pushStatus) return;
            const base = pushStatus.textContent.split('·')[0].trim();
            if (items.length) pushStatus.textContent = `${base} · Kø: ${items.length}`;
            else pushStatus.textContent = base;
        }

        function loadFavorites() {
            const sel = document.getElementById('favoritesSelect');
            if (!sel) return;
            sel.innerHTML = '<option value="">Velg favoritt</option>';
            const favs = JSON.parse(localStorage.getItem('vaervakt_favorites') || '[]');
            favs.forEach(f => {
                const opt = document.createElement('option');
                opt.value = JSON.stringify(f);
                opt.textContent = f.name;
                sel.appendChild(opt);
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadFavorites();
            updateQueueUI();
            stampReportFormStart();
            syncPushUi();

            const rememberedUser = localStorage.getItem('vaervakt_username');
            const rememberedLocation = localStorage.getItem('vaervakt_last_location');
            const userInput = document.getElementById('userInput');
            const locInput = document.getElementById('locInput');
            const useLocationBtn = document.getElementById('useLocationBtn');
            const shareBtn = document.getElementById('shareBtn');

            if (userInput && rememberedUser && !userInput.value) {
                userInput.value = rememberedUser;
            }
            if (locInput && rememberedLocation && !locInput.value) {
                locInput.value = rememberedLocation;
            }
            if (useLocationBtn) {
                useLocationBtn.addEventListener('click', handleUseCurrentLocation);
            }
            if (shareBtn) {
                shareBtn.addEventListener('click', async () => {
                    const shareUrl = `${window.location.origin}${window.location.pathname}`;
                    if (navigator.share) {
                        try {
                            await navigator.share({
                                title: 'Værvakt.no',
                                text: 'Se lokale værobservasjoner på Værvakt.no',
                                url: shareUrl,
                            });
                            showToast('Takk for at du deler Værvakt');
                        } catch (error) {
                            if (error && error.name !== 'AbortError') {
                                showToast('Kunne ikke åpne deling akkurat nå.');
                            }
                        }
                        return;
                    }

                    try {
                        await navigator.clipboard.writeText(shareUrl);
                        showToast('Lenke kopiert');
                    } catch (error) {
                        showToast('Kunne ikke kopiere lenken.');
                    }
                });
            }

            setLocationAssist(locInput && locInput.value ? `Klar med ${locInput.value}.` : 'Trykk for å hente sted automatisk, også utenfor Norge.', locInput && locInput.value ? 'success' : 'neutral');

            const favSel = document.getElementById('favoritesSelect');
            if (favSel) favSel.addEventListener('change', (e) => {
                if (!e.target.value) return;
                const f = JSON.parse(e.target.value);
                document.getElementById('locInput').value = f.loc || '';
                if (f.lat !== null && f.lat !== undefined && f.lat !== '') {
                    document.getElementById('formLat').value = f.lat;
                    document.getElementById('formLon').value = f.lon;
                }
                setLocationAssist(f.loc ? `Klar med ${f.loc}.` : 'Favoritt lastet.', 'success');
            });

            const addFavBtn = document.getElementById('addFavoriteBtn');
            if (addFavBtn) addFavBtn.addEventListener('click', () => {
                const name = prompt('Navn for favoritt (f.eks. Hjem)');
                if (!name) return;
                const f = { name, loc: document.getElementById('locInput').value, lat: document.getElementById('formLat').value || null, lon: document.getElementById('formLon').value || null };
                const favs = JSON.parse(localStorage.getItem('vaervakt_favorites') || '[]');
                favs.push(f);
                localStorage.setItem('vaervakt_favorites', JSON.stringify(favs));
                loadFavorites();
                showToast('Favoritt lagret');
            });

            const pushBtn = document.getElementById('pushBtn');
            if (pushBtn) {
                pushBtn.onclick = PUSH_NOTIFICATIONS_READY ? registerPush : null;
            }

            const installBtn = document.getElementById('installBtn');
            let deferredPrompt = null;
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                if (installBtn) installBtn.style.display = 'inline-block';
            });
            if (installBtn) installBtn.addEventListener('click', async () => {
                if (!deferredPrompt) return showToast('Installeringsprompt ikke tilgjengelig');
                deferredPrompt.prompt();
                const choice = await deferredPrompt.userChoice;
                showToast(choice.outcome === 'accepted' ? 'Installert' : 'Avvist');
                deferredPrompt = null;
            });

            sendQueuedReports();
        });

        window.addEventListener('online', () => { sendQueuedReports(); updateQueueUI(); });

        function urlBase64ToUint8Array(base64String) {
            const normalized = String(base64String || '').trim().replace(/\s/g, '');
            if (!normalized) {
                throw new Error('Mangler VAPID public key');
            }

            const remainder = normalized.length % 4;
            if (remainder === 1) {
                throw new Error('Ugyldig VAPID public key-lengde');
            }

            const padding = '='.repeat((4 - remainder) % 4);
            const base64 = (normalized + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            return Uint8Array.from(rawData, (char) => char.charCodeAt(0));
        }

        async function getPushPermissionState(registration, subscribeOptions) {
            if (registration.pushManager && typeof registration.pushManager.permissionState === 'function') {
                return registration.pushManager.permissionState(subscribeOptions);
            }

            if (typeof Notification !== 'undefined' && Notification.permission) {
                return Notification.permission === 'default' ? 'prompt' : Notification.permission;
            }

            return 'prompt';
        }

        async function ensurePushPermission(registration, subscribeOptions) {
            let state = await getPushPermissionState(registration, subscribeOptions);
            if (state === 'default') state = 'prompt';
            if (state === 'denied') return state;

            if (state === 'prompt' && typeof Notification !== 'undefined' && typeof Notification.requestPermission === 'function') {
                state = await Notification.requestPermission();
                if (state === 'default') state = 'prompt';
            }

            return state;
        }

        let pushSubscriptionInFlight = null;

        async function registerPush() {
            if (pushSubscriptionInFlight) {
                return pushSubscriptionInFlight;
            }

            pushSubscriptionInFlight = (async () => {
                if (!PUSH_NOTIFICATIONS_READY) {
                    showToast('Push-varsler blir aktivert når oppsettet er klart.');
                    return;
                }
                if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                    setPushUiState('unsupported');
                    showToast('Push ikke støttet i denne nettleseren');
                    return;
                }

                setPushUiState('busy');

                try {
                    const applicationServerKey = urlBase64ToUint8Array(VAPID_PUBLIC);
                    const subscribeOptions = { userVisibleOnly: true, applicationServerKey };
                    const reg = await getServiceWorkerRegistration();
                    const existing = await reg.pushManager.getSubscription();
                    if (existing) {
                        setPushUiState('active');
                        showToast('Allerede abonnert');
                        return;
                    }

                    const permissionState = await ensurePushPermission(reg, subscribeOptions);
                    if (permissionState !== 'granted') {
                        setPushUiState(permissionState === 'denied' ? 'denied' : 'ready');
                        showToast(permissionState === 'denied' ? 'Push-varsler er blokkert i nettleseren' : 'Push-varsler ble ikke tillatt');
                        return;
                    }

                    const sub = await reg.pushManager.subscribe(subscribeOptions);
                    const res = await fetch('subscriptions.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ subscription: sub }),
                    });

                    if (res.ok) {
                        setPushUiState('active');
                        showToast('Abonnert på push-varsler');
                    } else {
                        setPushUiState('ready');
                        showToast('Kunne ikke lagre abonnement');
                    }
                } catch (error) {
                    console.error('Push-abonnement feilet:', error);
                    setPushUiState('ready');
                    showToast('Abonnement feilet');
                }
            })().finally(() => {
                pushSubscriptionInFlight = null;
            });

            return pushSubscriptionInFlight;
        }
