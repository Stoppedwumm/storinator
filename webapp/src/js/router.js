/**
 * Hash and Path-based router with parameter support
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
    let path = window.location.pathname;
    const rawHash = window.location.hash.slice(1);

    if (rawHash) {
      path = rawHash;
    }

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

    // Direct match
    if (this.routes[path]) {
      this.currentRoute = path;
      this.routes[path]();
      window.scrollTo(0, 0);
      return;
    }

    // Parameterized routes (e.g. /s/:token)
    for (const [routePattern, handler] of Object.entries(this.routes)) {
      if (routePattern.includes(':')) {
        const regexStr = '^' + routePattern.replace(/:([a-zA-Z0-9_]+)/g, '(?<$1>[^/]+)') + '$';
        const match = path.match(new RegExp(regexStr));
        if (match) {
          this.currentRoute = path;
          handler(match.groups || {});
          window.scrollTo(0, 0);
          return;
        }
      }
    }

    // Fallback to '/'
    const fallbackHandler = this.routes['/'];
    if (fallbackHandler) {
      this.currentRoute = '/';
      fallbackHandler();
      window.scrollTo(0, 0);
    }
  }

  init() {
    this.resolve();
  }
}
