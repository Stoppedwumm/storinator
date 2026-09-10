import { api } from '../api.js';

export async function renderStores(container) {
  container.innerHTML = `
    <div class="stores-page">
      <div class="stores-header">
        <h1>Merchant Marketplaces</h1>
        <p>Explore decentralized stores hosted across the Aether platform. Discover hardware, software, and cloud digital assets.</p>
        <div class="stores-search-wrap">
          <span class="stores-search-icon">🔍</span>
          <input type="text" id="stores-search" class="stores-search-input" placeholder="Search merchant stores...">
        </div>
      </div>

      <div id="stores-loading" style="text-align: center; padding: 4rem 1rem; color: var(--text-secondary);">
        <div class="loading-spinner" style="margin: 0 auto 1rem;"></div>
        <p>Discovering verified merchant stores...</p>
      </div>

      <div id="stores-grid-container" class="stores-grid" style="display: none;"></div>
      
      <div id="stores-empty" style="display: none; text-align: center; padding: 4rem 1.5rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); margin-top: 1rem;">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🏪</div>
        <h3 style="color: var(--text-primary); margin-bottom: 0.5rem;">No Stores Found</h3>
        <p style="color: var(--text-secondary); max-width: 450px; margin: 0 auto;">No active merchant stores match your search criteria. Check back soon or register as a partner to open your store.</p>
      </div>
    </div>
  `;

  const searchInput = document.getElementById('stores-search');
  const loadingEl = document.getElementById('stores-loading');
  const gridEl = document.getElementById('stores-grid-container');
  const emptyEl = document.getElementById('stores-empty');

  let allStores = [];

  try {
    const res = await api.listPublicStores({ limit: 100 });
    allStores = res.data?.stores || [];
    renderStoreCards(allStores);
  } catch (err) {
    loadingEl.innerHTML = `
      <div style="color: var(--accent-rose); font-weight: 600; margin-bottom: 0.5rem;">Failed to load stores</div>
      <p style="font-size: 0.9rem;">${err.message || 'Please check your connection and try again.'}</p>
    `;
    return;
  }

  function renderStoreCards(stores) {
    loadingEl.style.display = 'none';

    if (!stores || stores.length === 0) {
      gridEl.style.display = 'none';
      emptyEl.style.display = 'block';
      return;
    }

    emptyEl.style.display = 'none';
    gridEl.style.display = 'grid';

    gridEl.innerHTML = stores.map(store => {
      const bannerStyle = store.banner_url
        ? `background-image: url('${encodeURI(store.banner_url)}');`
        : `background: linear-gradient(135deg, ${store.theme_color || '#312e81'}, #0f172a);`;

      const logoContent = store.logo_url
        ? `<img src="${encodeURI(store.logo_url)}" alt="${escapeHtml(store.name)}">`
        : `<span>${(store.name || 'S').charAt(0).toUpperCase()}</span>`;

      return `
        <a href="#/stores/${encodeURIComponent(store.slug)}" class="store-card">
          <div class="store-card-banner" style="${bannerStyle}">
            <div class="store-card-logo">
              ${logoContent}
            </div>
          </div>
          <div class="store-card-body">
            <h2 class="store-card-title">${escapeHtml(store.name)}</h2>
            <p class="store-card-desc">${escapeHtml(store.description || 'Welcome to our verified partner store.')}</p>
            <div class="store-card-footer">
              <span class="store-card-badge">${store.products_count || 0} Products</span>
              <span style="color: var(--accent-primary); font-weight: 600; font-size: 0.85rem;">Visit Store →</span>
            </div>
          </div>
        </a>
      `;
    }).join('');
  }

  searchInput.addEventListener('input', (e) => {
    const q = e.target.value.toLowerCase().trim();
    if (!q) {
      renderStoreCards(allStores);
      return;
    }
    const filtered = allStores.filter(s =>
      (s.name && s.name.toLowerCase().includes(q)) ||
      (s.description && s.description.toLowerCase().includes(q))
    );
    renderStoreCards(filtered);
  });
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
