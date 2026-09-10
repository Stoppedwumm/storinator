/**
 * Hash-based router with History API compatibility
 */
export class Router {
  constructor(routes = {}) {
    this.routes = routes;
    this.currentRoute = null;
    window.addEventListener('hashchange', () => this.resolve());
  }

  addRoute(path, handler) {
    this.routes[path] = handler;
  }

  navigate(path) {
    window.location.hash = `#${path}`;
  }

  resolve() {
    const rawHash = window.location.hash.slice(1) || '/';

    // Support section anchor links on landing page (#infrastructure, etc.)
    if (rawHash && !rawHash.startsWith('/')) {
      const el = document.getElementById(rawHash);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth' });
        return;
      }
      if (this.currentRoute !== '/') {
        this.currentRoute = '/';
        if (this.routes['/']) this.routes['/']();
        setTimeout(() => {
          const target = document.getElementById(rawHash);
          if (target) target.scrollIntoView({ behavior: 'smooth' });
        }, 50);
        return;
      }
    }

    const handler = this.routes[rawHash] || this.routes['/'];
    if (handler) {
      this.currentRoute = rawHash;
      handler();
      window.scrollTo(0, 0);
    }
  }

  init() {
    this.resolve();
  }
}
