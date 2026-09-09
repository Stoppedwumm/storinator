const test = require('node:test');
const assert = require('node:assert');
const path = require('path');
const fs = require('fs');

process.env.INTERNAL_BIGSTORE_SECRET = 'test_secret_token_12345';
process.env.SQLITE_BIGSTORE_PATH = path.join(__dirname, '../data/test_bigstore.sqlite');
process.env.BIGSTORE_STORAGE_ROOT = path.join(__dirname, '../data/test_files');

const { runMigrations } = require('../src/migrate');
const { getDatabase, closeDatabase } = require('../src/database');
const { initStorageLayout } = require('../src/storage');
const { app } = require('../src/server');

test('BigStore Migrations & Schema', (t) => {
  initStorageLayout();
  runMigrations();

  const db = getDatabase();
  const tables = db.prepare("SELECT name FROM sqlite_master WHERE type='table'").all().map(r => r.name);

  assert.ok(tables.includes('bigstore_migrations'), 'bigstore_migrations table exists');
  assert.ok(tables.includes('files'), 'files table exists');
  assert.ok(tables.includes('directories'), 'directories table exists');
  assert.ok(tables.includes('storage_accounts'), 'storage_accounts table exists');
  assert.ok(tables.includes('shares'), 'shares table exists');
});

test('BigStore Security Rejection without Token', async (t) => {
  const server = app.listen(0);
  const port = server.address().port;

  try {
    const res = await fetch(`http://127.0.0.1:${port}/internal/health`);
    assert.strictEqual(res.status, 401, 'Should reject unauthorized request with 401');
    const json = await res.json();
    assert.strictEqual(json.success, false);
    assert.strictEqual(json.error.code, 'UNAUTHORIZED_SERVICE');
  } finally {
    server.close();
  }
});

test('BigStore Health Check with Valid Token', async (t) => {
  const server = app.listen(0);
  const port = server.address().port;

  try {
    const res = await fetch(`http://127.0.0.1:${port}/internal/health`, {
      headers: {
        'X-Internal-Service-Token': 'test_secret_token_12345',
      },
    });
    assert.strictEqual(res.status, 200, 'Should accept authorized request with 200');
    const json = await res.json();
    assert.strictEqual(json.success, true);
    assert.strictEqual(json.data.status, 'healthy');
    assert.strictEqual(json.data.service, 'bigstore');
    assert.strictEqual(json.data.database, 'connected');
  } finally {
    server.close();
    closeDatabase();
    // Clean up test database
    try {
      if (fs.existsSync(process.env.SQLITE_BIGSTORE_PATH)) {
        fs.unlinkSync(process.env.SQLITE_BIGSTORE_PATH);
      }
    } catch {}
  }
});
