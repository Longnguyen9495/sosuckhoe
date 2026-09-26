/**
 * Hash router — #/today, #/calendar, #/onboarding/3, …
 * Route "*" chỉ dùng làm dự phòng, không được đưa vào vòng tạo RegExp
 * (trước đây tạo ra /^*$/ làm ứng dụng trắng màn hình — REVIEW-2 R1).
 */
const escapeRegExp = (text) => text.replace(/[.+?^${}()|[\]\\*]/g, '\\$&');

export function compile(pattern) {
    const keys = [];
    const source = pattern
        .split('/')
        .map((segment) => {
            if (segment.startsWith(':')) {
                keys.push(segment.slice(1));
                return '([^/]+)';
            }
            return escapeRegExp(segment);
        })
        .join('/');
    return { regex: new RegExp(`^${source}$`), keys };
}

export function createRouter(routes = {}, { fallback = '*' } = {}) {
    const table = Object.entries(routes)
        .filter(([pattern]) => pattern !== fallback)
        .map(([pattern, handler]) => ({ pattern, handler, ...compile(pattern) }));
    const listeners = new Set();

    function match(hash) {
        const path = (hash || '').replace(/^#/, '') || '/';
        for (const route of table) {
            const m = path.match(route.regex);
            if (m) {
                const params = {};
                route.keys.forEach((key, i) => { params[key] = decodeURIComponent(m[i + 1]); });
                return { handler: route.handler, params, path, pattern: route.pattern };
            }
        }
        return { handler: routes[fallback] || (() => {}), params: {}, path, pattern: fallback };
    }

    function resolve() {
        const found = match(window.location.hash);
        found.handler(found.params, found.path);
        listeners.forEach((cb) => cb(found.path, found.pattern));
    }

    function navigate(path) {
        if (window.location.hash === `#${path}`) resolve();
        else window.location.hash = path;
    }

    window.addEventListener('hashchange', resolve);

    return { match, navigate, resolve, onChange: (cb) => { listeners.add(cb); return () => listeners.delete(cb); } };
}
