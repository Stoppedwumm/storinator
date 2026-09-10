import { auth } from '../auth.js';
import { api } from '../api.js';
import { formatBytes, formatDate, escapeHtml } from '../utils/formatters.js';

const CHUNK_SIZE = 2 * 1024 * 1024; // 2 MiB chunks for streaming

export function renderFiles(container) {
  if (!auth.isAuthenticated()) {
    window.location.hash = '#/login';
    return;
  }

  let currentDirectoryId = null;
  let directoryHistory = []; // [{ id: null, name: 'Home' }]

  function initView() {
    container.innerHTML = `
      <div class="files-container container">
        <!-- Top Storage Header -->
        <div class="files-header card">
          <div class="header-info">
            <div class="header-subtitle">Object Storage Fabric</div>
            <h1 class="header-title">File Manager</h1>
            <p class="header-desc">Internal chunked streaming storage backed by BigStore engine.</p>
          </div>
          <div class="quota-widget" id="quota-widget">
            <div class="quota-meta">
              <span class="quota-label">Storage Quota</span>
              <span class="quota-values" id="quota-text">Loading...</span>
            </div>
            <div class="quota-progress-track">
              <div class="quota-progress-fill" id="quota-bar" style="width: 0%"></div>
            </div>
          </div>
        </div>

        <!-- File Browser Section -->
        <div class="files-browser card">
          <!-- Toolbar -->
          <div class="browser-toolbar">
            <div class="breadcrumbs" id="breadcrumbs">
              <button class="breadcrumb-item active" data-dir-id="">Root</button>
            </div>
            <div class="toolbar-actions">
              <button id="new-folder-btn" class="btn btn-secondary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                New Folder
              </button>
              <label class="btn btn-primary upload-btn-label">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                <span>Upload File</span>
                <input type="file" id="file-upload-input" style="display: none;" multiple>
              </label>
            </div>
          </div>

          <!-- Drag and Drop Dropzone -->
          <div id="dropzone" class="dropzone">
            <div class="dropzone-content">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
              <div class="dropzone-text">Drag & drop files here to stream directly to BigStore</div>
              <div class="dropzone-sub">Chunked multi-part streaming with SHA-256 integrity verification</div>
            </div>
          </div>

          <!-- Upload Progress Notification Area -->
          <div id="upload-status-area" class="upload-status-area" style="display: none;"></div>

          <!-- Alert Notification Area -->
          <div id="browser-alert" class="browser-alert" style="display: none;"></div>

          <!-- Content Grid / Table -->
          <div class="browser-content" id="browser-content">
            <div class="browser-loading">Loading storage items...</div>
          </div>
        </div>

        <!-- Preview Modal -->
        <div id="preview-modal" class="modal-backdrop" style="display: none;">
          <div class="modal-card">
            <div class="modal-header">
              <h3 id="modal-title" class="modal-title">File Preview</h3>
              <button id="modal-close-btn" class="modal-close">&times;</button>
            </div>
            <div id="modal-body" class="modal-body"></div>
          </div>
        </div>
      </div>
    `;

    setupEventListeners();
    loadDirectory(null);
  }

  function setupEventListeners() {
    const fileInput = container.querySelector('#file-upload-input');
    const dropzone = container.querySelector('#dropzone');
    const newFolderBtn = container.querySelector('#new-folder-btn');
    const modalCloseBtn = container.querySelector('#modal-close-btn');
    const modal = container.querySelector('#preview-modal');

    fileInput.addEventListener('change', (e) => {
      if (e.target.files && e.target.files.length > 0) {
        handleFilesUpload(Array.from(e.target.files));
        fileInput.value = '';
      }
    });

    // Drag and drop
    ['dragenter', 'dragover'].forEach(eventName => {
      dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropzone.classList.add('drag-active');
      });
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropzone.classList.remove('drag-active');
      });
    });

    dropzone.addEventListener('drop', (e) => {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        handleFilesUpload(Array.from(e.dataTransfer.files));
      }
    });

    newFolderBtn.addEventListener('click', handleCreateFolder);

    modalCloseBtn.addEventListener('click', () => {
      modal.style.display = 'none';
      const body = container.querySelector('#modal-body');
      body.innerHTML = '';
    });

    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        modal.style.display = 'none';
        container.querySelector('#modal-body').innerHTML = '';
      }
    });
  }

  async function loadDirectory(directoryId) {
    currentDirectoryId = directoryId;
    const contentEl = container.querySelector('#browser-content');
    contentEl.innerHTML = `<div class="browser-loading">Fetching directory contents...</div>`;

    try {
      const data = await api.getFiles(directoryId);
      updateQuotaWidget(data.quota);
      renderDirectoryItems(data.directories || [], data.files || []);
      updateBreadcrumbs();
    } catch (err) {
      contentEl.innerHTML = `
        <div class="empty-state error">
          <p>Failed to load directory contents: ${escapeHtml(err.message)}</p>
          <button class="btn btn-secondary retry-btn">Retry</button>
        </div>
      `;
      contentEl.querySelector('.retry-btn')?.addEventListener('click', () => loadDirectory(directoryId));
    }
  }

  function updateQuotaWidget(quota) {
    if (!quota) return;
    const textEl = container.querySelector('#quota-text');
    const barEl = container.querySelector('#quota-bar');

    const used = quota.used_bytes || 0;
    const total = quota.quota_bytes || 53687091200;
    const pct = Math.min(100, Math.round((used / total) * 10000) / 100);

    textEl.textContent = `${formatBytes(used)} / ${formatBytes(total)} (${pct}% used)`;
    barEl.style.width = `${pct}%`;

    if (pct > 90) {
      barEl.style.background = 'var(--accent-ruby, #ef4444)';
    } else if (pct > 75) {
      barEl.style.background = 'var(--accent-amber, #f59e0b)';
    } else {
      barEl.style.background = 'linear-gradient(90deg, var(--accent-cyan, #06b6d4), var(--accent-emerald, #10b981))';
    }
  }

  function updateBreadcrumbs() {
    const el = container.querySelector('#breadcrumbs');
    let html = `<button class="breadcrumb-item ${currentDirectoryId === null ? 'active' : ''}" data-dir-id="">Root</button>`;

    directoryHistory.forEach((item, idx) => {
      const isLast = idx === directoryHistory.length - 1;
      html += ` <span class="breadcrumb-sep">/</span> <button class="breadcrumb-item ${isLast ? 'active' : ''}" data-dir-id="${escapeHtml(item.id)}">${escapeHtml(item.name)}</button>`;
    });

    el.innerHTML = html;

    el.querySelectorAll('.breadcrumb-item').forEach(btn => {
      btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-dir-id') || null;
        if (targetId === null) {
          directoryHistory = [];
        } else {
          const index = directoryHistory.findIndex(h => h.id === targetId);
          if (index !== -1) {
            directoryHistory = directoryHistory.slice(0, index + 1);
          }
        }
        loadDirectory(targetId);
      });
    });
  }

  function renderDirectoryItems(directories, files) {
    const contentEl = container.querySelector('#browser-content');

    if (directories.length === 0 && files.length === 0) {
      contentEl.innerHTML = `
        <div class="empty-state">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
          <p>This directory is empty.</p>
          <p class="empty-sub">Create a new folder or drag & drop files above to start storing.</p>
        </div>
      `;
      return;
    }

    let html = `<div class="items-grid">`;

    // Render directories
    directories.forEach(dir => {
      html += `
        <div class="item-card directory-card" data-id="${escapeHtml(dir.id)}" data-name="${escapeHtml(dir.name)}">
          <div class="item-icon dir-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
          </div>
          <div class="item-details">
            <div class="item-name" title="${escapeHtml(dir.name)}">${escapeHtml(dir.name)}</div>
            <div class="item-meta">Directory</div>
          </div>
          <div class="item-actions">
            <button class="action-btn delete-dir-btn" title="Delete folder" data-id="${escapeHtml(dir.id)}">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            </button>
          </div>
        </div>
      `;
    });

    // Render files
    files.forEach(file => {
      const isMedia = file.mime_type && (file.mime_type.startsWith('video/') || file.mime_type.startsWith('audio/') || file.mime_type.startsWith('image/'));
      const ext = (file.original_name.split('.').pop() || '').toUpperCase();

      html += `
        <div class="item-card file-card" data-id="${escapeHtml(file.id)}">
          <div class="item-icon file-icon">
            <span class="file-ext">${escapeHtml(ext.slice(0, 4))}</span>
          </div>
          <div class="item-details">
            <div class="item-name" title="${escapeHtml(file.original_name)}">${escapeHtml(file.original_name)}</div>
            <div class="item-meta">${formatBytes(file.size_bytes)} &bull; ${formatDate(file.created_at)}</div>
          </div>
          <div class="item-actions">
            ${isMedia ? `
              <button class="action-btn stream-file-btn" title="Stream media" data-id="${escapeHtml(file.id)}" data-name="${escapeHtml(file.original_name)}" data-mime="${escapeHtml(file.mime_type)}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
              </button>
            ` : ''}
            <a href="${api.getFileDownloadUrl(file.id)}" class="action-btn download-file-btn" title="Download file" download="${escapeHtml(file.original_name)}">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            </a>
            <button class="action-btn delete-file-btn" title="Delete file" data-id="${escapeHtml(file.id)}" data-name="${escapeHtml(file.original_name)}">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            </button>
          </div>
        </div>
      `;
    });

    html += `</div>`;
    contentEl.innerHTML = html;

    // Attach click events to directories
    contentEl.querySelectorAll('.directory-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('.delete-dir-btn')) return;
        const dirId = card.getAttribute('data-id');
        const dirName = card.getAttribute('data-name');
        directoryHistory.push({ id: dirId, name: dirName });
        loadDirectory(dirId);
      });
    });

    // Delete directory handlers
    contentEl.querySelectorAll('.delete-dir-btn').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const dirId = btn.getAttribute('data-id');
        if (confirm('Are you sure you want to delete this folder and its contents?')) {
          try {
            await api.deleteDirectory(dirId);
            loadDirectory(currentDirectoryId);
          } catch (err) {
            showAlert(`Failed to delete folder: ${err.message}`, 'error');
          }
        }
      });
    });

    // Delete file handlers
    contentEl.querySelectorAll('.delete-file-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const fileId = btn.getAttribute('data-id');
        const fileName = btn.getAttribute('data-name');
        if (confirm(`Are you sure you want to delete "${fileName}"?`)) {
          try {
            await api.deleteFile(fileId);
            loadDirectory(currentDirectoryId);
          } catch (err) {
            showAlert(`Failed to delete file: ${err.message}`, 'error');
          }
        }
      });
    });

    // Stream / Preview handlers
    contentEl.querySelectorAll('.stream-file-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const fileId = btn.getAttribute('data-id');
        const fileName = btn.getAttribute('data-name');
        const mime = btn.getAttribute('data-mime') || '';
        openPreviewModal(fileId, fileName, mime);
      });
    });
  }

  function openPreviewModal(fileId, fileName, mime) {
    const modal = container.querySelector('#preview-modal');
    const title = container.querySelector('#modal-title');
    const body = container.querySelector('#modal-body');
    const streamUrl = api.getFileStreamUrl(fileId);

    title.textContent = fileName;

    if (mime.startsWith('video/')) {
      body.innerHTML = `
        <div class="video-preview-wrap">
          <video controls autoplay style="width: 100%; max-height: 450px; background: #000; border-radius: var(--radius-sm);">
            <source src="${streamUrl}" type="${escapeHtml(mime)}">
            Your browser does not support video playback.
          </video>
        </div>
      `;
    } else if (mime.startsWith('audio/')) {
      body.innerHTML = `
        <div style="padding: 2rem; text-align: center;">
          <audio controls autoplay style="width: 100%;">
            <source src="${streamUrl}" type="${escapeHtml(mime)}">
            Your browser does not support audio playback.
          </audio>
        </div>
      `;
    } else if (mime.startsWith('image/')) {
      body.innerHTML = `
        <div style="text-align: center; max-height: 500px; overflow: auto;">
          <img src="${streamUrl}" alt="${escapeHtml(fileName)}" style="max-width: 100%; border-radius: var(--radius-sm);">
        </div>
      `;
    } else {
      body.innerHTML = `
        <div style="padding: 1.5rem; text-align: center;">
          <p>Direct preview is not supported for this media type.</p>
          <a href="${api.getFileDownloadUrl(fileId)}" class="btn btn-primary" download="${escapeHtml(fileName)}">Download File</a>
        </div>
      `;
    }

    modal.style.display = 'flex';
  }

  async function handleCreateFolder() {
    const folderName = prompt('Enter folder name:');
    if (!folderName || !folderName.trim()) return;

    try {
      await api.createDirectory(folderName.trim(), currentDirectoryId);
      loadDirectory(currentDirectoryId);
    } catch (err) {
      showAlert(`Could not create folder: ${err.message}`, 'error');
    }
  }

  async function handleFilesUpload(files) {
    const statusArea = container.querySelector('#upload-status-area');
    statusArea.style.display = 'block';

    for (const file of files) {
      const uploadId = 'upload_' + Math.random().toString(36).substring(2, 9);
      statusArea.innerHTML = `
        <div class="upload-item" id="${uploadId}">
          <div class="upload-item-header">
            <span class="upload-filename">${escapeHtml(file.name)}</span>
            <span class="upload-pct">0%</span>
          </div>
          <div class="upload-progress-track">
            <div class="upload-progress-fill" style="width: 0%"></div>
          </div>
          <div class="upload-meta">Streaming to BigStore...</div>
        </div>
      `;

      const uploadItemEl = statusArea.querySelector(`#${uploadId}`);
      const pctEl = uploadItemEl.querySelector('.upload-pct');
      const fillEl = uploadItemEl.querySelector('.upload-progress-fill');
      const metaEl = uploadItemEl.querySelector('.upload-meta');

      try {
        if (file.size <= CHUNK_SIZE) {
          // Direct single-step upload
          metaEl.textContent = `Uploading single chunk (${formatBytes(file.size)})...`;
          await api.directUpload(file, currentDirectoryId);
          fillEl.style.width = '100%';
          pctEl.textContent = '100%';
          metaEl.textContent = 'Completed!';
        } else {
          // Chunked streaming upload
          const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
          metaEl.textContent = `Initializing chunked session (${totalChunks} chunks)...`;

          const session = await api.initUpload({
            original_name: file.name,
            total_size_bytes: file.size,
            total_chunks: totalChunks,
            mime_type: file.type || 'application/octet-stream',
            directory_id: currentDirectoryId,
          });

          for (let i = 0; i < totalChunks; i++) {
            const start = i * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunkBlob = file.slice(start, end);

            const chunkPct = Math.round(((i) / totalChunks) * 100);
            pctEl.textContent = `${chunkPct}%`;
            fillEl.style.width = `${chunkPct}%`;
            metaEl.textContent = `Streaming chunk ${i + 1} of ${totalChunks} (${formatBytes(end - start)})...`;

            await api.uploadChunk(session.upload_id, i, chunkBlob);
          }

          metaEl.textContent = 'Finalizing object assembly and SHA-256 verification...';
          pctEl.textContent = '99%';
          fillEl.style.width = '99%';

          await api.finalizeUpload(session.upload_id);

          pctEl.textContent = '100%';
          fillEl.style.width = '100%';
          metaEl.textContent = 'Upload successfully committed!';
        }

        setTimeout(() => {
          uploadItemEl.remove();
          if (statusArea.children.length === 0) {
            statusArea.style.display = 'none';
          }
        }, 2000);
      } catch (err) {
        metaEl.textContent = `Error: ${err.message}`;
        metaEl.style.color = 'var(--accent-ruby, #ef4444)';
        fillEl.style.background = 'var(--accent-ruby, #ef4444)';
        showAlert(`Upload error for "${file.name}": ${err.message}`, 'error');
      }
    }

    loadDirectory(currentDirectoryId);
  }

  function showAlert(msg, type = 'info') {
    const alertEl = container.querySelector('#browser-alert');
    alertEl.className = `browser-alert ${type}`;
    alertEl.textContent = msg;
    alertEl.style.display = 'block';
    setTimeout(() => {
      alertEl.style.display = 'none';
    }, 5000);
  }

  initView();
}
