const path = require('path');
const { getDatabase } = require('./database');
const { decrementStorageUsage } = require('./quota');

function getFile(id, ownerType = null, ownerId = null) {
  const db = getDatabase();
  let query = 'SELECT * FROM files WHERE id = ? AND deleted_at IS NULL';
  const params = [id];

  if (ownerType && ownerId) {
    query += ' AND owner_type = ? AND owner_id = ?';
    params.push(String(ownerType).toLowerCase().trim(), String(ownerId).trim());
  }

  return db.prepare(query).get(...params);
}

function listFiles({ ownerType, ownerId, directoryId = null }) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();

  if (directoryId) {
    return db.prepare(`
      SELECT * FROM files 
      WHERE owner_type = ? AND owner_id = ? AND directory_id = ? AND deleted_at IS NULL
      ORDER BY original_name ASC
    `).all(cleanOwnerType, cleanOwnerId, directoryId);
  }

  return db.prepare(`
    SELECT * FROM files 
    WHERE owner_type = ? AND owner_id = ? AND directory_id IS NULL AND deleted_at IS NULL
    ORDER BY original_name ASC
  `).all(cleanOwnerType, cleanOwnerId);
}

function deleteFile(id, ownerType, ownerId) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();

  const file = getFile(id, cleanOwnerType, cleanOwnerId);
  if (!file) {
    const err = new Error('File not found or already deleted.');
    err.code = 'FILE_NOT_FOUND';
    err.status = 404;
    throw err;
  }

  db.transaction(() => {
    db.prepare(`
      UPDATE files 
      SET deleted_at = CURRENT_TIMESTAMP 
      WHERE id = ?
    `).run(id);

    decrementStorageUsage(cleanOwnerType, cleanOwnerId, file.size_bytes, id, 'FILE_DELETE');
  })();

  return {
    deleted_file_id: id,
    reclaimed_bytes: file.size_bytes,
  };
}

function renameFile(id, newName, ownerType, ownerId) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();
  const cleanName = path.basename(String(newName).trim());

  if (!cleanName) {
    const err = new Error('New filename cannot be empty.');
    err.code = 'INVALID_FILENAME';
    err.status = 400;
    throw err;
  }

  const file = getFile(id, cleanOwnerType, cleanOwnerId);
  if (!file) {
    const err = new Error('File not found.');
    err.code = 'FILE_NOT_FOUND';
    err.status = 404;
    throw err;
  }

  db.prepare(`
    UPDATE files 
    SET original_name = ?, modified_at = CURRENT_TIMESTAMP 
    WHERE id = ?
  `).run(cleanName, id);

  return getFile(id);
}

function moveFile(id, newDirectoryId, ownerType, ownerId) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();

  const file = getFile(id, cleanOwnerType, cleanOwnerId);
  if (!file) {
    const err = new Error('File not found.');
    err.code = 'FILE_NOT_FOUND';
    err.status = 404;
    throw err;
  }

  let resolvedDirId = null;
  if (newDirectoryId) {
    const dir = db.prepare(`
      SELECT id FROM directories 
      WHERE id = ? AND owner_type = ? AND owner_id = ? AND deleted_at IS NULL
    `).get(newDirectoryId, cleanOwnerType, cleanOwnerId);

    if (!dir) {
      const err = new Error('Target directory not found.');
      err.code = 'DIRECTORY_NOT_FOUND';
      err.status = 404;
      throw err;
    }
    resolvedDirId = dir.id;
  }

  db.prepare(`
    UPDATE files 
    SET directory_id = ?, modified_at = CURRENT_TIMESTAMP 
    WHERE id = ?
  `).run(resolvedDirId, id);

  return getFile(id);
}

module.exports = {
  getFile,
  listFiles,
  deleteFile,
  renameFile,
  moveFile,
};
