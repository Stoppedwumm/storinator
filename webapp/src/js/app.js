import '../css/variables.css';
import '../css/base.css';
import '../css/components.css';
import '../css/landing.css';
import '../css/dashboard.css';
import '../css/files.css';
import '../css/movies.css';
import '../css/stores.css';

import { Router } from './router.js';
import { renderHeader } from './components/header.js';
import { renderFooter } from './components/footer.js';
import { renderLanding } from './pages/landing.js';
import { renderHealth } from './pages/health.js';
import { renderLogin } from './pages/login.js';

function initApp() {
  const app = document.getElementById('app');
  if (!app) return;

  app.innerHTML = `
    ${renderHeader()}
    <main id="main-view" style="flex: 1;"></main>
    ${renderFooter()}
  `;

  const mainView = document.getElementById('main-view');

  const router = new Router({
    '/': () => renderLanding(mainView),
    '/health': () => renderHealth(mainView),
    '/login': () => renderLogin(mainView),
  });

  router.init();
}

document.addEventListener('DOMContentLoaded', initApp);
