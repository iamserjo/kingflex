function csrfToken() {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
}

function setError(msg) {
    const el = document.getElementById('error');
    if (!el) return;
    if (!msg) {
        el.classList.remove('visible');
        el.textContent = '';
        return;
    }
    el.classList.add('visible');
    el.textContent = msg;
}

function prettyJson(obj) {
    try {
        return JSON.stringify(obj, null, 2);
    } catch {
        return String(obj);
    }
}

function opOptionsForType(fieldType) {
    if (fieldType === 'number') return ['eq', 'gte', 'lte'];
    if (fieldType === 'boolean') return ['eq'];
    return ['contains', 'starts_with', 'eq'];
}

function parseLimit(v) {
    const n = parseInt(String(v || ''), 10);
    if (!Number.isFinite(n)) return 20;
    return Math.max(1, Math.min(50, n));
}

function buildFilterRow(field, suggested) {
    const wrap = document.createElement('div');
    wrap.className = 'filter-item';

    const label = document.createElement('div');
    label.className = 'filter-label';
    label.textContent = field.label;

    const op = document.createElement('select');
    op.className = 'select mono';
    op.dataset.path = field.path;
    op.dataset.kind = 'op';

    const ops = opOptionsForType(field.type);
    for (const o of ops) {
        const opt = document.createElement('option');
        opt.value = o;
        opt.textContent = o;
        op.appendChild(opt);
    }

    const value = document.createElement('input');
    value.className = 'input mono';
    value.dataset.path = field.path;
    value.dataset.kind = 'value';
    value.placeholder = field.type;

    if (suggested) {
        if (suggested.op && ops.includes(suggested.op)) op.value = suggested.op;
        if (suggested.value !== undefined && suggested.value !== null) value.value = String(suggested.value);
    }

    wrap.appendChild(label);
    wrap.appendChild(op);
    wrap.appendChild(value);

    return wrap;
}

async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        cache: 'no-store',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'Accept': 'application/json',
        },
        body: JSON.stringify(body),
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const msg = data?.error || data?.message || `Request failed (${res.status})`;
        throw new Error(msg);
    }
    return data;
}

async function getJson(url) {
    const res = await fetch(url, {
        cache: 'no-store',
        headers: { 'Accept': 'application/json' },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.error || data?.message || `Request failed (${res.status})`);
    return data;
}

function collectFilters(fields) {
    const filters = [];
    for (const field of fields) {
        const opEl = document.querySelector(`[data-kind="op"][data-path="${CSS.escape(field.path)}"]`);
        const valEl = document.querySelector(`[data-kind="value"][data-path="${CSS.escape(field.path)}"]`);
        if (!opEl || !valEl) continue;

        const value = String(valEl.value || '').trim();
        if (!value) continue;

        filters.push({
            path: field.path,
            op: String(opEl.value),
            value,
        });
    }
    return filters;
}

function renderResults(results) {
    const root = document.getElementById('results');
    if (!root) return;
    root.innerHTML = '';

    if (!results || results.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'result-card';
        empty.textContent = 'No results';
        root.appendChild(empty);
        return;
    }

    for (const r of results) {
        const card = document.createElement('div');
        card.className = 'result-card';

        const top = document.createElement('div');
        top.className = 'result-top';

        const title = document.createElement('h3');
        title.className = 'result-title';
        const a = document.createElement('a');
        a.href = r.url;
        a.target = '_blank';
        a.rel = 'noreferrer';
        a.textContent = r.title || r.url;
        title.appendChild(a);

        const score = document.createElement('div');
        score.className = 'score';
        score.textContent = `score=${Number(r.score || 0).toFixed(4)}`;

        top.appendChild(title);
        top.appendChild(score);

        const toggle = document.createElement('div');
        toggle.className = 'payload-toggle';
        toggle.textContent = 'Show payload';

        const payload = document.createElement('div');
        payload.className = 'payload';
        payload.textContent = prettyJson(r.payload ?? {});

        toggle.addEventListener('click', () => {
            const visible = payload.classList.toggle('visible');
            toggle.textContent = visible ? 'Hide payload' : 'Show payload';
        });

        card.appendChild(top);
        card.appendChild(toggle);
        card.appendChild(payload);

        root.appendChild(card);
    }
}

let currentType = null;
let currentFields = [];
let currentPlan = null;
let lastPlannedQuery = '';

function resetPlannedState(reason) {
    currentType = null;
    currentFields = [];
    currentPlan = null;
    lastPlannedQuery = '';

    const typeLabel = document.getElementById('type-label');
    if (typeLabel) typeLabel.textContent = '—';

    const queryTextEl = document.getElementById('query-text');
    if (queryTextEl) queryTextEl.value = '';

    const limitEl = document.getElementById('limit');
    if (limitEl) limitEl.value = '20';

    const debug = document.getElementById('debug');
    if (debug) debug.value = reason ? `Reset: ${reason}` : '';

    renderResults([]);

    const btnSearch = document.getElementById('btn-search');
    if (btnSearch) btnSearch.disabled = true;
}

async function loadStats() {
    try {
        const data = await getJson('/qdrant/stats');
        const el = document.getElementById('qdrant-stats');
        if (el) {
            el.textContent = `Stored in Qdrant: ${data.stored_count} / ${data.total_products_count}`;
        }
    } catch {
        // ignore
    }
}

async function runPlan() {
    setError('');
    const query = document.getElementById('query')?.value || '';
    const q = String(query).trim();
    if (q.length < 2) {
        setError('Введите запрос (мин. 2 символа)');
        return;
    }

    const btnPlan = document.getElementById('btn-plan');
    const btnSearch = document.getElementById('btn-search');
    if (btnPlan) btnPlan.disabled = true;
    if (btnSearch) btnSearch.disabled = true;

    try {
        const debug = document.getElementById('debug');
        if (debug) debug.value = `Planning for: ${q}\n`;

        const data = await postJson('/qdrant/plan', { query: q });
        currentType = data.type_structure;
        currentFields = []; // json_structure is ignored
        currentPlan = data.qdrant_plan || null;
        lastPlannedQuery = String(data.source_query || q).trim();

        const typeLabel = document.getElementById('type-label');
        if (typeLabel) {
            typeLabel.textContent = currentType ? `${currentType.id} / ${currentType.type}` : '—';
        }

        const queryTextEl = document.getElementById('query-text');
        if (queryTextEl) queryTextEl.value = currentPlan?.query_text || lastPlannedQuery;

        const limitEl = document.getElementById('limit');
        if (limitEl) limitEl.value = String(currentPlan?.limit || 20);

        if (debug) debug.value = prettyJson({ source_query: lastPlannedQuery, plan: currentPlan, type: currentType });

        if (btnSearch) btnSearch.disabled = false;
    } catch (e) {
        setError(e?.message || 'Failed to plan');
    } finally {
        if (btnPlan) btnPlan.disabled = false;
    }
}

async function runSearch() {
    setError('');
    if (!currentType) {
        setError('Сначала нажмите Plan (нужно определить тип товара)');
        return;
    }

    const currentQuery = String(document.getElementById('query')?.value || '').trim();
    if (currentQuery && lastPlannedQuery && currentQuery !== lastPlannedQuery) {
        setError('Вы изменили запрос. Нажмите Plan, чтобы построить новый план для текущего запроса.');
        const debug = document.getElementById('debug');
        if (debug) debug.value = prettyJson({ error: 'query_changed_requires_plan', lastPlannedQuery, currentQuery });
        return;
    }

    const btnSearch = document.getElementById('btn-search');
    if (btnSearch) btnSearch.disabled = true;

    try {
        const queryText = String(document.getElementById('query-text')?.value || '').trim();
        const limit = parseLimit(document.getElementById('limit')?.value);
        const filters = [];

        const data = await postJson('/qdrant/search', {
            type_structure_id: currentType.id,
            query_text: queryText,
            limit,
            filters,
        });

        const debug = document.getElementById('debug');
        if (debug) debug.value = prettyJson(data);

        renderResults(data.results || []);
    } catch (e) {
        setError(e?.message || 'Failed to search');
    } finally {
        if (btnSearch) btnSearch.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadStats();

    document.getElementById('btn-plan')?.addEventListener('click', runPlan);
    document.getElementById('btn-search')?.addEventListener('click', runSearch);

    document.getElementById('query')?.addEventListener('input', () => {
        // Invalidate plan when the query changes to avoid showing stale debug/results.
        resetPlannedState('query_changed');
    });

    document.getElementById('query')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            runPlan();
        }
    });
});


