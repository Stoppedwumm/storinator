require('dotenv').config();
const express = require('express');
const { getDatabase } = require('./database');
const { runMigrations } = require('./migrate');
const { initStorageLayout } = require('./storage');
const { authenticateInternalService } = require('./security');

const app = express();
const PORT = process.env.PORT || 8080;

app.use(express.json({ limit: '50mb' }));

// Health check endpoint (Spec Section 60: GET /internal/health)
// BigStore is strictly internal, authenticated via X-Internal-Service-Token
app.get('/internal/health', authenticateInternalService, (req, res) => {
  try {
    const db = getDatabase();
    const result = db.prepare('SELECT 1 as healthy').get();

    return res.status(200).json({
      success: true,
      data: {
        status: 'healthy',
        service: 'bigstore',
        node_version: process.version,
        database: result && result.healthy === 1 ? 'connected' : 'error',
        timestamp: new Date().toISOString(),
      },
    });
  } catch (err) {
    console.error('[BigStore Health Check Failed]', err);
    return res.status(500).json({
      success: false,
      error: {
        code: 'HEALTH_CHECK_FAILED',
        message: err.message,
      },
    });
  }
});

// Protect all internal endpoints with service token authentication
app.use('/internal', authenticateInternalService);

// Internal routes placeholder for Phase 4+
app.get('/internal/files', (req, res) => {
  res.json({ success: true, data: { files: [] } });
});

// Global 404 Handler
app.use((req, res) => {
  res.status(404).json({
    success: false,
    error: {
      code: 'NOT_FOUND',
      message: `Endpoint ${req.method} ${req.url} does not exist on BigStore.`,
    },
  });
});

// Global Error Handler
app.use((err, req, res, next) => {
  console.error('[BigStore Internal Error]', err);
  res.status(500).json({
    success: false,
    error: {
      code: 'INTERNAL_ERROR',
      message: process.env.NODE_ENV === 'production' ? 'Internal BigStore error' : err.message,
    },
  });
});

function startServer() {
  try {
    // 1. Initialize storage folders
    initStorageLayout();

    // 2. Run migrations
    runMigrations();

    // 3. Start listening
    const server = app.listen(PORT, '0.0.0.0', () => {
      console.log(`[BigStore] Isolated storage service listening on 0.0.0.0:${PORT}`);
    });

    return server;
  } catch (err) {
    console.error('[BigStore Startup Error]', err);
    process.exit(1);
  }
}

if (require.main === module) {
  startServer();
}

module.exports = { app, startServer };
