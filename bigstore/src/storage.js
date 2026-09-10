const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

function getStorageRoot() {
  return process.env.BIGSTORE_STORAGE_ROOT || path.join(__dirname, '../data/files');
}

function initStorageLayout() {
  const root = getStorageRoot();
  const subdirs = [
    'objects',
    'temporary',
    'user-files',
    'movies',
    'public',
    'store-assets',
    'thumbnails',
  ];

  if (!fs.existsSync(root)) {
    fs.mkdirSync(root, { recursive: true });
  }

  for (const dir of subdirs) {
    const fullPath = path.join(root, dir);
    if (!fs.existsSync(fullPath)) {
      fs.mkdirSync(fullPath, { recursive: true });
    }
  }

  console.log('[BigStore Storage] Storage directory layout verified at:', root);
}

/**
 * Spec Section 39: Physical Hashed Storage Path
 * Example: /data/files/objects/a7/12/a712ea...
 */
function getObjectPath(sha256Hash) {
  const cleanHash = sha256Hash.toLowerCase().replace(/[^a-f0-9]/g, '');
  if (cleanHash.length < 4) {
    throw new Error('Invalid SHA-256 hash for object storage path calculation.');
  }

  const prefix1 = cleanHash.substring(0, 2);
  const prefix2 = cleanHash.substring(2, 4);
  const root = getStorageRoot();

  const dirPath = path.join(root, 'objects', prefix1, prefix2);
  if (!fs.existsSync(dirPath)) {
    fs.mkdirSync(dirPath, { recursive: true });
  }

  const relativePath = path.join('objects', prefix1, prefix2, cleanHash);
  const absolutePath = path.join(dirPath, cleanHash);

  return { relativePath, absolutePath };
}

function getTempUploadDir(uploadId) {
  const cleanId = uploadId.replace(/[^a-zA-Z0-9_\-]/g, '');
  const root = getStorageRoot();
  const tempDir = path.join(root, 'temporary', cleanId);
  if (!fs.existsSync(tempDir)) {
    fs.mkdirSync(tempDir, { recursive: true });
  }
  return tempDir;
}

function calculateFileHash(filePath) {
  return new Promise((resolve, reject) => {
    const hash = crypto.createHash('sha256');
    let sizeBytes = 0;

    const stream = fs.createReadStream(filePath);
    stream.on('data', (chunk) => {
      sizeBytes += chunk.length;
      hash.update(chunk);
    });
    stream.on('end', () => {
      resolve({
        sha256: hash.digest('hex'),
        sizeBytes,
      });
    });
    stream.on('error', (err) => {
      reject(err);
    });
  });
}

function removeFileSafe(filePath) {
  try {
    if (filePath && fs.existsSync(filePath)) {
      fs.unlinkSync(filePath);
    }
  } catch (err) {
    console.warn('[BigStore Storage] Failed to remove file:', filePath, err.message);
  }
}

function removeDirSafe(dirPath) {
  try {
    if (dirPath && fs.existsSync(dirPath)) {
      fs.rmSync(dirPath, { recursive: true, force: true });
    }
  } catch (err) {
    console.warn('[BigStore Storage] Failed to remove dir:', dirPath, err.message);
  }
}

module.exports = {
  getStorageRoot,
  initStorageLayout,
  getObjectPath,
  getTempUploadDir,
  calculateFileHash,
  removeFileSafe,
  removeDirSafe,
};
