import { api } from '../api.js';

export async function renderPublicShare(container, token) {
  if (!token) {
    container.innerHTML = `
      <div class="public-share-page">
        <div class="public-share-card">
          <div class="share-icon-wrapper share-icon-locked">⚠️</div>
          <h2 class="share-file-title">Invalid Share Link</h2>
          <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">No share token was provided in the URL.</p>
          <a href="#/" class="btn btn-outline">Back to Home</a>
        </div>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="public-share-page">
      <div class="public-share-card">
        <div class="spinner" style="margin: 2rem auto;"></div>
        <p style="color: var(--text-secondary);">Connecting to secure storage...</p>
      </div>
    </div>
  `;

  let shareData = null;
  let unlockToken = null;

  try {
    const res = await api.getPublicShare(token);
    shareData = res.data;
  } catch (err) {
    const errCode = err.code || 'SHARE_UNAVAILABLE';
    let title = 'Share Unavailable';
    let message = err.message || 'This share link could not be loaded.';

    if (errCode === 'NOT_FOUND') {
      title = 'Share Not Found';
      message = 'The requested share link does not exist or may have been deleted.';
    } else if (errCode === 'SHARE_REVOKED') {
      title = 'Link Revoked';
      message = 'This link has been revoked by the owner and is no longer accessible.';
    } else if (errCode === 'SHARE_EXPIRED') {
      title = 'Link Expired';
      message = 'This share link has expired and cannot be accessed.';
    } else if (errCode === 'DOWNLOAD_LIMIT_EXCEEDED') {
      title = 'Download Limit Reached';
      message = 'The maximum download limit for this link has been reached.';
    }

    container.innerHTML = `
      <div class="public-share-page">
        <div class="public-share-card">
          <div class="share-icon-wrapper share-icon-locked">🔒</div>
          <h2 class="share-file-title">${title}</h2>
          <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">${message}</p>
          <a href="#/" class="btn btn-outline">Back to Home</a>
        </div>
      </div>
    `;
    return;
  }

  renderView();

  function renderView() {
    if (shareData.requires_password && !unlockToken) {
      renderPasswordForm();
    } else {
      renderContent();
    }
  }

  function renderPasswordForm() {
    container.innerHTML = `
      <div class="public-share-page">
        <div class="public-share-card">
          <div class="share-icon-wrapper share-icon-locked">🔐</div>
          <h2 class="share-file-title">${escapeHtml(shareData.resource_name || 'Protected File')}</h2>
          <p style="color: var(--text-secondary); font-size: 0.9rem;">
            This file is encrypted and protected with a password. Please enter the password to view or download.
          </p>

          <form id="unlock-form" class="share-password-box">
            <div id="unlock-error" style="display: none; padding: 0.5rem 0.75rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: var(--radius-sm); color: var(--accent-red); font-size: 0.8rem; margin-bottom: 0.75rem;"></div>
            <label for="share-pwd">Password</label>
            <input type="password" id="share-pwd" placeholder="Enter share password" required autofocus autocomplete="current-password" />
            <button type="submit" id="btn-unlock" class="btn btn-primary" style="width: 100%;">
              Unlock File
            </button>
          </form>
        </div>
      </div>
    `;

    const form = container.querySelector('#unlock-form');
    const errEl = container.querySelector('#unlock-error');
    const btn = container.querySelector('#btn-unlock');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const pwd = form.querySelector('#share-pwd').value;
      errEl.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Verifying...';

      try {
        const unlockRes = await api.unlockShare(token, pwd);
        if (unlockRes.data && unlockRes.data.unlock_token) {
          unlockToken = unlockRes.data.unlock_token;
          if (unlockRes.data.file_info) {
            shareData = { ...shareData, ...unlockRes.data.file_info, requires_password: false };
          } else {
            shareData.requires_password = false;
          }
          renderContent();
        } else {
          throw new Error('Unexpected response during unlock.');
        }
      } catch (err) {
        errEl.textContent = err.message || 'Incorrect password.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Unlock File';
      }
    });
  }

  function renderContent() {
    const isAudio = shareData.mime_type && shareData.mime_type.startsWith('audio/');
    const isVideo = shareData.mime_type && shareData.mime_type.startsWith('video/');
    const streamUrl = api.getPublicShareStreamUrl(token, unlockToken);
    const downloadUrl = api.getPublicShareDownloadUrl(token, unlockToken);

    container.innerHTML = `
      <div class="public-share-page">
        <div class="public-share-card">
          <div class="share-icon-wrapper">📄</div>
          <h2 class="share-file-title">${escapeHtml(shareData.resource_name)}</h2>

          <div class="share-meta-tags">
            ${shareData.formatted_size ? `<span class="share-tag share-tag-cyan">📦 ${escapeHtml(shareData.formatted_size)}</span>` : ''}
            ${shareData.mime_type ? `<span class="share-tag">${escapeHtml(shareData.mime_type)}</span>` : ''}
            ${shareData.expires_at ? `<span class="share-tag share-tag-amber">⏳ Expires: ${new Date(shareData.expires_at).toLocaleDateString()}</span>` : ''}
            <span class="share-tag">👁️ ${shareData.view_count || 1} views</span>
            <span class="share-tag">⬇️ ${shareData.download_count || 0} downloads</span>
          </div>

          ${isVideo ? `
            <div class="share-media-container">
              <video controls preload="metadata" src="${streamUrl}"></video>
            </div>
          ` : ''}

          ${isAudio ? `
            <div class="share-media-container" style="padding: 1rem;">
              <audio controls preload="metadata" src="${streamUrl}" style="width: 100%;"></audio>
            </div>
          ` : ''}

          <div class="share-actions-group">
            ${shareData.download_enabled ? `
              <a href="${downloadUrl}" class="btn btn-primary share-download-btn">
                <span>⬇️</span> Download File
              </a>
            ` : `
              <div style="padding: 0.75rem; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem; color: var(--text-muted);">
                🔒 File downloads have been disabled by the owner for this link.
              </div>
            `}
          </div>

          <div style="margin-top: 2rem; font-size: 0.75rem; color: var(--text-muted);">
            Powered by <strong>AETHER Cloud Platform</strong> • End-to-End Encrypted Storage
          </div>
        </div>
      </div>
    `;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
}
