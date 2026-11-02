@extends('layouts.vertical', ['title' => 'Agent Call (AI)', 'subTitle' => 'Xplogent'])

@section('content')
<div class="row">
    <div class="col-12">
        @unless ($configured)
            <div class="alert alert-warning">
                Xplogent is not configured yet: set <code>XPLOGENT_BASE_URL</code> and <code>XPLOGENT_API_TOKEN</code>
                in the CRM's <code>.env</code> (the same token as on the Xplogent server).
            </div>
        @endunless
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-2">1. Vacancy</h5>
                <div class="d-flex gap-2 mb-2">
                    <input id="xpl-search" class="form-control" style="max-width:360px" placeholder="Search open vacancies (title, postcode, client)">
                    <button id="xpl-search-btn" class="btn btn-outline-primary">Search</button>
                </div>
                <select id="xpl-sale" class="form-select" style="max-width:640px"><option value="">— pick a vacancy —</option></select>
                <div id="xpl-sale-info" class="text-muted small mt-2"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-2">2. Candidate</h5>
                <p class="text-muted small mb-2">Candidates within the radius who may be called (blocked, not interested,
                    no job, CV already sent, recently called or being called by someone are left out).
                    <span id="xpl-skipped"></span></p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Name</th><th>Number</th><th>Area</th><th>Distance</th><th>Last note</th><th></th></tr></thead>
                        <tbody id="xpl-candidates"><tr><td colspan="6" class="text-muted">Pick a vacancy first.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-2">3. AI call attempts <span id="xpl-attempts-for" class="text-muted small"></span></h5>
                <div id="xpl-attempts" class="text-muted">Click a candidate's "Attempts" to see their AI calls.</div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('script')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const get = (url) => fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'}).then((r) => r.json());
    let currentSale = null;

    async function loadSales() {
        const q = document.getElementById('xpl-search').value;
        const data = await get(@json(route('xplogent.agent-call.sales')) + '?search=' + encodeURIComponent(q));
        const sel = document.getElementById('xpl-sale');
        sel.innerHTML = '<option value="">— pick a vacancy —</option>' + (data.sales || []).map((s) =>
            `<option value="${s.sale_id}">#${s.sale_id} ${esc(s.job_title)} · ${esc(s.postcode_district)} · ${esc(s.office_name)} (${s.cvs_sent}/${s.cv_limit} CVs)</option>`).join('');
    }

    async function loadQueue(saleId) {
        currentSale = saleId;
        const body = document.getElementById('xpl-candidates');
        body.innerHTML = '<tr><td colspan="6" class="text-muted">Loading…</td></tr>';
        const data = await get(@json(route('xplogent.agent-call.queue')) + '?sale_id=' + saleId);
        if (!data.ok) { body.innerHTML = `<tr><td colspan="6" class="text-danger">${esc(data.error)}</td></tr>`; return; }
        const s = data.sale;
        document.getElementById('xpl-sale-info').textContent = `${s.job_title} · ${s.salary || ''} · ${s.timing || ''}`;
        const sk = Object.entries(data.skipped || {}).filter(([, n]) => n > 0).map(([k, n]) => `${k.replace('_', ' ')} ${n}`).join(', ');
        document.getElementById('xpl-skipped').textContent = sk ? `Left out: ${sk}.` : '';
        body.innerHTML = (data.candidates || []).map((c) => `<tr>
            <td>${esc(c.name)}</td><td>${esc(c.phone_display)} <span class="badge bg-light text-dark">${esc(c.phone_kind)}</span></td>
            <td>${esc(c.postcode_district)}</td><td>${c.distance_km} km</td>
            <td class="small text-muted" style="max-width:320px">${esc(c.last_note)}</td>
            <td class="text-nowrap"><button class="btn btn-sm btn-primary" data-call="${c.applicant_id}">Call with AI</button>
                <button class="btn btn-sm btn-outline-secondary" data-attempts="${c.applicant_id}" data-name="${esc(c.name)}">Attempts</button></td>
        </tr>`).join('') || '<tr><td colspan="6" class="text-muted">No one to call for this vacancy right now.</td></tr>';
    }

    async function dial(applicantId, btn) {
        btn.disabled = true; btn.textContent = 'Calling…';
        const r = await fetch(@json(route('xplogent.agent-call.dial')), {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({applicant_id: applicantId, sale_id: currentSale})});
        const data = await r.json().catch(() => ({ok: false, error: 'no answer'}));
        btn.textContent = data.ok ? 'Call started' : 'Call with AI';
        btn.disabled = !!data.ok;
        if (!data.ok) alert('Not dialled: ' + (data.error || 'unknown error'));
    }

    async function attempts(applicantId, name) {
        document.getElementById('xpl-attempts-for').textContent = '· ' + name;
        const box = document.getElementById('xpl-attempts');
        const data = await get(@json(route('xplogent.agent-call.attempts')) + '?applicant_id=' + applicantId);
        box.innerHTML = (data.attempts || []).map((a) => `<div class="border rounded p-2 mb-2">
            <div><strong>${esc(a.outcome || 'call')}</strong> · ${esc(new Date(a.at).toLocaleString())} · ${a.duration_s}s · sale #${esc(a.sale_id)}</div>
            <div class="small">${esc(a.summary)}</div>
            ${a.transcript ? `<details class="small mt-1"><summary>Transcript</summary>${a.transcript.map((t) =>
                `<div><b>${esc(t.speaker)}:</b> ${esc(t.text)}</div>`).join('')}</details>` : ''}
        </div>`).join('') || 'No AI calls yet.';
    }

    document.getElementById('xpl-search-btn').addEventListener('click', loadSales);
    document.getElementById('xpl-search').addEventListener('keydown', (e) => { if (e.key === 'Enter') loadSales(); });
    document.getElementById('xpl-sale').addEventListener('change', (e) => e.target.value && loadQueue(e.target.value));
    document.getElementById('xpl-candidates').addEventListener('click', (e) => {
        const call = e.target.closest('[data-call]');
        if (call) return dial(Number(call.dataset.call), call);
        const att = e.target.closest('[data-attempts]');
        if (att) return attempts(Number(att.dataset.attempts), att.dataset.name);
    });
    loadSales();
})();
</script>
@endsection
