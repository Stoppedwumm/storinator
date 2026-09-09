const Database = require('better-sqlite3');
const path = require('path');
const fs = require('fs');

let dbInstance = null;

function getDatabase() {
  if (dbInstance) {
    return dbInstance;
  }

  const dbPath = process.env.SQLITE_BIGSTORE_PATH || path.join(__dirname, '../data/databases/bigstore.sqlite');
  const dbDir = path.dirname(dbPath);

  if (!fs.existsSync(dbDir)) {
    fs.mkdirSync(dbDir, { recursive: true });
  }

  dbInstance = new Database(dbPath);
  
  // High-performance concurrency and data integrity settings
  dbInstance.pragma('journal_mode = WAL');
  dbInstance.pragma('synchronous = NORMAL');
  dbInstance.pragma('foreign_keys = ON');
  dbInstance.pragma('busy_timeout = 5000');

  return dbInstance;
}

function closeDatabase() {
  if (dbInstance) {
    dbInstance.close();
    dbInstance = null;
  }
}

module.exports = {
  getDatabase,
  closeDatabase,
};
