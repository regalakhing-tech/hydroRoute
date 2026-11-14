import assert from 'node:assert/strict';
const base=process.argv[2]||'http://127.0.0.1:8001';
for(const path of ['/customer/station?station=guintas','/station-admin/products']){
 const response=await fetch(base+path); assert.equal(response.status,200,path);
 const html=await response.text();
 for(const name of ['Bottled water 350 ml','Water jug with faucet refill','Water container refill (18.9 L)','Small water container refill (9.5 L)','New water jug with faucet'])assert.ok(html.includes(name),path+' missing '+name);
 assert.ok(html.includes('water-bottle.webp'),path+' needs bottle image');
 assert.ok(html.includes('faucet-container.webp'),path+' needs faucet image');
 assert.ok(!html.includes('PHP 30')&&!html.includes('data-unit-price="30"'),path+' still shows old price');
}
const response=await fetch(base+'/customer/checkout?station=guintas&round=2&faucet=1&bottle350=3');
const checkout=await response.text();
assert.equal(response.status,200);
assert.ok(checkout.includes('PHP 730.00'),'Mixed basket must total PHP 730.00 (2*20 + 1*20 + 3*220 + PHP 10 delivery)');
for(const path of ['/assets/images/water-bottle.webp','/assets/images/faucet-container.webp']){
 const image=await fetch(base+path);assert.equal(image.status,200,path);assert.ok(image.headers.get('content-type')?.includes('image/webp'),path);
}
console.log('Product catalogue, confirmed pricing, mixed checkout total and new product WebP routes passed.');

