// Shared GPS and barangay helpers for the customer address pin and the station location pin, modelled on the ALAB
// resident map: keep the best fresh fix, then name the barangay from OpenStreetMap or the bundled Hamtic outlines.
window.HydroLocate = (() => {
    'use strict';
    // Roman numerals and Spanish/English number words, so "Pob. Tres" and "Poblacion III" both equal "Poblacion 3".
    const ROMAN = {i: '1', ii: '2', iii: '3', iv: '4', v: '5', vi: '6', vii: '7', viii: '8', ix: '9', x: '10',
        uno: '1', dos: '2', tres: '3', cuatro: '4', cinco: '5', singko: '5', one: '1', two: '2', three: '3', four: '4', five: '5'};

    // ALAB's normalizeTokens, plus roman numerals and "Pob." so "Brgy. Poblacion II" equals "Poblacion 2".
    const normalize = text => String(text ?? '').normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
        .replace(/\b(barangay|brgy|bgy|brg)\b\.?/g, ' ').replace(/\bpob\b\.?/g, 'poblacion')
        .replace(/\bnorth\b/g, 'norte').replace(/\bsouth\b/g, 'sur').replace(/\beast\b/g, 'este').replace(/\bwest\b/g, 'weste')
        .split(/[^a-z0-9]+/).filter(Boolean).map(t => ROMAN[t] ?? t).join('-');

    // Official barangay for a list of place names: exact match first (longest names first), then an official name
    // inside a longer string such as "Barangay Guintas, Hamtic, Antique". official: [{name, value}].
    const match = (names, official) => {
        const list = official.map(o => ({...o, key: normalize(o.name)})).filter(o => o.key).sort((a, b) => b.key.length - a.key.length);
        const keys = names.map(normalize).filter(Boolean);
        for (const k of keys) { const hit = list.find(o => o.key === k); if (hit) return {...hit, exact: true}; }
        // A name inside a longer string (often a road named after a barangay) is only a hint.
        for (const k of keys) { const hit = list.find(o => o.key.length > 3 && ('-' + k + '-').includes('-' + o.key + '-')); if (hit) return {...hit, exact: false}; }
        return null;
    };

    const inRing = (x, y, pts) => {
        let hit = false;
        for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
            const [xi, yi] = pts[i], [xj, yj] = pts[j];
            if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) hit = !hit;
        }
        return hit;
    };
    // Metres from a point to an outline edge (flat projection is fine at barangay scale).
    const edgeMetres = (y, x, ring) => {
        const kx = 111320 * Math.cos(y * Math.PI / 180), ky = 110540;
        let best = Infinity;
        for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            const ax = (ring[j][0] - x) * kx, ay = (ring[j][1] - y) * ky, bx = (ring[i][0] - x) * kx, by = (ring[i][1] - y) * ky;
            const dx = bx - ax, dy = by - ay, t = Math.max(0, Math.min(1, -(ax * dx + ay * dy) / (dx * dx + dy * dy || 1)));
            best = Math.min(best, Math.hypot(ax + t * dx, ay + t * dy));
        }
        return best;
    };
    // The bundled outlines are coarse: inside one wins; otherwise the nearest outline within maxMetres, marked approximate.
    const outline = (y, x, features, maxMetres = 1500) => {
        const inside = features.find(f => inRing(x, y, f.rings[0]) && !f.rings.slice(1).some(h => inRing(x, y, h)));
        if (inside) return {feature: inside, exact: true};
        let best = null, distance = Infinity;
        for (const f of features) { const d = edgeMetres(y, x, f.rings[0]); if (d < distance) { distance = d; best = f; } }
        return best && distance <= maxMetres ? {feature: best, exact: false, metres: Math.round(distance)} : null;
    };

    // ALAB's refinement: fresh high-accuracy fixes for windowMs, keep the best (inside the service area first, then the
    // smallest accuracy), report each better one, stop early at targetMetres. Returns a stop function.
    const watchBest = ({inside, onFix, onDone, windowMs = 15000, targetMetres = 20}) => {
        let best = null, done = false, watch = 0;
        const finish = error => {
            if (done) return; done = true;
            navigator.geolocation.clearWatch(watch); clearTimeout(timer);
            onDone(best, error);
        };
        const better = p => {
            if (!best) return true;
            const now = inside(p.coords.latitude, p.coords.longitude), was = inside(best.coords.latitude, best.coords.longitude);
            return now !== was ? now : p.coords.accuracy < best.coords.accuracy;
        };
        const timer = setTimeout(() => finish(null), windowMs);
        watch = navigator.geolocation.watchPosition(p => {
            if (done || !better(p)) return;
            best = p;
            onFix(p);
            if (p.coords.accuracy <= targetMetres && inside(p.coords.latitude, p.coords.longitude)) finish(null);
        }, error => finish(error), {enableHighAccuracy: true, maximumAge: 0, timeout: windowMs});
        return () => finish(null);
    };

    const grade = accuracy => accuracy <= 50 ? 'precise' : accuracy <= 150 ? 'approximate' : 'low';
    const zoomFor = accuracy => accuracy <= 50 ? 18 : accuracy <= 150 ? 16 : accuracy <= 1000 ? 14 : 12;
    return {normalize, match, outline, watchBest, grade, zoomFor};
})();
