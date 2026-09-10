import { api } from '../api.js';

export async function renderMovies(container) {
  let currentSearch = '';
  let currentGenre = '';
  let currentSort = 'created_at';
  let moviesList = [];

  container.innerHTML = `
    <div class="movies-page">
      <div class="movies-header">
        <div class="movies-title-row">
          <h1>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect>
              <line x1="7" y1="2" x2="7" y2="22"></line>
              <line x1="17" y1="2" x2="17" y2="22"></line>
              <line x1="2" y1="12" x2="22" y2="12"></line>
              <line x1="2" y1="7" x2="7" y2="7"></line>
              <line x1="2" y1="17" x2="7" y2="17"></line>
              <line x1="17" y1="17" x2="22" y2="17"></line>
              <line x1="17" y1="7" x2="22" y2="7"></line>
            </svg>
            Cinema Library
          </h1>
          <div class="movies-actions">
            <button id="btn-scan-movies" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
              </svg>
              <span>Scan Storage</span>
            </button>
          </div>
        </div>

        <div class="movies-toolbar">
          <div class="movies-search-wrap">
            <span class="movies-search-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
            </span>
            <input type="text" id="movie-search-input" placeholder="Search by title, director, cast..." />
          </div>

          <div class="genre-filter-row" id="genre-filter-container">
            <button class="genre-pill active" data-genre="">All</button>
            <button class="genre-pill" data-genre="Action">Action</button>
            <button class="genre-pill" data-genre="Sci-Fi">Sci-Fi</button>
            <button class="genre-pill" data-genre="Adventure">Adventure</button>
            <button class="genre-pill" data-genre="Drama">Drama</button>
            <button class="genre-pill" data-genre="Comedy">Comedy</button>
            <button class="genre-pill" data-genre="Crime">Crime</button>
            <button class="genre-pill" data-genre="Animation">Animation</button>
          </div>
        </div>
      </div>

      <div id="movies-content-area">
        <div class="movies-loading" style="text-align: center; padding: 4rem; color: var(--text-muted);">
          Loading cinema library...
        </div>
      </div>
    </div>
  `;

  const searchInput = container.querySelector('#movie-search-input');
  const scanBtn = container.querySelector('#btn-scan-movies');
  const genreContainer = container.querySelector('#genre-filter-container');
  const contentArea = container.querySelector('#movies-content-area');

  // Load movies with active filters
  async function loadMovies() {
    contentArea.innerHTML = `
      <div style="text-align: center; padding: 4rem; color: var(--text-muted);">
        Loading cinema library...
      </div>
    `;

    try {
      const res = await api.listMovies({
        search: currentSearch,
        genre: currentGenre,
        sort: currentSort,
      });

      moviesList = res.data?.movies || [];
      renderGrid(moviesList);
    } catch (err) {
      contentArea.innerHTML = `
        <div class="alert alert-danger" style="margin-top: 1rem;">
          Failed to load cinema library: ${err.message || 'Unknown error'}
        </div>
      `;
    }
  }

  // Render cards grid
  function renderGrid(movies) {
    if (!movies || movies.length === 0) {
      contentArea.innerHTML = `
        <div class="movies-empty">
          <div class="movies-empty-icon">🎬</div>
          <h3>No Movies Found</h3>
          <p>
            ${currentSearch || currentGenre
              ? 'No movies match your current search or genre filters.'
              : 'Upload video files (.mp4, .mkv, .webm) to your storage, then click "Scan Storage" to auto-detect titles, posters, and cinema metadata.'}
          </p>
          <button id="empty-scan-btn" class="btn btn-primary">Scan Storage Now</button>
        </div>
      `;

      container.querySelector('#empty-scan-btn')?.addEventListener('click', handleScan);
      return;
    }

    let html = `<div class="movies-grid">`;
    movies.forEach(m => {
      const posterUrl = m.poster_url || 'https://images.unsplash.com/photo-1489599849927-2ee91cede3ba?w=600&auto=format&fit=crop&q=80';
      const year = m.release_year || '—';
      const rating = m.rating || '—';

      html += `
        <div class="movie-card" data-movie-id="${m.id}">
          <div class="movie-poster-wrap">
            <img src="${posterUrl}" alt="${m.title}" class="movie-poster" loading="lazy" onerror="this.src='https://images.unsplash.com/photo-1489599849927-2ee91cede3ba?w=600&auto=format&fit=crop&q=80'" />
            <div class="movie-badges">
              ${rating !== '—' ? `<span class="badge-pill badge-rating">★ ${rating}</span>` : ''}
              <span class="badge-pill badge-year">${year}</span>
            </div>
            <div class="movie-card-overlay">
              <button class="play-action-btn" data-play-id="${m.id}" title="Play Movie">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                  <polygon points="5 3 19 12 5 21 5 3"></polygon>
                </svg>
              </button>
            </div>
          </div>
          <div class="movie-card-info">
            <div class="movie-card-title" title="${m.title}">${m.title}</div>
            <div class="movie-card-meta">
              <span>${m.genres ? m.genres.split(',')[0].trim() : 'Film'}</span>
              <span>${m.runtime_minutes ? `${m.runtime_minutes}m` : ''}</span>
            </div>
          </div>
        </div>
      `;
    });
    html += `</div>`;
    contentArea.innerHTML = html;

    // Attach card click handlers
    contentArea.querySelectorAll('.movie-card').forEach(card => {
      const id = card.dataset.movieId;
      const movie = moviesList.find(item => item.id === id);

      card.addEventListener('click', (e) => {
        // If play button clicked, launch player directly
        if (e.target.closest('.play-action-btn')) {
          e.stopPropagation();
          if (movie) launchCinemaPlayer(movie);
          return;
        }
        if (movie) openMovieDetailModal(movie);
      });
    });
  }

  // Handle media scan action
  async function handleScan() {
    const origText = scanBtn.innerHTML;
    scanBtn.disabled = true;
    scanBtn.innerHTML = `
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin">
        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
      </svg>
      <span>Scanning...</span>
    `;

    try {
      const res = await api.scanMovies();
      const added = res.data?.added_movies ?? 0;
      const scanned = res.data?.scanned_files ?? 0;
      alert(`Scan complete: Discovered ${scanned} video files, added ${added} new movies to library.`);
      await loadMovies();
    } catch (err) {
      alert(`Scan failed: ${err.message || 'Unknown error'}`);
    } finally {
      scanBtn.disabled = false;
      scanBtn.innerHTML = origText;
    }
  }

  scanBtn.addEventListener('click', handleScan);

  // Search input debouncer
  let searchTimer = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      currentSearch = searchInput.value.trim();
      loadMovies();
    }, 300);
  });

  // Genre pills
  genreContainer.querySelectorAll('.genre-pill').forEach(pill => {
    pill.addEventListener('click', () => {
      genreContainer.querySelectorAll('.genre-pill').forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
      currentGenre = pill.dataset.genre || '';
      loadMovies();
    });
  });

  // Movie Details Modal
  function openMovieDetailModal(movie) {
    const backdrop = movie.backdrop_url || movie.poster_url || '';
    const poster = movie.poster_url || '';

    const modal = document.createElement('div');
    modal.className = 'movie-detail-modal';
    modal.innerHTML = `
      <div class="movie-detail-content">
        <button class="movie-modal-close" title="Close">&times;</button>
        <div class="movie-backdrop-hero" style="background-image: url('${backdrop}');">
          <div class="movie-backdrop-gradient"></div>
        </div>
        <div class="movie-detail-body">
          <div class="movie-detail-poster-wrap">
            <img src="${poster}" alt="${movie.title}" class="movie-detail-poster" />
          </div>
          <div class="movie-detail-info">
            <div class="movie-detail-title">${movie.title}</div>
            <div class="movie-detail-meta-row">
              <span class="badge-pill badge-rating">★ ${movie.rating || 'N/A'}</span>
              <span class="badge-pill badge-year">${movie.release_year || 'Unknown'}</span>
              ${movie.runtime_minutes ? `<span class="badge-pill">${movie.runtime_minutes} min</span>` : ''}
              ${movie.genres ? `<span class="badge-pill" style="background: rgba(99, 102, 241, 0.4);">${movie.genres}</span>` : ''}
            </div>

            <p class="movie-detail-plot">${movie.description || 'No synopsis available for this title.'}</p>

            <div class="movie-detail-specs">
              <div class="movie-spec-label">Director</div>
              <div class="movie-spec-val">${movie.director || 'Unknown'}</div>
              <div class="movie-spec-label">Cast</div>
              <div class="movie-spec-val">${movie.cast_members || 'Unknown'}</div>
              <div class="movie-spec-label">Original Title</div>
              <div class="movie-spec-val">${movie.original_title || movie.title}</div>
            </div>

            <div class="movie-detail-actions">
              <button id="modal-play-btn" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.75rem; font-size: 1.05rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                  <polygon points="5 3 19 12 5 21 5 3"></polygon>
                </svg>
                <span>Play Movie</span>
              </button>

              <button id="modal-edit-btn" class="btn btn-secondary">Edit Metadata</button>
              <button id="modal-delete-btn" class="btn btn-danger" style="margin-left: auto;">Delete</button>
            </div>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const closeModal = () => modal.remove();
    modal.querySelector('.movie-modal-close').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });

    // Play action
    modal.querySelector('#modal-play-btn').addEventListener('click', () => {
      closeModal();
      launchCinemaPlayer(movie);
    });

    // Edit Metadata action
    modal.querySelector('#modal-edit-btn').addEventListener('click', () => {
      openEditMetadataModal(movie, async (updated) => {
        closeModal();
        await loadMovies();
      });
    });

    // Delete action
    modal.querySelector('#modal-delete-btn').addEventListener('click', async () => {
      if (confirm(`Remove "${movie.title}" from your cinema library?`)) {
        try {
          await api.deleteMovie(movie.id);
          closeModal();
          await loadMovies();
        } catch (err) {
          alert(`Failed to delete: ${err.message}`);
        }
      }
    });
  }

  // Edit Metadata Modal
  function openEditMetadataModal(movie, onSave) {
    const modal = document.createElement('div');
    modal.className = 'movie-detail-modal';
    modal.innerHTML = `
      <div class="movie-detail-content" style="max-width: 600px; padding: 2rem;">
        <button class="movie-modal-close" title="Close">&times;</button>
        <h2 style="margin-bottom: 1.5rem; color: #fff;">Edit Movie Metadata</h2>
        <form id="edit-metadata-form" style="display: flex; flex-direction: column; gap: 1rem;">
          <div class="form-group">
            <label class="form-label" style="color: #cbd5e1; font-size: 0.85rem;">Title</label>
            <input type="text" name="title" class="form-input" value="${movie.title || ''}" required />
          </div>
          <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
              <label class="form-label" style="color: #cbd5e1; font-size: 0.85rem;">Release Year</label>
              <input type="number" name="release_year" class="form-input" value="${movie.release_year || ''}" />
            </div>
            <div class="form-group" style="flex: 1;">
              <label class="form-label" style="color: #cbd5e1; font-size: 0.85rem;">Rating</label>
              <input type="text" name="rating" class="form-input" value="${movie.rating || ''}" placeholder="e.g. 8.5/10" />
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" style="color: #cbd5e1; font-size: 0.85rem;">Genres</label>
            <input type="text" name="genres" class="form-input" value="${movie.genres || ''}" placeholder="Comma-separated" />
          </div>
          <div class="form-group">
            <label class="form-label" style="color: #cbd5e1; font-size: 0.85rem;">Director</label>
            <input type="text" name="director" class="form-input" value="${movie.director || ''}" />
          </div>
          <div class="form-group">
            <label class="form-label" style="color: #cbd5e1; font-size: 0.85rem;">Cast</label>
            <input type="text" name="cast_members" class="form-input" value="${movie.cast_members || ''}" />
          </div>
          <div class="form-group">
            <label class="form-label" style="color: #cbd5e1; font-size: 0.85rem;">Synopsis / Description</label>
            <textarea name="description" class="form-input" rows="4">${movie.description || ''}</textarea>
          </div>
          <div class="form-group">
            <label class="form-label" style="color: #cbd5e1; font-size: 0.85rem;">Poster Artwork URL</label>
            <input type="url" name="poster_url" class="form-input" value="${movie.poster_url || ''}" />
          </div>
          <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1rem;">
            <button type="button" id="edit-cancel-btn" class="btn btn-secondary">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    `;

    document.body.appendChild(modal);

    const closeModal = () => modal.remove();
    modal.querySelector('.movie-modal-close').addEventListener('click', closeModal);
    modal.querySelector('#edit-cancel-btn').addEventListener('click', closeModal);

    const form = modal.querySelector('#edit-metadata-form');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(form);
      const payload = {
        title: formData.get('title'),
        release_year: formData.get('release_year') ? parseInt(formData.get('release_year'), 10) : null,
        rating: formData.get('rating'),
        genres: formData.get('genres'),
        director: formData.get('director'),
        cast_members: formData.get('cast_members'),
        description: formData.get('description'),
        poster_url: formData.get('poster_url'),
      };

      try {
        const res = await api.updateMovieMetadata(movie.id, payload);
        closeModal();
        if (onSave) onSave(res.data);
      } catch (err) {
        alert(`Failed to update metadata: ${err.message}`);
      }
    });
  }

  // Cinema Player Overlay (Spec Section 19 HTTP Range Streaming)
  async function launchCinemaPlayer(movie) {
    try {
      // 1. Generate short-lived streaming token
      const tokenRes = await api.createMovieStreamToken(movie.id);
      const streamToken = tokenRes.data?.token;
      if (!streamToken) {
        throw new Error('Could not obtain short-lived stream token.');
      }

      const streamUrl = `/api/v1/media/stream/${encodeURIComponent(streamToken)}`;

      // 2. Build overlay
      const overlay = document.createElement('div');
      overlay.className = 'cinema-player-overlay';
      overlay.innerHTML = `
        <div class="cinema-player-header">
          <div class="cinema-player-title">${movie.title} (${movie.release_year || 'Cinema'})</div>
          <button class="movie-modal-close" id="player-close-btn" title="Exit Cinema">&times;</button>
        </div>
        <video id="cinema-video" class="cinema-video-element" controls autoplay preload="metadata">
          <source src="${streamUrl}" type="video/mp4" />
          <source src="${streamUrl}" type="video/x-matroska" />
          Your browser does not support HTML5 video streaming.
        </video>
      `;

      document.body.appendChild(overlay);

      const video = overlay.querySelector('#cinema-video');
      const closePlayer = () => {
        video.pause();
        video.removeAttribute('src');
        overlay.remove();
        document.removeEventListener('keydown', handleKeyControls);
      };

      overlay.querySelector('#player-close-btn').addEventListener('click', closePlayer);

      // Keyboard shortcuts
      const handleKeyControls = (e) => {
        if (e.key === 'Escape') {
          closePlayer();
        } else if (e.key === ' ' && e.target === document.body) {
          e.preventDefault();
          if (video.paused) video.play(); else video.pause();
        } else if (e.key === 'ArrowRight') {
          video.currentTime = Math.min(video.duration, video.currentTime + 10);
        } else if (e.key === 'ArrowLeft') {
          video.currentTime = Math.max(0, video.currentTime - 10);
        } else if (e.key === 'f' || e.key === 'F') {
          if (!document.fullscreenElement) {
            overlay.requestFullscreen().catch(() => {});
          } else {
            document.exitFullscreen().catch(() => {});
          }
        }
      };

      document.addEventListener('keydown', handleKeyControls);
    } catch (err) {
      alert(`Could not start playback: ${err.message || 'Stream token error'}`);
    }
  }

  // Initial load
  await loadMovies();
}
