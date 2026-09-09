/**
 * Lightweight observable state store
 */
class StateStore {
  constructor() {
    this.state = {
      user: null,
      isAuthenticated: false,
      entryUnlocked: false,
      systemHealth: null,
    };
    this.listeners = new Set();
  }

  get() {
    return this.state;
  }

  set(partial) {
    this.state = { ...this.state, ...partial };
    this.notify();
  }

  subscribe(listener) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  notify() {
    for (const listener of this.listeners) {
      listener(this.state);
    }
  }
}

export const store = new StateStore();
