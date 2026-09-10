const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { getDatabase } = require('./database');
const {
  getObjectPath,
  getTempUploadDir,
  calculateFileHash,
  removeFileSafe,
  removeDirSafe,
} = require('./storage');
const { checkQuotaAvailable, incrementStorageUsage } = require('./quota');

function initUpload({
  ownerType,
  ownerId,
  directoryId = null,
  originalName,
  mimeType = 'application/octet-stream',
  totalSizeBytes,
  totalChunks = 1,
  sha256 = null,
}) {
  const db = getDatabase();
  const cleanOwnerType = String(ownerType).toLowerCase().trim();
  const cleanOwnerId = String(ownerId).trim();
  const cleanName = path.basename(String(originalName).trim());
  const sizeBytes = parseInt(totalSizeBytes, 10);
  const chunks = parseInt(totalChunks, 10);

  if (!cleanName) {
    const err = new Error('Filename cannot be empty.');
    err.code = 'INVALID_FILENAME';
    err.status = 400;
    throw err;
  }

  if (isNaN(sizeBytes) || sizeBytes < 0) {
    const err = new Error('Invalid total size in bytes.');
    err.code = 'INVALID_FILE_SIZE';
    err.status = 400;
    throw err;
  }

  if (isNaN(chunks) || chunks < 1) {
    const err = new Error('Total chunks must be at least 1.');
    err.code = 'INVALID_CHUNK_COUNT';
    err.status = 400;
    throw err;
  }

  // Check quota
  const quotaCheck = checkQuotaAvailable(cleanOwnerType, cleanOwnerId, sizeBytes);
  if (!quotaCheck.ok) {
    const err = new Error(quotaCheck.message);
    err.code = 'QUOTA_EXCEEDED';
    err.status = 413;
    err.details = quotaCheck;
    throw err;
  }

  // Validate directory if provided
  let resolvedDirId = null;
  if (directoryId) {
    const dir = db.prepare(`
      SELECT id FROM directories 
      WHERE id = ? AND owner_type = ? AND owner_id = ? AND deleted_at IS NULL
    `).get(directoryId, cleanOwnerType, cleanOwnerId);

    if (!dir) {
      const err = new Error('Target directory not found.');
      err.code = 'DIRECTORY_NOT_FOUND';
      err.status = 404;
      throw err;
    }
    resolvedDirId = dir.id;
  }

  const uploadId = 'upl_' + crypto.randomBytes(12).toString('hex');
  const tempDir = getTempUploadDir(uploadId);

  db.prepare(`
    INSERT INTO uploads (
      id, owner_type, owner_id, directory_id, original_name,
      mime_type, total_size_bytes, total_chunks, received_chunks,
      sha256, status, temp_dir
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'UPLOADING', ?)
  `).run(
    uploadId,
    cleanOwnerType,
    cleanOwnerId,
    resolvedDirId,
    cleanName,
    mimeType || 'application/octet-stream',
    sizeBytes,
    chunks,
    sha256 ? sha256.toLowerCase().trim() : null,
    tempDir
  );

  return {
    upload_id: uploadId,
    original_name: cleanName,
    mime_type: mimeType,
    total_size_bytes: sizeBytes,
    total_chunks: chunks,
    status: 'UPLOADING',
  };
}

function saveChunkStream({ uploadId, chunkIndex, stream, chunkSize = null }) {
  return new Promise((resolve, reject) => {
    const db = getDatabase();
    const cleanUploadId = String(uploadId).trim();
    const idx = parseInt(chunkIndex, 10);

    const upload = db.prepare(`SELECT * FROM uploads WHERE id = ?`).get(cleanUploadId);
    if (!upload) {
      const err = new Error('Upload session not found.');
      err.code = 'UPLOAD_NOT_FOUND';
      err.status = 404;
      return reject(err);
    }

    if (upload.status !== 'UPLOADING') {
      const err = new Error(`Upload session is in ${upload.status} state.`);
      err.code = 'INVALID_UPLOAD_STATE';
      err.status = 400;
      return reject(err);
    }

    if (isNaN(idx) || idx < 0 || idx >= upload.total_chunks) {
      const err = new Error(`Chunk index ${idx} out of range (0..${upload.total_chunks - 1}).`);
      err.code = 'INVALID_CHUNK_INDEX';
      err.status = 400;
      return reject(err);
    }

    const chunkPath = path.join(upload.temp_dir, `chunk_${idx}`);
    const writeStream = fs.createWriteStream(chunkPath);
    let bytesWritten = 0;

    stream.on('data', (data) => {
      bytesWritten += data.length;
    });

    stream.pipe(writeStream);

    writeStream.on('finish', () => {
      try {
        db.transaction(() => {
          db.prepare(`
            INSERT INTO upload_chunks (upload_id, chunk_index, chunk_size_bytes)
            VALUES (?, ?, ?)
            ON CONFLICT(upload_id, chunk_index) DO UPDATE SET 
              chunk_size_bytes = excluded.chunk_size_bytes,
              received_at = CURRENT_TIMESTAMP
          `).run(cleanUploadId, idx, bytesWritten);

          const countRow = db.prepare(`
            SELECT COUNT(*) as count FROM upload_chunks WHERE upload_id = ?
          `).get(cleanUploadId);

          db.prepare(`
            UPDATE uploads 
            SET received_chunks = ? 
            WHERE id = ?
          `).run(countRow.count, cleanUploadId);
        })();

        const updatedUpload = db.prepare(`SELECT * FROM uploads WHERE id = ?`).get(cleanUploadId);

        resolve({
          upload_id: cleanUploadId,
          chunk_index: idx,
          chunk_size_bytes: bytesWritten,
          received_chunks: updatedUpload.received_chunks,
          total_chunks: updatedUpload.total_chunks,
          is_complete: updatedUpload.received_chunks >= updatedUpload.total_chunks,
        });
      } catch (dbErr) {
        reject(dbErr);
      }
    });

    writeStream.on('error', (err) => {
      reject(err);
    });
  });
}

async function finalizeUpload({ uploadId, expectedSha256 = null }) {
  const db = getDatabase();
  const cleanUploadId = String(uploadId).trim();

  const upload = db.prepare(`SELECT * FROM uploads WHERE id = ?`).get(cleanUploadId);
  if (!upload) {
    const err = new Error('Upload session not found.');
    err.code = 'UPLOAD_NOT_FOUND';
    err.status = 404;
    throw err;
  }

  if (upload.status !== 'UPLOADING') {
    const err = new Error(`Upload session is in ${upload.status} state.`);
    err.code = 'INVALID_UPLOAD_STATE';
    err.status = 400;
    throw err;
  }

  // Verify all chunks exist
  const chunks = db.prepare(`
    SELECT chunk_index, chunk_size_bytes 
    FROM upload_chunks 
    WHERE upload_id = ? 
    ORDER BY chunk_index ASC
  `).all(cleanUploadId);

  if (chunks.length < upload.total_chunks) {
    const err = new Error(`Upload incomplete: received ${chunks.length}/${upload.total_chunks} chunks.`);
    err.code = 'CHUNKS_INCOMPLETE';
    err.status = 400;
    throw err;
  }

  for (let i = 0; i < upload.total_chunks; i++) {
    const chunkFile = path.join(upload.temp_dir, `chunk_${i}`);
    if (!fs.existsSync(chunkFile)) {
      const err = new Error(`Chunk ${i} file is missing on disk.`);
      err.code = 'CHUNK_FILE_MISSING';
      err.status = 400;
      throw err;
    }
  }

  // Assemble chunks into temporary assembled file
  const assembledPath = path.join(upload.temp_dir, 'assembled.tmp');
  const writeStream = fs.createWriteStream(assembledPath);
  const hash = crypto.createHash('sha256');
  let actualSize = 0;

  for (let i = 0; i < upload.total_chunks; i++) {
    const chunkFile = path.join(upload.temp_dir, `chunk_${i}`);
    const chunkBuffer = fs.readFileSync(chunkFile);
    actualSize += chunkBuffer.length;
    hash.update(chunkBuffer);
    writeStream.write(chunkBuffer);
  }

  await new Promise((resolve) => writeStream.end(resolve));

  const actualSha256 = hash.digest('hex');

  // Verify checksum if expected
  const checkHash = expectedSha256 || upload.sha256;
  if (checkHash && checkHash.toLowerCase().trim() !== actualSha256) {
    removeFileSafe(assembledPath);
    const err = new Error(`Checksum mismatch. Expected: ${checkHash}, Actual: ${actualSha256}`);
    err.code = 'CHECKSUM_MISMATCH';
    err.status = 400;
    throw err;
  }

  // Verify quota again with actual size
  const quotaCheck = checkQuotaAvailable(upload.owner_type, upload.owner_id, actualSize);
  if (!quotaCheck.ok) {
    removeFileSafe(assembledPath);
    const err = new Error(quotaCheck.message);
    err.code = 'QUOTA_EXCEEDED';
    err.status = 413;
    throw err;
  }

  // Determine physical storage path
  const { relativePath, absolutePath } = getObjectPath(actualSha256);

  // Move assembled file to final hashed location (or deduplicate if existing)
  if (fs.existsSync(absolutePath)) {
    // Content-addressable match already exists physically
    removeFileSafe(assembledPath);
  } else {
    fs.renameSync(assembledPath, absolutePath);
  }

  // Create file record
  const fileId = 'fil_' + crypto.randomBytes(12).toString('hex');
  const storedName = actualSha256;

  const fileRecord = db.transaction(() => {
    db.prepare(`
      INSERT INTO files (
        id, owner_type, owner_id, directory_id, stored_name,
        original_name, mime_type, size_bytes, sha256, storage_path
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).run(
      fileId,
      upload.owner_type,
      upload.owner_id,
      upload.directory_id,
      storedName,
      upload.original_name,
      upload.mime_type,
      actualSize,
      actualSha256,
      relativePath
    );

    // Update storage usage
    incrementStorageUsage(upload.owner_type, upload.owner_id, actualSize, fileId, 'FILE_UPLOAD');

    // Update upload status
    db.prepare(`
      UPDATE uploads 
      SET status = 'COMPLETED',
          sha256 = ?,
          completed_at = CURRENT_TIMESTAMP
      WHERE id = ?
    `).run(actualSha256, cleanUploadId);

    return db.prepare(`SELECT * FROM files WHERE id = ?`).get(fileId);
  })();

  // Clean up temporary chunks
  removeDirSafe(upload.temp_dir);

  return fileRecord;
}

async function abortUpload({ uploadId }) {
  const db = getDatabase();
  const cleanUploadId = String(uploadId).trim();

  const upload = db.prepare(`SELECT * FROM uploads WHERE id = ?`).get(cleanUploadId);
  if (upload) {
    db.prepare(`UPDATE uploads SET status = 'ABORTED' WHERE id = ?`).run(cleanUploadId);
    removeDirSafe(upload.temp_dir);
  }

  return { success: true, upload_id: cleanUploadId, status: 'ABORTED' };
}

function getUploadStatus(uploadId) {
  const db = getDatabase();
  const cleanUploadId = String(uploadId).trim();

  const upload = db.prepare(`SELECT * FROM uploads WHERE id = ?`).get(cleanUploadId);
  if (!upload) {
    const err = new Error('Upload session not found.');
    err.code = 'UPLOAD_NOT_FOUND';
    err.status = 404;
    throw err;
  }

  const chunks = db.prepare(`
    SELECT chunk_index, chunk_size_bytes, received_at 
    FROM upload_chunks 
    WHERE upload_id = ? 
    ORDER BY chunk_index ASC
  `).all(cleanUploadId);

  return {
    upload,
    chunks,
  };
}

module.exports = {
  initUpload,
  saveChunkStream,
  finalizeUpload,
  abortUpload,
  getUploadStatus,
};
