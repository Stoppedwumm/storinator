require('dotenv').config();
const express = require('express');
const { getDatabase } = require('./database');
const { runMigrations } = require('./migrate');
const { initStorageLayout } = require('./storage');
const { authenticateInternalService } = require('./security');
const { getStorageAccount } = require('./quota');
const {
  createDirectory,
  listDirectories,
  getDirectory,
  deleteDirectory,
} = require('./directories');
const {
  initUpload,
  saveChunkStream,
  finalizeUpload,
  abortUpload,
  getUploadStatus,
} = require('./uploads');
const {
  getFile,
  listFiles,
  deleteFile,
  renameFile,
  moveFile,
} = require('./files');
const { streamFile } = require('./streaming');

const app = express();
const PORT = process.env.PORT || 8080;

// Health check endpoint (Spec Section 60: GET /internal/health)
// Strictly internal, authenticated via X-Internal-Service-Token
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

// Chunks may be streamed directly as application/octet-stream, so place chunk route BEFORE express.json()
app.put('/internal/uploads/:id/chunk/:index', async (req, res, next) => {
  try {
    const result = await saveChunkStream({
      uploadId: req.params.id,
      chunkIndex: req.params.index,
      stream: req,
      chunkSize: req.headers['content-length'] ? parseInt(req.headers['content-length'], 10) : null,
    });

    return res.status(200).json({
      success: true,
      data: result,
    });
  } catch (err) {
    next(err);
  }
});

// Alias for chunk upload with POST
app.post('/internal/uploads/:id/chunk/:index', async (req, res, next) => {
  try {
    const result = await saveChunkStream({
      uploadId: req.params.id,
      chunkIndex: req.params.index,
      stream: req,
      chunkSize: req.headers['content-length'] ? parseInt(req.headers['content-length'], 10) : null,
    });

    return res.status(200).json({
      success: true,
      data: result,
    });
  } catch (err) {
    next(err);
  }
});

// Body parser for JSON endpoints
app.use(express.json({ limit: '10mb' }));

// -----------------------------------------------------------------------------
// Storage & Quota Endpoints
// -----------------------------------------------------------------------------
app.get('/internal/storage/:ownerType/:ownerId', (req, res, next) => {
  try {
    const account = getStorageAccount(req.params.ownerType, req.params.ownerId);
    return res.status(200).json({
      success: true,
      data: account,
    });
  } catch (err) {
    next(err);
  }
});

// -----------------------------------------------------------------------------
// Directory Management Endpoints
// -----------------------------------------------------------------------------
app.post('/internal/directories', (req, res, next) => {
  try {
    const { owner_type, owner_id, name, parent_id } = req.body;
    if (!owner_type || !owner_id || !name) {
      return res.status(400).json({
        success: false,
        error: { code: 'MISSING_PARAM', message: 'owner_type, owner_id, and name are required.' },
      });
    }

    const dir = createDirectory({
      ownerType: owner_type,
      ownerId: owner_id,
      name,
      parentId: parent_id || null,
    });

    return res.status(201).json({
      success: true,
      data: dir,
    });
  } catch (err) {
    next(err);
  }
});

app.get('/internal/directories', (req, res, next) => {
  try {
    const { owner_type, owner_id, parent_id } = req.query;
    if (!owner_type || !owner_id) {
      return res.status(400).json({
        success: false,
        error: { code: 'MISSING_PARAM', message: 'owner_type and owner_id are required query parameters.' },
      });
    }

    const dirs = listDirectories({
      ownerType: owner_type,
      ownerId: owner_id,
      parentId: parent_id || null,
    });

    return res.status(200).json({
      success: true,
      data: dirs,
    });
  } catch (err) {
    next(err);
  }
});

app.get('/internal/directories/:id', (req, res, next) => {
  try {
    const { owner_type, owner_id } = req.query;
    const dir = getDirectory(req.params.id, owner_type, owner_id);

    if (!dir) {
      return res.status(404).json({
        success: false,
        error: { code: 'DIRECTORY_NOT_FOUND', message: 'Directory not found.' },
      });
    }

    return res.status(200).json({
      success: true,
      data: dir,
    });
  } catch (err) {
    next(err);
  }
});

app.delete('/internal/directories/:id', (req, res, next) => {
  try {
    const ownerType = req.body.owner_type || req.query.owner_type;
    const ownerId = req.body.owner_id || req.query.owner_id;

    if (!ownerType || !ownerId) {
      return res.status(400).json({
        success: false,
        error: { code: 'MISSING_PARAM', message: 'owner_type and owner_id are required.' },
      });
    }

    const result = deleteDirectory(req.params.id, ownerType, ownerId);
    return res.status(200).json({
      success: true,
      data: result,
    });
  } catch (err) {
    next(err);
  }
});

// -----------------------------------------------------------------------------
// Upload Endpoints
// -----------------------------------------------------------------------------
app.post('/internal/uploads/init', (req, res, next) => {
  try {
    const {
      owner_type,
      owner_id,
      directory_id,
      original_name,
      mime_type,
      total_size_bytes,
      total_chunks,
      sha256,
    } = req.body;

    if (!owner_type || !owner_id || !original_name || total_size_bytes === undefined) {
      return res.status(400).json({
        success: false,
        error: {
          code: 'MISSING_PARAM',
          message: 'owner_type, owner_id, original_name, and total_size_bytes are required.',
        },
      });
    }

    const session = initUpload({
      ownerType: owner_type,
      ownerId: owner_id,
      directoryId: directory_id || null,
      originalName: original_name,
      mimeType: mime_type,
      totalSizeBytes: total_size_bytes,
      totalChunks: total_chunks || 1,
      sha256: sha256 || null,
    });

    return res.status(201).json({
      success: true,
      data: session,
    });
  } catch (err) {
    next(err);
  }
});

app.post('/internal/uploads/:id/finalize', async (req, res, next) => {
  try {
    const file = await finalizeUpload({
      uploadId: req.params.id,
      expectedSha256: req.body ? req.body.expected_sha256 : null,
    });

    return res.status(200).json({
      success: true,
      data: file,
    });
  } catch (err) {
    next(err);
  }
});

app.get('/internal/uploads/:id/status', (req, res, next) => {
  try {
    const status = getUploadStatus(req.params.id);
    return res.status(200).json({
      success: true,
      data: status,
    });
  } catch (err) {
    next(err);
  }
});

app.delete('/internal/uploads/:id', async (req, res, next) => {
  try {
    const result = await abortUpload({ uploadId: req.params.id });
    return res.status(200).json(result);
  } catch (err) {
    next(err);
  }
});

// -----------------------------------------------------------------------------
// File Management Endpoints
// -----------------------------------------------------------------------------
app.get('/internal/files', (req, res, next) => {
  try {
    const { owner_type, owner_id, directory_id } = req.query;
    if (!owner_type || !owner_id) {
      return res.status(400).json({
        success: false,
        error: { code: 'MISSING_PARAM', message: 'owner_type and owner_id are required query parameters.' },
      });
    }

    const files = listFiles({
      ownerType: owner_type,
      ownerId: owner_id,
      directoryId: directory_id || null,
    });

    return res.status(200).json({
      success: true,
      data: files,
    });
  } catch (err) {
    next(err);
  }
});

app.get('/internal/files/:id', (req, res, next) => {
  try {
    const { owner_type, owner_id } = req.query;
    const file = getFile(req.params.id, owner_type, owner_id);

    if (!file) {
      return res.status(404).json({
        success: false,
        error: { code: 'FILE_NOT_FOUND', message: 'File not found.' },
      });
    }

    return res.status(200).json({
      success: true,
      data: file,
    });
  } catch (err) {
    next(err);
  }
});

app.get('/internal/files/:id/download', (req, res, next) => {
  try {
    const { owner_type, owner_id } = req.query;
    const file = getFile(req.params.id, owner_type, owner_id);

    if (!file) {
      return res.status(404).json({
        success: false,
        error: { code: 'FILE_NOT_FOUND', message: 'File not found.' },
      });
    }

    return streamFile(file, req, res, { asAttachment: true });
  } catch (err) {
    next(err);
  }
});

app.get('/internal/files/:id/stream', (req, res, next) => {
  try {
    const { owner_type, owner_id } = req.query;
    const file = getFile(req.params.id, owner_type, owner_id);

    if (!file) {
      return res.status(404).json({
        success: false,
        error: { code: 'FILE_NOT_FOUND', message: 'File not found.' },
      });
    }

    return streamFile(file, req, res, { asAttachment: false });
  } catch (err) {
    next(err);
  }
});

app.patch('/internal/files/:id', (req, res, next) => {
  try {
    const { owner_type, owner_id, name, directory_id } = req.body;
    if (!owner_type || !owner_id) {
      return res.status(400).json({
        success: false,
        error: { code: 'MISSING_PARAM', message: 'owner_type and owner_id are required.' },
      });
    }

    let file = getFile(req.params.id, owner_type, owner_id);
    if (!file) {
      return res.status(404).json({
        success: false,
        error: { code: 'FILE_NOT_FOUND', message: 'File not found.' },
      });
    }

    if (name !== undefined) {
      file = renameFile(req.params.id, name, owner_type, owner_id);
    }

    if (directory_id !== undefined) {
      file = moveFile(req.params.id, directory_id || null, owner_type, owner_id);
    }

    return res.status(200).json({
      success: true,
      data: file,
    });
  } catch (err) {
    next(err);
  }
});

app.delete('/internal/files/:id', (req, res, next) => {
  try {
    const ownerType = req.body.owner_type || req.query.owner_type;
    const ownerId = req.body.owner_id || req.query.owner_id;

    if (!ownerType || !ownerId) {
      return res.status(400).json({
        success: false,
        error: { code: 'MISSING_PARAM', message: 'owner_type and owner_id are required.' },
      });
    }

    const result = deleteFile(req.params.id, ownerType, ownerId);
    return res.status(200).json({
      success: true,
      data: result,
    });
  } catch (err) {
    next(err);
  }
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
  const statusCode = err.status || 500;
  const errorCode = err.code || (statusCode === 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED');

  if (statusCode === 500) {
    console.error('[BigStore Internal Error]', err);
  }

  res.status(statusCode).json({
    success: false,
    error: {
      code: errorCode,
      message: err.message || 'An internal storage error occurred.',
      details: err.details,
    },
  });
});

function startServer() {
  try {
    initStorageLayout();
    runMigrations();

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
