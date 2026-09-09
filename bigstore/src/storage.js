const fs = require('fs');
const path = require('path');

function getStorageRoot() {
  return process.env.BIGSTORE_STORAGE_ROOT || path.join(__dirname, '../data/files');
}

function initStorageLayout() {
  const root = getStorageRoot();
  const subdirs = [
    'user-files',
    'movies',
    'public',
    'store-assets',
    'thumbnails',
    'temporary',
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

module.exports = {
  getStorageRoot,
  initStorageLayout,
};
