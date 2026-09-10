const test = require('node:test');
const assert = require('node:assert');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');

process.env.INTERNAL_BIGSTORE_SECRET = 'test_secret_token_core';
process.env.SQLITE_BIGSTORE_PATH = path.join(__dirname, '../data/test_core_bigstore.sqlite');
process.env.BIGSTORE_STORAGE_ROOT = path.join(__dirname, '../data/test_core_files');

const { runMigrations } = require('../src/migrate');
const { getDatabase, closeDatabase } = require('../src/database');
const { initStorageLayout } = require('../src/storage');
const { app } = require('../src/server');

let server;
let baseUrl;
const SECRET = 'test_secret_token_core';

test.before(async () => {
  // Ensure clean test directories
  if (fs.existsSync(process.env.SQLITE_BIGSTORE_PATH)) {
    fs.unlinkSync(process.env.SQLITE_BIGSTORE_PATH);
  }
  if (fs.existsSync(process.env.BIGSTORE_STORAGE_ROOT)) {
    fs.rmSync(process.env.BIGSTORE_STORAGE_ROOT, { recursive: true, force: true });
  }

  initStorageLayout();
  runMigrations();

  server = app.listen(0);
  const port = server.address().port;
  baseUrl = `http://127.0.0.1:${port}`;
});

test.after(() => {
  if (server) server.close();
  closeDatabase();
  try {
    if (fs.existsSync(process.env.SQLITE_BIGSTORE_PATH)) {
      fs.unlinkSync(process.env.SQLITE_BIGSTORE_PATH);
    }
    if (fs.existsSync(process.env.BIGSTORE_STORAGE_ROOT)) {
      fs.rmSync(process.env.BIGSTORE_STORAGE_ROOT, { recursive: true, force: true });
    }
  } catch {}
});

test('1. BigStore Storage Account & Quota Initialization', async () => {
  const res = await fetch(`${baseUrl}/internal/storage/user/usr_test_1`, {
    headers: { 'X-Internal-Service-Token': SECRET },
  });
  assert.strictEqual(res.status, 200);
  const json = await res.json();
  assert.strictEqual(json.success, true);
  assert.strictEqual(json.data.owner_type, 'user');
  assert.strictEqual(json.data.owner_id, 'usr_test_1');
  assert.strictEqual(json.data.used_bytes, 0);
  assert.strictEqual(json.data.quota_bytes, 53687091200); // 50 GiB
  assert.strictEqual(json.data.available_bytes, 53687091200);
});

test('2. BigStore Directory Operations', async () => {
  // Create root folder
  const createRes = await fetch(`${baseUrl}/internal/directories`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Internal-Service-Token': SECRET,
    },
    body: JSON.stringify({
      owner_type: 'user',
      owner_id: 'usr_test_1',
      name: 'Documents',
    }),
  });
  assert.strictEqual(createRes.status, 201);
  const createJson = await createRes.json();
  assert.strictEqual(createJson.success, true);
  const parentId = createJson.data.id;
  assert.strictEqual(createJson.data.name, 'Documents');
  assert.strictEqual(createJson.data.path, '/Documents');

  // Create nested subfolder
  const subRes = await fetch(`${baseUrl}/internal/directories`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Internal-Service-Token': SECRET,
    },
    body: JSON.stringify({
      owner_type: 'user',
      owner_id: 'usr_test_1',
      name: 'Work',
      parent_id: parentId,
    }),
  });
  assert.strictEqual(subRes.status, 201);
  const subJson = await subRes.json();
  assert.strictEqual(subJson.data.name, 'Work');
  assert.strictEqual(subJson.data.path, '/Documents/Work');

  // List root folders
  const listRes = await fetch(`${baseUrl}/internal/directories?owner_type=user&owner_id=usr_test_1`, {
    headers: { 'X-Internal-Service-Token': SECRET },
  });
  assert.strictEqual(listRes.status, 200);
  const listJson = await listRes.json();
  assert.strictEqual(listJson.data.length, 1);
  assert.strictEqual(listJson.data[0].id, parentId);

  // List subfolders
  const listSubRes = await fetch(`${baseUrl}/internal/directories?owner_type=user&owner_id=usr_test_1&parent_id=${parentId}`, {
    headers: { 'X-Internal-Service-Token': SECRET },
  });
  assert.strictEqual(listSubRes.status, 200);
  const listSubJson = await listSubRes.json();
  assert.strictEqual(listSubJson.data.length, 1);
  assert.strictEqual(listSubJson.data[0].name, 'Work');
});

test('3. BigStore Chunked Upload, Assembly, & Physical Storage Path', async () => {
  const contentChunk0 = Buffer.from('Part 1 of the file content. ');
  const contentChunk1 = Buffer.from('Part 2 with extra data. ');
  const contentChunk2 = Buffer.from('Part 3 concluding the payload.');
  const fullContent = Buffer.concat([contentChunk0, contentChunk1, contentChunk2]);
  const expectedHash = crypto.createHash('sha256').update(fullContent).digest('hex');

  // 1. Initialize upload session
  const initRes = await fetch(`${baseUrl}/internal/uploads/init`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Internal-Service-Token': SECRET,
    },
    body: JSON.stringify({
      owner_type: 'user',
      owner_id: 'usr_test_1',
      original_name: 'sample_report.txt',
      mime_type: 'text/plain',
      total_size_bytes: fullContent.length,
      total_chunks: 3,
      sha256: expectedHash,
    }),
  });
  assert.strictEqual(initRes.status, 201);
  const initJson = await initRes.json();
  const uploadId = initJson.data.upload_id;
  assert.ok(uploadId.startsWith('upl_'));

  // 2. Upload Chunk 0
  const c0Res = await fetch(`${baseUrl}/internal/uploads/${uploadId}/chunk/0`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/octet-stream',
      'X-Internal-Service-Token': SECRET,
    },
    body: contentChunk0,
  });
  assert.strictEqual(c0Res.status, 200);

  // 3. Upload Chunk 1
  const c1Res = await fetch(`${baseUrl}/internal/uploads/${uploadId}/chunk/1`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/octet-stream',
      'X-Internal-Service-Token': SECRET,
    },
    body: contentChunk1,
  });
  assert.strictEqual(c1Res.status, 200);

  // 4. Upload Chunk 2
  const c2Res = await fetch(`${baseUrl}/internal/uploads/${uploadId}/chunk/2`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/octet-stream',
      'X-Internal-Service-Token': SECRET,
    },
    body: contentChunk2,
  });
  assert.strictEqual(c2Res.status, 200);
  const c2Json = await c2Res.json();
  assert.strictEqual(c2Json.data.received_chunks, 3);
  assert.strictEqual(c2Json.data.is_complete, true);

  // 5. Finalize upload
  const finalRes = await fetch(`${baseUrl}/internal/uploads/${uploadId}/finalize`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Internal-Service-Token': SECRET,
    },
    body: JSON.stringify({ expected_sha256: expectedHash }),
  });
  assert.strictEqual(finalRes.status, 200);
  const finalJson = await finalRes.json();
  assert.strictEqual(finalJson.success, true);
  const fileId = finalJson.data.id;
  assert.ok(fileId.startsWith('fil_'));
  assert.strictEqual(finalJson.data.sha256, expectedHash);
  assert.strictEqual(finalJson.data.size_bytes, fullContent.length);

  // Verify physical file exists at two-level hex path (Rule 10 & Spec Section 39)
  const prefix1 = expectedHash.substring(0, 2);
  const prefix2 = expectedHash.substring(2, 4);
  const expectedPhysicalPath = path.join(process.env.BIGSTORE_STORAGE_ROOT, 'objects', prefix1, prefix2, expectedHash);
  assert.ok(fs.existsSync(expectedPhysicalPath), 'Physical hashed file must exist on disk');

  // Verify storage account usage increased
  const quotaRes = await fetch(`${baseUrl}/internal/storage/user/usr_test_1`, {
    headers: { 'X-Internal-Service-Token': SECRET },
  });
  const quotaJson = await quotaRes.json();
  assert.strictEqual(quotaJson.data.used_bytes, fullContent.length);

  // 6. Test full file download
  const dlRes = await fetch(`${baseUrl}/internal/files/${fileId}/download?owner_type=user&owner_id=usr_test_1`, {
    headers: { 'X-Internal-Service-Token': SECRET },
  });
  assert.strictEqual(dlRes.status, 200);
  assert.strictEqual(dlRes.headers.get('content-type'), 'text/plain');
  assert.ok(dlRes.headers.get('content-disposition').includes('sample_report.txt'));
  const downloadedText = await dlRes.text();
  assert.strictEqual(downloadedText, fullContent.toString());

  // 7. Test HTTP Range Request (Spec Section 830)
  const rangeRes = await fetch(`${baseUrl}/internal/files/${fileId}/stream?owner_type=user&owner_id=usr_test_1`, {
    headers: {
      'X-Internal-Service-Token': SECRET,
      'Range': 'bytes=0-5',
    },
  });
  assert.strictEqual(rangeRes.status, 206, 'Should respond with 206 Partial Content');
  assert.strictEqual(rangeRes.headers.get('content-range'), `bytes 0-5/${fullContent.length}`);
  assert.strictEqual(rangeRes.headers.get('content-length'), '6');
  const rangeText = await rangeRes.text();
  assert.strictEqual(rangeText, fullContent.subarray(0, 6).toString());

  // 8. Delete file and check quota reclaim
  const delRes = await fetch(`${baseUrl}/internal/files/${fileId}`, {
    method: 'DELETE',
    headers: {
      'Content-Type': 'application/json',
      'X-Internal-Service-Token': SECRET,
    },
    body: JSON.stringify({ owner_type: 'user', owner_id: 'usr_test_1' }),
  });
  assert.strictEqual(delRes.status, 200);

  // Quota should now be 0 used
  const quotaAfterRes = await fetch(`${baseUrl}/internal/storage/user/usr_test_1`, {
    headers: { 'X-Internal-Service-Token': SECRET },
  });
  const quotaAfterJson = await quotaAfterRes.json();
  assert.strictEqual(quotaAfterJson.data.used_bytes, 0);
});

test('4. BigStore Quota Overflow Rejection', async () => {
  // Attempt to allocate an upload session exceeding quota (51 GiB)
  const hugeSize = 51 * 1024 * 1024 * 1024;
  const res = await fetch(`${baseUrl}/internal/uploads/init`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Internal-Service-Token': SECRET,
    },
    body: JSON.stringify({
      owner_type: 'user',
      owner_id: 'usr_test_overflow',
      original_name: 'huge_archive.zip',
      total_size_bytes: hugeSize,
      total_chunks: 10,
    }),
  });
  assert.strictEqual(res.status, 413, 'Should reject upload exceeding quota with HTTP 413');
  const json = await res.json();
  assert.strictEqual(json.success, false);
  assert.strictEqual(json.error.code, 'QUOTA_EXCEEDED');
});
