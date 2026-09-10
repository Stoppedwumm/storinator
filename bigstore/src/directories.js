const crypto = require('crypto');
const { getDatabase } = require('./database');
const { decrementStorageUsage } = require('./quota');

function createDirectory({ ownerType, ownerId, name, parentId = null }) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();
  const cleanName = String(name).trim();

  if (!cleanName || cleanName === '.' || cleanName === '..' || cleanName.includes('/') || cleanName.includes('\\')) {
    const err = new Error('Invalid directory name.');
    err.code = 'INVALID_DIRECTORY_NAME';
    err.status = 400;
    throw err;
  }

  let dirPath = '/' + cleanName;
  let resolvedParentId = null;

  if (parentId) {
    const parent = db.prepare(`
      SELECT * FROM directories 
      WHERE id = ? AND owner_type = ? AND owner_id = ? AND deleted_at IS NULL
    `).get(parentId, cleanOwnerType, cleanOwnerId);

    if (!parent) {
      const err = new Error('Parent directory does not exist or has been deleted.');
      err.code = 'PARENT_NOT_FOUND';
      err.status = 404;
      throw err;
    }

    resolvedParentId = parent.id;
    dirPath = (parent.path.endsWith('/') ? parent.path : parent.path + '/') + cleanName;
  }

  // Check uniqueness within the same parent
  const existing = db.prepare(`
    SELECT id FROM directories
    WHERE owner_type = ? AND owner_id = ? 
      AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))
      AND name = ?
      AND deleted_at IS NULL
  `).get(cleanOwnerType, cleanOwnerId, resolvedParentId, resolvedParentId, cleanName);

  if (existing) {
    const err = new Error(`A folder named "${cleanName}" already exists in this directory.`);
    err.code = 'DIRECTORY_ALREADY_EXISTS';
    err.status = 409;
    throw err;
  }

  const id = 'dir_' + crypto.randomBytes(12).toString('hex');
  db.prepare(`
    INSERT INTO directories (id, owner_type, owner_id, parent_id, name, path)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(id, cleanOwnerType, cleanOwnerId, resolvedParentId, cleanName, dirPath);

  return db.prepare(`SELECT * FROM directories WHERE id = ?`).get(id);
}

function listDirectories({ ownerType, ownerId, parentId = null }) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();

  if (parentId) {
    return db.prepare(`
      SELECT * FROM directories
      WHERE owner_type = ? AND owner_id = ? AND parent_id = ? AND deleted_at IS NULL
      ORDER BY name ASC
    `).all(cleanOwnerType, cleanOwnerId, parentId);
  }

  return db.prepare(`
    SELECT * FROM directories
    WHERE owner_type = ? AND owner_id = ? AND parent_id IS NULL AND deleted_at IS NULL
    ORDER BY name ASC
  `).all(cleanOwnerType, cleanOwnerId);
}

function getDirectory(id, ownerType = null, ownerId = null) {
  const db = getDatabase();
  let query = 'SELECT * FROM directories WHERE id = ? AND deleted_at IS NULL';
  const params = [id];

  if (ownerType && ownerId) {
    query += ' AND owner_type = ? AND owner_id = ?';
    params.push(String(ownerType).toLowerCase().trim(), String(ownerId).trim());
  }

  return db.prepare(query).get(...params);
}

function deleteDirectory(id, ownerType, ownerId) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();

  const dir = getDirectory(id, cleanOwnerType, cleanOwnerId);
  if (!dir) {
    const err = new Error('Directory not found or already deleted.');
    err.code = 'DIRECTORY_NOT_FOUND';
    err.status = 404;
    throw err;
  }

  let deletedDirs = 0;
  let deletedFiles = 0;
  let reclaimedBytes = 0;

  const tx = db.transaction(() => {
    // Find all descendant directories using path prefix match
    const pathPrefix = dir.path.endsWith('/') ? dir.path : dir.path + '/';
    const subDirs = db.prepare(`
      SELECT id FROM directories 
      WHERE owner_type = ? AND owner_id = ? 
        AND (id = ? OR path LIKE ? OR path = ?)
        AND deleted_at IS NULL
    `).all(cleanOwnerType, cleanOwnerId, id, pathPrefix + '%', dir.path);

    const dirIds = subDirs.map(d => d.id);

    for (const dId of dirIds) {
      // Find files in directory
      const files = db.prepare(`
        SELECT id, size_bytes FROM files 
        WHERE directory_id = ? AND deleted_at IS NULL
      `).all(dId);

      for (const f of files) {
        db.prepare(`UPDATE files SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?`).run(f.id);
        deletedFiles++;
        reclaimedBytes += Number(f.size_bytes);
      }

      db.prepare(`UPDATE directories SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?`).run(dId);
      deletedDirs++;
    }

    if (reclaimedBytes > 0) {
      decrementStorageUsage(cleanOwnerType, cleanOwnerId, reclaimedBytes, null, 'DIRECTORY_DELETE');
    }
  });

  tx();

  return {
    deleted_directory_id: id,
    deleted_directories_count: deletedDirs,
    deleted_files_count: deletedFiles,
    reclaimed_bytes: reclaimedBytes,
  };
}

module.exports = {
  createDirectory,
  listDirectories,
  getDirectory,
  deleteDirectory,
};
