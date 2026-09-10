const fs = require('fs');
const path = require('path');
const { getStorageRoot } = require('./storage');

function streamFile(fileRecord, req, res, options = {}) {
  const root = getStorageRoot();
  const absolutePath = path.join(root, fileRecord.storage_path);

  if (!fs.existsSync(absolutePath)) {
    return res.status(404).json({
      success: false,
      error: {
        code: 'PHYSICAL_FILE_NOT_FOUND',
        message: 'The physical storage object for this file could not be found.',
      },
    });
  }

  const stat = fs.statSync(absolutePath);
  const totalSize = stat.size;
  const mimeType = fileRecord.mime_type || 'application/octet-stream';

  // Common response headers
  res.setHeader('Accept-Ranges', 'bytes');
  res.setHeader('ETag', `"${fileRecord.sha256}"`);
  res.setHeader('Last-Modified', new Date(fileRecord.modified_at || stat.mtime).toUTCString());

  if (options.asAttachment) {
    const filename = encodeURIComponent(fileRecord.original_name);
    res.setHeader('Content-Disposition', `attachment; filename="${filename}"; filename*=UTF-8''${filename}`);
  } else {
    res.setHeader('Content-Disposition', `inline; filename="${encodeURIComponent(fileRecord.original_name)}"`);
  }

  const range = req.headers.range;

  if (range) {
    // Parse Range header e.g. "bytes=0-1024" or "bytes=1024-"
    const match = range.match(/bytes=(\d*)-(\d*)/);
    if (!match) {
      res.setHeader('Content-Range', `bytes */${totalSize}`);
      return res.status(416).json({
        success: false,
        error: { code: 'INVALID_RANGE', message: 'Requested range format is invalid.' },
      });
    }

    let start = match[1] ? parseInt(match[1], 10) : 0;
    let end = match[2] ? parseInt(match[2], 10) : totalSize - 1;

    // Handle suffix range e.g. bytes=-500
    if (!match[1] && match[2]) {
      start = totalSize - parseInt(match[2], 10);
      end = totalSize - 1;
    }

    if (isNaN(start) || isNaN(end) || start < 0 || start >= totalSize || end < start || end >= totalSize) {
      res.setHeader('Content-Range', `bytes */${totalSize}`);
      return res.status(416).json({
        success: false,
        error: { code: 'RANGE_NOT_SATISFIABLE', message: 'Requested range cannot be satisfied.' },
      });
    }

    const chunkSize = (end - start) + 1;
    res.status(206);
    res.setHeader('Content-Range', `bytes ${start}-${end}/${totalSize}`);
    res.setHeader('Content-Length', chunkSize);
    res.setHeader('Content-Type', mimeType);

    const stream = fs.createReadStream(absolutePath, { start, end });
    stream.on('error', (err) => {
      console.error('[BigStore Stream Error]', err);
      if (!res.headersSent) {
        res.status(500).end();
      }
    });
    return stream.pipe(res);
  }

  // Full file streaming
  res.status(200);
  res.setHeader('Content-Length', totalSize);
  res.setHeader('Content-Type', mimeType);

  const stream = fs.createReadStream(absolutePath);
  stream.on('error', (err) => {
    console.error('[BigStore Stream Error]', err);
    if (!res.headersSent) {
      res.status(500).end();
    }
  });
  return stream.pipe(res);
}

module.exports = {
  streamFile,
};
