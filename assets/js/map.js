/* Værvakt map layer: Leaflet reports and forecast playback. */
const forecastData = window.VAERVAKT_CONFIG?.forecastData || null;
            const reportsData = window.VAERVAKT_CONFIG?.reportsData || [];
            const reportsHaveCoords = Boolean(window.VAERVAKT_CONFIG?.reportsHaveCoords);
            (function(){
                const lat = Number(window.VAERVAKT_CONFIG?.lat ?? 58.1504);
                const lon = Number(window.VAERVAKT_CONFIG?.lon ?? 7.9470);
                const map = L.map('leafletMap', { zoomControl: false }).setView([lat, lon], 10);
                window.__vaervakt_map = map;
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map);

                function typeColor(t){
                    t = (t||'').toLowerCase();
                    if(t.includes('sn') || t.includes('snø')) return '#60a5fa';
                    if(t.includes('regn') || t.includes('rain')) return '#3b82f6';
                    if(t.includes('vind') || t.includes('storm')) return '#fb923c';
                    if(t.includes('tåke')) return '#94a3b8';
                    return '#34d399';
                }

                function makeReportMarker(report) {
                    const rlat = (report.latitude !== undefined && report.latitude !== null && report.latitude !== '') ? parseFloat(report.latitude) : null;
                    const rlon = (report.longitude !== undefined && report.longitude !== null && report.longitude !== '') ? parseFloat(report.longitude) : null;
                    if (!Number.isFinite(rlat) || !Number.isFinite(rlon)) {
                        return null;
                    }
                    const color = typeColor(report.weather_condition);
                    const html = `<div style="width:14px;height:14px;border-radius:9999px;background:${color};border:2px solid rgba(255,255,255,0.06)"></div>`;
                    const icon = L.divIcon({ html: html, className: '', iconSize: [18,18], iconAnchor: [9,9] });
                    const marker = L.marker([rlat, rlon], { icon: icon });
                    marker.bindPopup(`<strong>${escapeHtml(report.username)}</strong><br>${escapeHtml(report.location)}<br>${Math.round(report.temperature)}°<br><em>${escapeHtml(report.weather_condition)}</em>`);
                    return marker;
                }

                const markerCluster = L.markerClusterGroup();
                let plotted = false;
                let noCoordsControl = null;
                reportsData.forEach((r) => {
                    const marker = makeReportMarker(r);
                    if (marker) {
                        plotted = true;
                        markerCluster.addLayer(marker);
                    }
                });
                if (markerCluster.getLayers().length) map.addLayer(markerCluster);

                window.addMapReportMarker = function(report, panToMarker) {
                    const normalizedReport = {
                        ...report,
                        username: report.username || report.user || 'Noen',
                        location: report.location || report.loc || 'Ukjent sted',
                        weather_condition: report.weather_condition || report.weather || '',
                        temperature: report.temperature ?? report.temp ?? 0,
                        latitude: report.latitude ?? report.lat ?? null,
                        longitude: report.longitude ?? report.lon ?? null,
                    };
                    const marker = makeReportMarker(normalizedReport);
                    if (!marker) {
                        return;
                    }
                    markerCluster.addLayer(marker);
                    if (!map.hasLayer(markerCluster)) {
                        map.addLayer(markerCluster);
                    }
                    if (panToMarker) {
                        const markerLat = parseFloat(normalizedReport.latitude);
                        const markerLon = parseFloat(normalizedReport.longitude);
                        setTimeout(() => map.invalidateSize(), 80);
                        map.flyTo([markerLat, markerLon], Math.max(map.getZoom(), 16), { duration: 0.6 });
                    }
                    if (noCoordsControl) {
                        map.removeControl(noCoordsControl);
                        noCoordsControl = null;
                    }
                };

                window.fetchReportsNearby = async function(latN, lonN, radiusKm=25) {
                    try {
                        const res = await fetch(`reports_nearby.php?lat=${encodeURIComponent(latN)}&lon=${encodeURIComponent(lonN)}&radius=${encodeURIComponent(radiusKm)}`);
                        if (!res.ok) return;
                        const rows = await res.json();
                        markerCluster.clearLayers();
                        const obsList = document.getElementById('observationList');
                        if (obsList) obsList.innerHTML = '';
                        let any = false;
                        for (const r of rows) {
                            const marker = makeReportMarker(r);
                            if (marker) {
                                any = true;
                                markerCluster.addLayer(marker);
                            }
                            if (obsList) {
                                obsList.insertAdjacentHTML('beforeend', renderObservationCard(r));
                            }
                        }
                        if (obsList && !rows.length) {
                            obsList.innerHTML = '<p id="noObservationsMsg" class="text-xs text-slate-500 italic py-4 text-center">Ingen observasjoner i dette området ennå.</p><p id="emptyFilterMsg" class="text-xs text-slate-500 italic py-4 text-center" style="display: none;">Ingen kritiske forhold rapportert akkurat nå...</p>';
                        } else if (obsList && !document.getElementById('emptyFilterMsg')) {
                            obsList.insertAdjacentHTML('beforeend', '<p id="emptyFilterMsg" class="text-xs text-slate-500 italic py-4 text-center" style="display: none;">Ingen kritiske forhold rapportert akkurat nå...</p>');
                        }
                        if (markerCluster.getLayers().length) {
                            if (!map.hasLayer(markerCluster)) map.addLayer(markerCluster);
                            try { map.fitBounds(markerCluster.getBounds().pad(0.2), { maxZoom: 16 }); } catch(e){}
                            setTimeout(() => map.invalidateSize(), 80);
                            if (noCoordsControl) {
                                map.removeControl(noCoordsControl);
                                noCoordsControl = null;
                            }
                        }
                        if (!any) {
                            showToast('Ingen rapporter funnet i området');
                            setFeedStatus('Ingen rapporter i nærheten', 'warning');
                        } else {
                            setFeedStatus('Lokale rapporter oppdatert', 'success');
                        }
                    } catch (e) { console.error('fetchReportsNearby failed', e); }
                };

                if (forecastData && forecastData.properties && Array.isArray(forecastData.properties.timeseries) && forecastData.properties.timeseries.length > 0) {
                    const timeseries = forecastData.properties.timeseries;
                    const frames = timeseries.map(ts => {
                        const time = ts.time;
                        const temp = ts.data && ts.data.instant && ts.data.instant.details && (ts.data.instant.details.air_temperature !== undefined) ? Math.round(ts.data.instant.details.air_temperature) : null;
                        let symbol = null;
                        if (ts.data && ts.data.next_1_hours && ts.data.next_1_hours.summary && ts.data.next_1_hours.summary.symbol_code) symbol = ts.data.next_1_hours.summary.symbol_code;
                        else if (ts.data && ts.data.next_6_hours && ts.data.next_6_hours.summary && ts.data.next_6_hours.summary.symbol_code) symbol = ts.data.next_6_hours.summary.symbol_code;
                        else if (ts.data && ts.data.next_12_hours && ts.data.next_12_hours.summary && ts.data.next_12_hours.summary.symbol_code) symbol = ts.data.next_12_hours.summary.symbol_code;
                        return { time, temp, symbol, raw: ts };
                    });

                    const metIconAt = (symbol)=> L.icon({ iconUrl: `https://raw.githubusercontent.com/metno/weathericons/main/weather/svg/${symbol || 'clearsky_day'}.svg`, iconSize: [56,56], iconAnchor: [28,28] });
                    const forecastMarker = L.marker([lat, lon], { icon: metIconAt(frames[0].symbol || (window.VAERVAKT_CONFIG?.symbol || 'clearsky_day')) }).addTo(map);
                    forecastMarker.bindPopup('');

                    const forecastControl = L.control({ position: 'bottomleft' });
                    forecastControl.onAdd = function(map){
                        const div = L.DomUtil.create('div', '');
                        div.style.minWidth = '260px';
                        div.style.padding = '8px';
                        div.style.borderRadius = '12px';
                        div.style.background = 'rgba(6,8,15,0.8)';
                        div.style.boxShadow = '0 6px 20px rgba(2,6,23,0.6)';
                        div.innerHTML = `
                            <div style="display:flex;align-items:center;gap:8px">
                                <button id="forecastPlay" style="background:#0ea5e9;color:white;border:none;padding:6px 10px;border-radius:10px;font-weight:700">▶</button>
                                <input id="timeSlider" type="range" min="0" max="${frames.length-1}" value="0" style="flex:1">
                            </div>
                            <div id="timeLabel" style="margin-top:6px;font-size:12px;color:#cbd5e1"></div>
                        `;
                        L.DomEvent.disableClickPropagation(div);
                        L.DomEvent.disableScrollPropagation(div);
                        return div;
                    };
                    forecastControl.addTo(map);

                    const slider = document.getElementById('timeSlider');
                    const label = document.getElementById('timeLabel');
                    const playBtn = document.getElementById('forecastPlay');
                    let playTimer = null;
                    let currentIndex = 0;

                    function updateFrame(i){
                        if (i < 0 || i >= frames.length) return;
                        currentIndex = i;
                        const f = frames[i];
                        forecastMarker.setIcon(metIconAt(f.symbol || 'clearsky_day'));
                        const t = (f.temp !== null) ? `${f.temp}°` : '—';
                        const timeStr = new Date(f.time).toLocaleString();
                        forecastMarker.setPopupContent(`<strong>Prognose</strong><br>${timeStr}<br>${t}<br><em>${escapeHtml(f.symbol||'')}</em>`);
                        label.textContent = `${timeStr} — ${t}`;
                        const tempDisplay = document.getElementById('tempDisplay');
                        if (tempDisplay) tempDisplay.textContent = t;
                    }

                    slider.addEventListener('input', (e)=> updateFrame(parseInt(e.target.value,10)));
                    playBtn.addEventListener('click', ()=>{
                        if (playTimer) { clearInterval(playTimer); playTimer = null; playBtn.textContent='▶'; }
                        else { playBtn.textContent='⏸'; playTimer = setInterval(()=>{ let next = currentIndex+1; if(next>=frames.length) next=0; slider.value = next; updateFrame(next); }, 1200); }
                    });

                    updateFrame(0);
                } else {
                    const metIconUrl = `https://raw.githubusercontent.com/metno/weathericons/main/weather/svg/${window.VAERVAKT_CONFIG?.symbol || 'clearsky_day'}.svg`;
                    const metIcon = L.icon({ iconUrl: metIconUrl, iconSize: [56,56], iconAnchor: [28,28] });
                    L.marker([lat, lon], { icon: metIcon }).addTo(map).bindPopup(`<strong>MET.no</strong><br>${window.VAERVAKT_CONFIG?.tempNow ?? '—'}°`);
                }

                if (!plotted) {
                    noCoordsControl = L.control({position:'topright'});
                    noCoordsControl.onAdd = function() {
                        const div = L.DomUtil.create('div', 'p-2 rounded text-xs');
                        div.style.background = 'rgba(2,6,23,0.8)';
                        div.style.color = 'white';
                        div.style.margin = '6px';
                        div.style.padding = '6px 10px';
                        div.style.border = '1px solid rgba(255,255,255,0.05)';
                        div.innerHTML = reportsHaveCoords ? 'Ingen rapporter med koordinater.' : 'Tillat posisjon i skjemaet for å vise lokale markører.';
                        return div;
                    };
                    noCoordsControl.addTo(map);
                }
            })();
