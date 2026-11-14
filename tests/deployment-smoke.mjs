import assert from 'node:assert/strict';

const base = new URL(process.argv[2] || 'https://hydroroute-seven.vercel.app');
let checks = 0;
async function request(path) {
    return fetch(new URL(path, base), {redirect: 'manual', signal: AbortSignal.timeout(25000)});
}

for (const path of ['/', '/api/health', '/login/customer', '/login/station-admin', '/signup', '/register-station']) {
    const response = await request(path);
    assert.equal(response.status, 200, `${path} must load in production`);
    if (path === '/api/health') {
        const health = await response.json();
        assert.equal(health.status, 'ok');
        assert.equal(health.supabase_configured, true);
    } else {
        assert.match(await response.text(), /HydroRoute/, `${path} must render the app`);
    }
    checks++;
}

for (const [path, type] of Object.entries({
    '/assets/css/app.css': 'text/css',
    '/assets/js/app.js': 'javascript',
    '/assets/images/logo.png': 'image/png',
})) {
    const response = await request(path);
    assert.equal(response.status, 200, `${path} must load`);
    assert.ok(response.headers.get('content-type')?.includes(type), `${path} must have the expected type`);
    assert.ok((await response.arrayBuffer()).byteLength > 0, `${path} must contain data`);
    checks++;
}

for (const role of ['customer', 'rider', 'station-admin', 'super-admin']) {
    const response = await request(`/${role}`);
    assert.equal(response.status, 303, `${role} must require a login`);
    assert.equal(response.headers.get('location'), `/login/${role}`);
    checks++;
}

for (const path of ['/.env', '/config/supabase.php', '/bootstrap.php', '/README.md']) {
    const response = await request(path);
    assert.equal(response.status, 404, `${path} must stay private`);
    checks++;
}

console.log(`${checks} production route, asset, login guard and private-file checks passed.`);
