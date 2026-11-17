export function money(n) {
    if (n === null || n === undefined) return '';
    if (n >= 1000) return '$' + Math.round(n / 1000) + 'K';
    return '$' + n;
}

export function salary(s) {
    if (!s) return null;
    const per = s.interval === 'hour' ? '/hr' : s.interval === 'month' ? '/mo' : '';
    return s.min && s.min !== s.max ? `${money(s.min)}–${money(s.max)}${per}` : `${money(s.max)}${per}`;
}

export function age(job) {
    const d = job.age_days;
    const label = d <= 0 ? 'Today' : d === 1 ? '1 day ago' : `${d} days ago`;
    return job.age_basis === 'posted' ? label : `${label} (first seen)`;
}

export function date(iso, opts = {}) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric', ...opts });
}

export function dateTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export function relative(iso) {
    if (!iso) return 'never';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    const future = diff < 0;
    const s = Math.abs(diff);
    const v = s < 60 ? 'just now' : s < 3600 ? `${Math.round(s / 60)}m` : s < 86400 ? `${Math.round(s / 3600)}h` : `${Math.round(s / 86400)}d`;
    if (v === 'just now') return v;
    return future ? `in ${v}` : `${v} ago`;
}

export const remoteLabels = { remote: 'Remote', hybrid: 'Hybrid', onsite: 'On-site', unknown: 'Not stated' };
export const remoteIcons = { remote: 'bi-house', hybrid: 'bi-buildings', onsite: 'bi-building', unknown: 'bi-question-circle' };

export function scoreClass(score, high = 80) {
    if (score === null || score === undefined) return 'jh-score-none';
    if (score >= high) return 'jh-score-high';
    if (score >= 60) return 'jh-score-mid';
    return 'jh-score-low';
}

export const healthDot = { healthy: 'jh-dot-green', degraded: 'jh-dot-amber', failing: 'jh-dot-red', blocked: 'jh-dot-red', unsupported: 'jh-dot-gray', unknown: 'jh-dot-gray' };
