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

    const showPin = (lat, lng) => {
      if (!map) return;
      if (marker) marker.setLatLng([lat, lng]);
      else marker = window.L.circleMarker([lat, lng], { radius: 9, color: '#075985', fillColor: '#0ea5e9', fillOpacity: 1, weight: 3 }).addTo(map);
      map.panTo([lat, lng]);
    };
    const choosePin = (lat, lng) => {
      if (!inBounds(lat, lng)) {
        status.textContent = 'Choose a location within the Hamtic coordinate bounds.';
        return;
      }
      latitude.value = lat.toFixed(6);
      longitude.value = lng.toFixed(6);
      latitude.setCustomValidity('');
      longitude.setCustomValidity('');
      showPin(lat, lng);
      status.textContent = 'Pin selected. Save location to apply this change.';
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
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      }).addTo(map);
      map.on('click', event => choosePin(event.latlng.lat, event.latlng.lng));
      if (hasPin) showPin(savedLat, savedLng);
      status.textContent = hasPin ? 'Current saved pin shown. Choose a point to change it.' : 'Choose a map point or enter coordinates below.';
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
      });
    }
    if (!navigator.geolocation) {
      deviceButton.disabled = true;
      deviceButton.title = 'Device location is unavailable. Enter coordinates manually.';
    } else {
      deviceButton.addEventListener('click', () => {
        deviceButton.disabled = true;
        status.textContent = 'Waiting for device location permission.';
        navigator.geolocation.getCurrentPosition(position => {
          deviceButton.disabled = false;
          choosePin(position.coords.latitude, position.coords.longitude);
        }, () => {
          deviceButton.disabled = false;
          status.textContent = 'Device location is unavailable. Choose a map point or enter coordinates manually.';
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
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
