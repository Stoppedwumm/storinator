import { api } from '../api.js';

export async function renderStorefront(container, slug) {
  container.innerHTML = `
    <div id="storefront-loading" style="text-align: center; padding: 6rem 1.5rem; color: var(--text-secondary);">
      <div class="loading-spinner" style="margin: 0 auto 1.5rem;"></div>
      <p style="font-size: 1.1rem;">Entering store...</p>
    </div>
    <div id="storefront-content" style="display: none;"></div>
  `;

  const loadingEl = document.getElementById('storefront-loading');
  const contentEl = document.getElementById('storefront-content');

  try {
    const storeRes = await api.getPublicStore(slug);
    const store = storeRes.data?.store;
    if (!store) {
      throw new Error('Store not found');
    }

    const prodRes = await api.listPublicStoreProducts(slug, { limit: 100 });
    const products = prodRes.data?.products || [];

    renderStoreView(contentEl, store, products);
    loadingEl.style.display = 'none';
    contentEl.style.display = 'block';
  } catch (err) {
    loadingEl.innerHTML = `
      <div style="max-width: 450px; margin: 0 auto; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 3rem 1.5rem;">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🏬</div>
        <h2 style="color: var(--accent-rose); margin-bottom: 0.5rem;">Store Unavailable</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">${err.message || 'This store may have been closed or does not exist.'}</p>
        <a href="#/stores" class="btn btn-primary" style="padding: 0.5rem 1.25rem;">← Back to Stores</a>
      </div>
    `;
  }
}

function renderStoreView(container, store, initialProducts) {
  const themeColor = store.theme_color || '#6366f1';
  const bannerStyle = store.banner_url
    ? `background-image: url('${encodeURI(store.banner_url)}');`
    : `background: linear-gradient(135deg, ${themeColor}, #0b0f19);`;

  const logoContent = store.logo_url
    ? `<img src="${encodeURI(store.logo_url)}" alt="${escapeHtml(store.name)}">`
    : `<span>${(store.name || 'S').charAt(0).toUpperCase()}</span>`;

  // Collect unique categories from products
  const categoryMap = new Map();
  initialProducts.forEach(p => {
    if (p.category_id && p.category_name) {
      categoryMap.set(p.category_id, p.category_name);
    }
  });

  container.innerHTML = `
    <div class="storefront-page" style="--store-theme: ${themeColor};">
      <!-- Store Hero Banner -->
      <div class="storefront-hero" style="${bannerStyle}">
        <div class="storefront-hero-overlay"></div>
        <div class="storefront-header-content">
          <div class="storefront-logo">
            ${logoContent}
          </div>
          <div class="storefront-info">
            <h1 class="storefront-title">${escapeHtml(store.name)}</h1>
            <p class="storefront-desc">${escapeHtml(store.description || 'Welcome to our marketplace.')}</p>
            <div class="storefront-meta">
              ${store.contact_email ? `<span>✉ ${escapeHtml(store.contact_email)}</span>` : ''}
              ${store.contact_phone ? `<span>📞 ${escapeHtml(store.contact_phone)}</span>` : ''}
              <span>📦 ${initialProducts.length} Items Listed</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Store Catalog Container -->
      <div class="storefront-container">
        <!-- Filter Controls -->
        <div class="storefront-controls">
          <div class="storefront-categories" id="category-pills">
            <button class="cat-pill active" data-cat="all">All Products</button>
            ${Array.from(categoryMap.entries()).map(([id, name]) => `
              <button class="cat-pill" data-cat="${id}">${escapeHtml(name)}</button>
            `).join('')}
          </div>

          <div class="storefront-search">
            <span class="storefront-search-icon">🔍</span>
            <input type="text" id="product-search-input" placeholder="Search products...">
          </div>
        </div>

        <!-- Products Grid -->
        <div id="product-grid" class="product-grid"></div>

        <!-- Empty Products Message -->
        <div id="products-empty" style="display: none; text-align: center; padding: 4rem 1.5rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg);">
          <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">📦</div>
          <h3 style="color: var(--text-primary); margin-bottom: 0.5rem;">No Products Found</h3>
          <p style="color: var(--text-secondary); max-width: 400px; margin: 0 auto;">No items match your active filters. Try searching for something else or reset filters.</p>
        </div>
      </div>

      <!-- Product Modal Placeholder -->
      <div id="product-modal-root"></div>
    </div>
  `;

  const gridEl = document.getElementById('product-grid');
  const emptyEl = document.getElementById('products-empty');
  const searchInput = document.getElementById('product-search-input');
  const catPills = document.querySelectorAll('.cat-pill');
  const modalRoot = document.getElementById('product-modal-root');

  let activeCategory = 'all';
  let activeSearch = '';

  function filterAndRender() {
    let filtered = initialProducts;

    if (activeCategory !== 'all') {
      filtered = filtered.filter(p => p.category_id === activeCategory);
    }

    if (activeSearch) {
      filtered = filtered.filter(p =>
        (p.name && p.name.toLowerCase().includes(activeSearch)) ||
        (p.description && p.description.toLowerCase().includes(activeSearch)) ||
        (p.short_description && p.short_description.toLowerCase().includes(activeSearch)) ||
        (p.sku && p.sku.toLowerCase().includes(activeSearch))
      );
    }

    if (filtered.length === 0) {
      gridEl.style.display = 'none';
      emptyEl.style.display = 'block';
    } else {
      emptyEl.style.display = 'none';
      gridEl.style.display = 'grid';
      gridEl.innerHTML = filtered.map(prod => renderProductCard(prod, themeColor)).join('');

      // Wire up card buttons
      gridEl.querySelectorAll('.product-view-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const prodId = btn.dataset.productId;
          const selected = initialProducts.find(p => p.id === prodId);
          if (selected) {
            openProductModal(selected, store, themeColor, modalRoot);
          }
        });
      });
    }
  }

  // Category filter clicks
  catPills.forEach(pill => {
    pill.addEventListener('click', () => {
      catPills.forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
      activeCategory = pill.dataset.cat;
      filterAndRender();
    });
  });

  // Search input
  searchInput.addEventListener('input', (e) => {
    activeSearch = e.target.value.toLowerCase().trim();
    filterAndRender();
  });

  // Initial render
  filterAndRender();
}

function renderProductCard(product, themeColor) {
  const images = Array.isArray(product.images) ? product.images : [];
  const primaryImg = images.length > 0 ? images[0] : null;

  const isAvailable = product.is_available && product.stock_quantity > 0;
  const stockBadgeClass = isAvailable ? 'in-stock' : 'out-stock';
  const stockBadgeText = isAvailable ? `In Stock (${product.stock_quantity})` : 'Out of Stock';

  const thumbHtml = primaryImg
    ? `<img src="${encodeURI(primaryImg)}" alt="${escapeHtml(product.name)}" loading="lazy">`
    : `<div style="font-size: 2rem; color: var(--text-muted);">🛍️</div>`;

  return `
    <div class="product-card">
      <div class="product-thumb-container">
        ${thumbHtml}
        <span class="product-stock-badge ${stockBadgeClass}">${stockBadgeText}</span>
      </div>
      <div class="product-details">
        ${product.category_name ? `<span class="product-cat-tag">${escapeHtml(product.category_name)}</span>` : ''}
        <h3 class="product-title">${escapeHtml(product.name)}</h3>
        <p class="product-desc">${escapeHtml(product.short_description || product.description || '')}</p>
        <div class="product-bottom-row">
          <span class="product-price">${escapeHtml(product.formatted_price || `${(product.price_cents / 100).toFixed(2)} €`)}</span>
          <button class="product-view-btn" data-product-id="${product.id}">View Details</button>
        </div>
      </div>
    </div>
  `;
}

function openProductModal(product, store, themeColor, rootEl) {
  const images = Array.isArray(product.images) ? product.images : [];
  const primaryImg = images.length > 0 ? images[0] : null;
  const isAvailable = product.is_available && product.stock_quantity > 0;
  const variants = Array.isArray(product.variants) ? product.variants : [];

  rootEl.innerHTML = `
    <div class="product-modal-backdrop" id="modal-backdrop">
      <div class="product-modal" role="dialog" aria-modal="true">
        <button class="product-modal-close" id="modal-close-btn" aria-label="Close">✕</button>
        <div class="product-modal-grid">
          <div class="product-modal-media">
            <div class="product-modal-main-img">
              ${primaryImg 
                ? `<img src="${encodeURI(primaryImg)}" alt="${escapeHtml(product.name)}">`
                : `<div style="font-size: 4rem; color: var(--text-muted);">🛍️</div>`
              }
            </div>
          </div>
          <div class="product-modal-info">
            ${product.category_name ? `<span class="product-cat-tag">${escapeHtml(product.category_name)}</span>` : ''}
            <h2 class="product-modal-title">${escapeHtml(product.name)}</h2>
            ${product.sku ? `<div class="product-modal-sku">SKU: ${escapeHtml(product.sku)}</div>` : ''}
            
            <div class="product-modal-price-box">
              <span class="product-modal-price">${escapeHtml(product.formatted_price || `${(product.price_cents / 100).toFixed(2)} €`)}</span>
              <span class="product-stock-badge ${isAvailable ? 'in-stock' : 'out-stock'}">
                ${isAvailable ? `In Stock (${product.stock_quantity} available)` : 'Out of Stock'}
              </span>
            </div>

            <div class="product-modal-desc">${escapeHtml(product.description || 'No detailed description available.')}</div>

            ${variants.length > 0 ? `
              <div class="product-modal-variants">
                <label>Options / Variants:</label>
                <div class="variant-btn-group" id="variant-group">
                  ${variants.map((v, idx) => `
                    <button class="variant-btn ${idx === 0 ? 'active' : ''}" data-val="${escapeHtml(typeof v === 'string' ? v : (v.name || JSON.stringify(v)))}">
                      ${escapeHtml(typeof v === 'string' ? v : (v.name || JSON.stringify(v)))}
                    </button>
                  `).join('')}
                </div>
              </div>
            ` : ''}

            <div class="product-modal-actions">
              <input type="number" id="modal-qty" class="product-qty-input" value="1" min="1" max="${Math.max(1, product.stock_quantity)}" ${!isAvailable ? 'disabled' : ''}>
              <button id="modal-add-btn" class="product-add-cart-btn" ${!isAvailable ? 'disabled' : ''}>
                🛒 Add to Cart
              </button>
            </div>
            <div id="modal-cart-feedback" style="margin-top: 0.75rem; font-size: 0.85rem; text-align: center; display: none;"></div>
          </div>
        </div>
      </div>
    </div>
  `;

  const backdrop = document.getElementById('modal-backdrop');
  const closeBtn = document.getElementById('modal-close-btn');
  const addBtn = document.getElementById('modal-add-btn');
  const qtyInput = document.getElementById('modal-qty');
  const feedbackEl = document.getElementById('modal-cart-feedback');

  function closeModal() {
    rootEl.innerHTML = '';
    document.removeEventListener('keydown', handleKey);
  }

  function handleKey(e) {
    if (e.key === 'Escape') closeModal();
  }

  document.addEventListener('keydown', handleKey);
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) closeModal();
  });
  closeBtn.addEventListener('click', closeModal);

  // Variant selector
  const variantBtns = rootEl.querySelectorAll('.variant-btn');
  let selectedVariant = variants.length > 0 ? (typeof variants[0] === 'string' ? variants[0] : (variants[0].name || '')) : null;
  variantBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      variantBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      selectedVariant = btn.dataset.val;
    });
  });

  // Add to cart click
  if (addBtn && isAvailable) {
    addBtn.addEventListener('click', () => {
      const qty = parseInt(qtyInput.value, 10) || 1;
      
      // Store cart item in localStorage for Phase 11 Cart & Checkout
      try {
        const cartKey = `cart_${store.id}`;
        const existingCart = JSON.parse(localStorage.getItem(cartKey) || '[]');
        const existingIndex = existingCart.findIndex(item => item.productId === product.id && item.variant === selectedVariant);
        
        if (existingIndex > -1) {
          existingCart[existingIndex].quantity = Math.min(product.stock_quantity, existingCart[existingIndex].quantity + qty);
        } else {
          existingCart.push({
            productId: product.id,
            name: product.name,
            price_cents: product.price_cents,
            formatted_price: product.formatted_price,
            sku: product.sku,
            variant: selectedVariant,
            quantity: qty,
            storeId: store.id,
            storeSlug: store.slug,
            image: primaryImg,
          });
        }
        localStorage.setItem(cartKey, JSON.stringify(existingCart));

        feedbackEl.style.display = 'block';
        feedbackEl.style.color = 'var(--accent-emerald)';
        feedbackEl.textContent = `✔ Added ${qty} item(s) to cart!`;
        addBtn.textContent = '✔ Added';
        setTimeout(() => {
          if (addBtn) addBtn.textContent = '🛒 Add to Cart';
        }, 1500);
      } catch (e) {
        console.error('Failed to update cart', e);
      }
    });
  }
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
