const fs = require('fs');
const path = require('path');
const { getDatabase } = require('./database');

function runMigrations() {
  const db = getDatabase();
  const migrationsDir = path.join(__dirname, '../migrations');

  console.log('[BigStore Migration] Starting migrations from:', migrationsDir);

  db.exec(`
    CREATE TABLE IF NOT EXISTS bigstore_migrations (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      migration VARCHAR(255) NOT NULL UNIQUE,
      applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
  `);

  if (!fs.existsSync(migrationsDir)) {
    console.log('[BigStore Migration] No migrations directory found.');
    return;
  }

  const files = fs.readdirSync(migrationsDir)
    .filter(file => file.endsWith('.sql'))
    .sort();

  const getMigrationStmt = db.prepare('SELECT id FROM bigstore_migrations WHERE migration = ?');
  const recordMigrationStmt = db.prepare('INSERT INTO bigstore_migrations (migration) VALUES (?)');

  let appliedCount = 0;

  for (const file of files) {
    const existing = getMigrationStmt.get(file);
    if (!existing) {
      console.log(`[BigStore Migration] Applying migration: ${file}`);
      const sql = fs.readFileSync(path.join(migrationsDir, file), 'utf8');
      
      const transaction = db.transaction(() => {
        db.exec(sql);
        recordMigrationStmt.run(file);
      });

      transaction();
      appliedCount++;
      console.log(`[BigStore Migration] Successfully applied: ${file}`);
    } else {
      console.log(`[BigStore Migration] Already applied: ${file}`);
    }
  }

  console.log(`[BigStore Migration] Completed. Total newly applied: ${appliedCount}`);
}

if (require.main === module) {
  try {
    runMigrations();
    process.exit(0);
  } catch (err) {
    console.error('[BigStore Migration Error]', err);
    process.exit(1);
  }
}

module.exports = { runMigrations };
