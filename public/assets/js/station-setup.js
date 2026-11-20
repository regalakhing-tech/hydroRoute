(() => {
  'use strict';

  const locationForm = document.querySelector('[data-station-location]');
  if (locationForm) {
    const latitude = locationForm.elements.namedItem('latitude');
    const longitude = locationForm.elements.namedItem('longitude');
    const status = locationForm.querySelector('[data-location-status]');
    const deviceButton = locationForm.querySelector('[data-device-location]');
    const bounds = { south: 10.584854, north: 10.746484, west: 121.961241, east: 122.073730 };
    const inBounds = (lat, lng) => Number.isFinite(lat) && Number.isFinite(lng)
      && lat >= bounds.south && lat <= bounds.north && lng >= bounds.west && lng <= bounds.east;
    let map;
    let marker;
    let ring;
    const H = window.HydroLocate;
    const quality = locationForm.querySelector('[data-location-quality]');
    const barangayStatus = locationForm.querySelector('[data-barangay-status]');
    // The barangay lives on the profile form beside this one; detection fills it and the owner saves the profile.
    const profile = document.querySelector('form[action="/station-admin/settings/profile"]');
    const barangaySelect = profile?.elements.namedItem('barangay_id');
    const addressBox = profile?.elements.namedItem('address');
    let boundaries = null;
    let lookup = 0;

    const showPin = (lat, lng, accuracy = null) => {
      if (!map) return;
      if (marker) marker.setLatLng([lat, lng]);
      else marker = window.L.circleMarker([lat, lng], { radius: 9, color: '#075985', fillColor: '#0ea5e9', fillOpacity: 1, weight: 3 }).addTo(map);
      if (ring) { ring.remove(); ring = null; }
      if (accuracy) ring = window.L.circle([lat, lng], { radius: Math.max(15, accuracy), color: '#0ea5e9', weight: 1.5, fillOpacity: 0.12, interactive: false }).addTo(map);
      if (accuracy && H) map.setView([lat, lng], H.zoomFor(accuracy), { animate: true });
      else map.panTo([lat, lng]);
    };
    const tones = { precise: ['Precise', 'bg-emerald-50 text-emerald-800 ring-emerald-100'], approximate: ['Approximate', 'bg-amber-50 text-amber-800 ring-amber-100'],
      low: ['Low accuracy', 'bg-rose-50 text-rose-800 ring-rose-100'], searching: ['Locating…', 'bg-sky-50 text-sky-800 ring-sky-100'] };
    const badge = kind => {
      if (!quality) return;
      quality.hidden = !kind;
      if (!kind) return;
      quality.className = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ' + tones[kind][1];
      quality.textContent = tones[kind][0];
    };
    // ALAB-style barangay: the bundled outline answers at once, an exact OpenStreetMap name for the pin wins.
    const detectBarangay = async (lat, lng) => {
      if (!H || !barangaySelect) return;
      const mine = ++lookup;
      const official = [...barangaySelect.options].filter(o => o.value).map(o => ({ name: o.textContent.trim(), value: o.value }));
      const tell = (text, done) => { barangayStatus.hidden = false; barangayStatus.textContent = text; barangayStatus.classList.toggle('text-water', done); barangayStatus.classList.toggle('text-muted', !done); };
      const apply = (hit, how) => {
        const changed = barangaySelect.value !== hit.value;
        barangaySelect.value = hit.value;
        tell('Barangay ' + hit.name + how + (changed ? ' Save profile to apply it.' : ' The profile already matches.'), true);
      };
      tell('Detecting the barangay…', false);
      boundaries ??= fetch(locationForm.dataset.boundaries, { credentials: 'same-origin' }).then(r => r.json()).catch(() => ({ features: [] }));
      const found = H.outline(lat, lng, (await boundaries).features || []);
      if (mine !== lookup) return;
      const fromOutline = found ? H.match([found.feature.name], official) : null;
      if (fromOutline) apply(fromOutline, found.exact ? ' detected from the map.' : ' (nearest, about ' + found.metres + ' m from its mapped edge).');
      else tell('No Hamtic barangay found at this pin. Choose it on the profile form.', false);
      try {
        const response = await fetch(locationForm.dataset.reverse + '?' + new URLSearchParams({ lat: lat.toFixed(7), lng: lng.toFixed(7) }), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const body = await response.json();
        if (mine !== lookup || !body.ok) return;
        const hit = H.match(body.data.areas || [], official);
        if (hit && (hit.exact || !fromOutline)) apply(hit, ' detected from OpenStreetMap.');
        // An empty station address gets "Street, Barangay, Hamtic, Antique" from the same lookup.
        const name = hit?.name || fromOutline?.name;
        if (addressBox && addressBox.value.trim() === '' && name) addressBox.value = [body.data.street, name, 'Hamtic, Antique'].filter(Boolean).join(', ');
      } catch { /* the outline answer stays */ }
    };
    const choosePin = (lat, lng, accuracy = null) => {
      if (!inBounds(lat, lng)) {
        status.textContent = 'Choose a location within the Hamtic coordinate bounds.';
        return false;
      }
      latitude.value = lat.toFixed(6);
      longitude.value = lng.toFixed(6);
      latitude.setCustomValidity('');
      longitude.setCustomValidity('');
      showPin(lat, lng, accuracy);
      if (accuracy === null) badge(null);
      status.textContent = 'Pin selected. Save location to apply this change.';
      detectBarangay(lat, lng);
      return true;
    };

    if (window.L) {
      const savedLat = latitude.value.trim() === '' ? NaN : Number(latitude.value);
      const savedLng = longitude.value.trim() === '' ? NaN : Number(longitude.value);
      const hasPin = inBounds(savedLat, savedLng);
      map = window.L.map(locationForm.querySelector('[data-station-map]')).setView(
        hasPin ? [savedLat, savedLng] : [(bounds.south + bounds.north) / 2, (bounds.west + bounds.east) / 2],
        hasPin ? 16 : 12
      );
      window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        referrerPolicy: 'strict-origin-when-cross-origin',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      }).addTo(map);
      map.on('click', event => choosePin(event.latlng.lat, event.latlng.lng));
      if (hasPin) showPin(savedLat, savedLng);
      status.textContent = hasPin ? 'Current saved pin shown. Choose a point to change it.' : 'Choose a map point or enter coordinates below.';
      // A saved pin with no barangay on the profile (older stations) gets one suggested straight away.
      if (hasPin && barangaySelect && barangaySelect.value === '') detectBarangay(savedLat, savedLng);
    } else {
      status.textContent = 'The map is unavailable. Enter latitude and longitude below.';
    }

    for (const input of [latitude, longitude]) {
      input.addEventListener('input', () => {
        latitude.setCustomValidity('');
        longitude.setCustomValidity('');
        if (latitude.value.trim() === '' || longitude.value.trim() === '') return;
        const lat = Number(latitude.value);
        const lng = Number(longitude.value);
        if (!inBounds(lat, lng)) {
          status.textContent = 'Enter coordinates within the Hamtic coordinate bounds.';
          input.setCustomValidity('Enter coordinates within the Hamtic coordinate bounds.');
          return;
        }
        showPin(lat, lng);
        status.textContent = 'Coordinates entered. Save location to apply this change.';
        detectBarangay(lat, lng);
      });
    }
    if (!navigator.geolocation || !H) {
      deviceButton.disabled = true;
      deviceButton.title = 'Device location is unavailable. Enter coordinates manually.';
    } else {
      // ALAB-style: fresh high-accuracy fixes for 15 s, best one wins (inside Hamtic first), stop early at 20 m.
      deviceButton.addEventListener('click', () => {
        deviceButton.disabled = true;
        badge('searching');
        status.textContent = 'Finding the station location… stay still, outdoors or near a window if you can.';
        H.watchBest({
          inside: inBounds,
          onFix: position => {
            showPin(position.coords.latitude, position.coords.longitude, position.coords.accuracy);
            status.textContent = 'Improving accuracy… ±' + Math.round(position.coords.accuracy) + ' m';
          },
          onDone: (best, error) => {
            deviceButton.disabled = false;
            if (!best) {
              badge(null);
              status.textContent = error && error.code === 1 ? 'Location permission is off. Allow location for this site, or choose a map point.'
                : 'Device location is unavailable. Choose a map point or enter coordinates manually.';
              return;
            }
            const { latitude: lat, longitude: lng, accuracy } = best.coords;
            if (!choosePin(lat, lng, accuracy)) {
              badge(null);
              status.textContent = 'This device reports a point outside Hamtic (±' + Math.round(accuracy) + ' m). Computers often guess from Wi-Fi; use a phone or choose a map point.';
              return;
            }
            badge(H.grade(accuracy));
            if (accuracy > 50) status.textContent += ' Location is approximate (±' + Math.round(accuracy) + ' m): move the pin to the exact spot if needed.';
          },
        });
      });
    }
  }

  const feeForm = document.querySelector('[data-fee-form]');
  if (feeForm) {
    const bands = feeForm.querySelector('[data-fee-bands]');
    const renumber = () => {
      bands.querySelectorAll('[data-fee-band]').forEach((band, index) => {
        band.querySelectorAll('[name]').forEach(control => {
          control.name = control.name.replace(/^bands\[\d+\]/, `bands[${index}]`);
          control.id = control.name;
          const label = control.closest('div')?.querySelector('label');
          if (label) label.htmlFor = control.id;
        });
      });
    };
    feeForm.querySelector('[data-add-fee-band]').addEventListener('click', () => {
      const band = document.createElement('fieldset');
      band.dataset.feeBand = '';
      band.className = 'grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2 lg:grid-cols-5';
      const legend = document.createElement('legend');
      legend.className = 'px-1 text-sm font-bold';
      legend.textContent = 'Distance band';
      band.append(legend);
      for (const [key, text] of [['min_km', 'Minimum km'], ['min_inclusive', 'Include minimum'], ['max_km', 'Maximum km'], ['max_inclusive', 'Include maximum'], ['fee', 'Fee (PHP)']]) {
        const container = document.createElement('div');
        const label = document.createElement('label');
        label.className = 'label';
        label.textContent = text;
        let control;
        if (key.endsWith('inclusive')) {
          control = document.createElement('select');
          for (const [value, caption] of [['1', 'Included'], ['0', 'Excluded']]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = caption;
            control.append(option);
          }
        } else {
          control = document.createElement('input');
          control.type = 'number';
          control.min = '0';
          control.step = key === 'fee' ? '0.01' : 'any';
          control.required = key !== 'max_km';
        }
        control.className = 'field';
        control.name = `bands[0][${key}]`;
        container.append(label, control);
        band.append(container);
      }
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn-ghost';
      remove.dataset.removeFeeBand = '';
      remove.textContent = 'Remove band';
      band.append(remove);
      bands.append(band);
      renumber();
    });
    bands.addEventListener('click', event => {
      const remove = event.target.closest('[data-remove-fee-band]');
      if (!remove) return;
      remove.closest('[data-fee-band]').remove();
      renumber();
    });
  }

  document.querySelectorAll('[data-hours-window]').forEach(windowRow => {
    const enabled = windowRow.querySelector('[type="checkbox"]');
    const update = () => {
      windowRow.querySelector('[name$="[opens_at]"]').required = enabled.checked;
      windowRow.querySelector('[name$="[closes_at]"]').required = enabled.checked;
    };
    enabled.addEventListener('change', update);
    update();
  });
})();
