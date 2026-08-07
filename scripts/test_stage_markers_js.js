/**
 * Node checks for score-form / scoreboard marker helpers (logic mirrors).
 */
'use strict';

let pass = 0;
let fail = 0;

function assert(cond, label) {
  if (cond) {
    pass++;
    console.log('  PASS ', label);
  } else {
    fail++;
    console.log('  FAIL ', label);
  }
}

function parseMarkersJson(raw) {
  if (!raw) return [];
  try {
    const data = JSON.parse(raw);
    if (!Array.isArray(data)) return [];
    return data
      .filter((m) => {
        if (!m || typeof m !== 'object') return false;
        if (m.x == null || m.y == null || m.x === '' || m.y === '') return false;
        return Number.isFinite(Number(m.x)) && Number.isFinite(Number(m.y));
      })
      .slice(0, 4)
      .map((m) => ({ x: Number(m.x), y: Number(m.y) }));
  } catch (e) {
    return [];
  }
}

function normalizeMarkers(raw) {
  if (!Array.isArray(raw)) return [];
  return raw
    .filter((m) => {
      if (!m || typeof m !== 'object') return false;
      if (m.x == null || m.y == null || m.x === '' || m.y === '') return false;
      return Number.isFinite(Number(m.x)) && Number.isFinite(Number(m.y));
    })
    .slice(0, 4)
    .map((m) => ({ x: Number(m.x), y: Number(m.y) }));
}

function clamp(n, min, max) {
  return Math.min(max, Math.max(min, n));
}

console.log('=== JS marker helpers ===');
assert(JSON.stringify(parseMarkersJson('')) === '[]', 'empty → []');
assert(JSON.stringify(parseMarkersJson('[]')) === '[]', '[] → []');
assert(JSON.stringify(parseMarkersJson('nope')) === '[]', 'bad json → []');
assert(JSON.stringify(parseMarkersJson('{"x":1}')) === '[]', 'object → []');
assert(parseMarkersJson('[{"x":1,"y":2}]').length === 1, 'one marker');
assert(parseMarkersJson('[{"x":1,"y":2},{"x":3,"y":4},{"x":5,"y":6},{"x":7,"y":8},{"x":9,"y":10}]').length === 4, 'cap 4');
assert(parseMarkersJson('[{"x":"a","y":2}]').length === 0, 'non-numeric dropped');
assert(parseMarkersJson('[{"x":1}]').length === 0, 'missing y dropped');
assert(parseMarkersJson('[{"x":null,"y":1}]').length === 0, 'null x dropped');
assert(parseMarkersJson('[{"x":"","y":1}]').length === 0, 'empty string x dropped');
assert(normalizeMarkers([{ x: null, y: 1 }]).length === 0, 'normalize null x');
assert(Number.isFinite(parseMarkersJson('[{"x":1.5,"y":2.25}]')[0].x), 'finite x');
assert(normalizeMarkers(null).length === 0, 'normalize null');
assert(normalizeMarkers(undefined).length === 0, 'normalize undefined');
assert(normalizeMarkers([{ x: 10, y: 20 }]).length === 1, 'normalize one');
assert(clamp(-5, 0, 100) === 0, 'clamp low');
assert(clamp(150, 0, 100) === 100, 'clamp high');
assert(clamp(50, 0, 100) === 50, 'clamp mid');

// Round-trip like hidden input
const markers = [{ x: 12.3456, y: 7.891 }];
const synced = JSON.stringify(markers.map((m) => ({
  x: Math.round(m.x * 1000) / 1000,
  y: Math.round(m.y * 1000) / 1000,
})));
assert(synced === '[{"x":12.346,"y":7.891}]', 'round to 3 decimals');
assert(parseMarkersJson(synced)[0].x === 12.346, 'reparse rounded');

console.log('Passed:', pass, 'Failed:', fail);
process.exit(fail ? 1 : 0);
