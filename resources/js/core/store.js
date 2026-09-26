/**
 * Reactive store — state đơn giản với subscribe/unsubscribe.
 */
export function createStore(initialState = {}) {
    let state = { ...initialState };
    const listeners = new Map();

    function get(key) {
        return key === undefined ? { ...state } : state[key];
    }

    function set(key, value) {
        const prev = state[key];
        state[key] = value;
        if (prev !== value) {
            const cbs = listeners.get(key);
            if (cbs) cbs.forEach((cb) => cb(value, prev));
        }
    }

    function subscribe(key, callback) {
        if (!listeners.has(key)) listeners.set(key, new Set());
        listeners.get(key).add(callback);
        return () => listeners.get(key)?.delete(callback);
    }

    function batch(updates) {
        Object.entries(updates).forEach(([k, v]) => set(k, v));
    }

    return { get, set, subscribe, batch };
}
