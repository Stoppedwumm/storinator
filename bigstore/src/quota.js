const crypto = require('crypto');
const { getDatabase } = require('./database');

const DEFAULT_QUOTA_BYTES = 50 * 1024 * 1024 * 1024; // 50 GiB (53,687,091,200 bytes)

function getStorageAccount(ownerType, ownerId) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();

  let account = db.prepare(`
    SELECT * FROM storage_accounts 
    WHERE owner_type = ? AND owner_id = ?
  `).get(cleanOwnerType, cleanOwnerId);

  if (!account) {
    const id = 'acc_' + crypto.randomBytes(12).toString('hex');
    db.prepare(`
      INSERT INTO storage_accounts (id, owner_type, owner_id, quota_bytes, used_bytes)
      VALUES (?, ?, ?, ?, 0)
    `).run(id, cleanOwnerType, cleanOwnerId, DEFAULT_QUOTA_BYTES);

    account = db.prepare(`
      SELECT * FROM storage_accounts 
      WHERE id = ?
    `).get(id);
  }

  const availableBytes = Math.max(0, Number(account.quota_bytes) - Number(account.used_bytes));

  return {
    id: account.id,
    owner_type: account.owner_type,
    owner_id: account.owner_id,
    quota_bytes: Number(account.quota_bytes),
    used_bytes: Number(account.used_bytes),
    available_bytes: availableBytes,
    created_at: account.created_at,
    updated_at: account.updated_at,
  };
}

function checkQuotaAvailable(ownerType, ownerId, bytesToAdd) {
  const account = getStorageAccount(ownerType, ownerId);
  const needed = Number(bytesToAdd);

  if (account.used_bytes + needed > account.quota_bytes) {
    return {
      ok: false,
      error: 'QUOTA_EXCEEDED',
      message: `Storage quota exceeded. Needed: ${needed} bytes, Available: ${account.available_bytes} bytes.`,
      quota_bytes: account.quota_bytes,
      used_bytes: account.used_bytes,
      available_bytes: account.available_bytes,
    };
  }

  return {
    ok: true,
    available_bytes: account.available_bytes,
    quota_bytes: account.quota_bytes,
    used_bytes: account.used_bytes,
  };
}

function incrementStorageUsage(ownerType, ownerId, bytesDelta, fileId = null, eventType = 'FILE_UPLOAD') {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();
  const delta = Number(bytesDelta);

  // Ensure account exists
  getStorageAccount(cleanOwnerType, cleanOwnerId);

  const tx = db.transaction(() => {
    db.prepare(`
      UPDATE storage_accounts
      SET used_bytes = used_bytes + ?,
          updated_at = CURRENT_TIMESTAMP
      WHERE owner_type = ? AND owner_id = ?
    `).run(delta, cleanOwnerType, cleanOwnerId);

    db.prepare(`
      INSERT INTO storage_events (event_type, owner_type, owner_id, file_id, bytes_delta)
      VALUES (?, ?, ?, ?, ?)
    `).run(eventType, cleanOwnerType, cleanOwnerId, fileId, delta);
  });

  tx();
  return getStorageAccount(cleanOwnerType, cleanOwnerId);
}

function decrementStorageUsage(ownerType, ownerId, bytesDelta, fileId = null, eventType = 'FILE_DELETE') {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();
  const delta = Number(bytesDelta);

  const tx = db.transaction(() => {
    db.prepare(`
      UPDATE storage_accounts
      SET used_bytes = MAX(0, used_bytes - ?),
          updated_at = CURRENT_TIMESTAMP
      WHERE owner_type = ? AND owner_id = ?
    `).run(delta, cleanOwnerType, cleanOwnerId);

    db.prepare(`
      INSERT INTO storage_events (event_type, owner_type, owner_id, file_id, bytes_delta)
      VALUES (?, ?, ?, ?, ?)
    `).run(eventType, cleanOwnerType, cleanOwnerId, fileId, -delta);
  });

  tx();
  return getStorageAccount(cleanOwnerType, cleanOwnerId);
}

function recalculateStorageUsage(ownerType, ownerId) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();

  const total = db.prepare(`
    SELECT COALESCE(SUM(size_bytes), 0) as total_size
    FROM files
    WHERE owner_type = ? AND owner_id = ? AND deleted_at IS NULL
  `).get(cleanOwnerType, cleanOwnerId).total_size;

  db.prepare(`
    UPDATE storage_accounts
    SET used_bytes = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE owner_type = ? AND owner_id = ?
  `).run(Number(total), cleanOwnerType, cleanOwnerId);

  return getStorageAccount(cleanOwnerType, cleanOwnerId);
}

module.exports = {
  DEFAULT_QUOTA_BYTES,
  getStorageAccount,
  checkQuotaAvailable,
  incrementStorageUsage,
  decrementStorageUsage,
  recalculateStorageUsage,
};
