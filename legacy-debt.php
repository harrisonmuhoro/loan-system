<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/legacy_authorization.php';

$db = (new Database())->getConnection();
try {
    $legacyContext = legacyRequirePermission($db, 'legacy.view');
} catch (Throwable $e) {
    http_response_code($e->getMessage() === 'Authentication required' ? 401 : 403);
    exit('<h1>Access denied</h1><p>You are not authorized to view legacy debt.</p>');
}

$csrf = htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8');
$role = htmlspecialchars($legacyContext['role'], ENT_QUOTES, 'UTF-8');
$userId = (int)$legacyContext['user_id'];
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Legacy Debt Management</title>
    <link rel="stylesheet" href="css/admin.css">
    <style>
        :root { --legacy-border:#dfe5df; --legacy-muted:#66736b; --legacy-green:#2e7d32; }
        body { background:var(--background-color,#f5f7f5); color:var(--text-primary,#27332c); }
        .legacy-shell { max-width:1500px; margin:0 auto; padding:24px; }
        .legacy-header { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .legacy-header h1 { margin:0; color:var(--primary-color,#2e7d32); }
        .legacy-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .legacy-btn { border:0; border-radius:6px; padding:10px 14px; cursor:pointer; background:var(--primary-color,#2e7d32); color:#fff; }
        .legacy-btn.secondary { background:#64756a; } .legacy-btn.warning { background:#b7791f; }
        .legacy-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:20px; }
        .legacy-card { background:var(--card-background,#fff); border:1px solid var(--legacy-border); border-radius:8px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
        .legacy-card .value { font-size:1.35rem; font-weight:700; margin-top:8px; }
        .legacy-card .label { color:var(--legacy-muted); font-size:.9rem; }
        .legacy-layout { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr); gap:18px; align-items:start; }
        .legacy-table-wrap { overflow:auto; } table { width:100%; border-collapse:collapse; } th,td { padding:10px 8px; border-bottom:1px solid var(--legacy-border); text-align:left; white-space:nowrap; } th { color:var(--legacy-muted); font-size:.85rem; }
        .legacy-table button { padding:6px 9px; border:1px solid var(--legacy-border); background:transparent; border-radius:5px; cursor:pointer; }
        .legacy-badge { display:inline-block; border-radius:12px; padding:3px 8px; background:#e8f3e8; color:#27632a; font-size:.78rem; }
        .legacy-badge.warn { background:#fff4d6; color:#805b00; } .legacy-badge.danger { background:#fde5e5; color:#8b2626; }
        .legacy-panel h2,.legacy-panel h3 { margin-top:0; } .legacy-panel { min-width:0; }
        .legacy-form { display:grid; gap:10px; } .legacy-form label { display:grid; gap:5px; font-size:.9rem; color:var(--legacy-muted); } .legacy-form input,.legacy-form select,.legacy-form textarea { padding:9px; border:1px solid var(--legacy-border); border-radius:5px; background:inherit; color:inherit; } .legacy-form textarea { min-height:70px; }
        .legacy-section { margin-top:18px; } .legacy-empty { color:var(--legacy-muted); padding:20px 0; }
        .legacy-detail { display:grid; gap:8px; font-size:.92rem; } .legacy-detail strong { color:var(--legacy-muted); }
        .legacy-notice { padding:10px 12px; border-radius:6px; background:#eef6ee; color:#275d2b; margin-bottom:12px; } .legacy-error { background:#fdecec; color:#8b2626; }
        @media (max-width:900px) { .legacy-layout { grid-template-columns:1fr; } .legacy-shell { padding:14px; } }
    </style>
</head>
<body>
<main class="legacy-shell" data-role="<?= $role ?>">
    <div class="legacy-header">
        <div><h1>Legacy Debt Management</h1><p>Immutable ledger, controlled imports, approvals and migration.</p></div>
        <div class="legacy-actions"><button class="legacy-btn secondary" id="backButton">Back to portal</button><button class="legacy-btn" id="refreshButton">Refresh</button></div>
    </div>
    <div id="notice" aria-live="polite"></div>
    <section class="legacy-grid" id="summaryCards"></section>
    <div class="legacy-layout">
        <section class="legacy-panel legacy-card">
            <h2>Legacy accounts</h2>
            <div class="legacy-table-wrap"><table><thead><tr><th>Debtor</th><th>PF</th><th>Account</th><th>Balance</th><th>Status</th><th>Migration</th><th></th></tr></thead><tbody id="accountsBody"></tbody></table></div>
        </section>
        <aside class="legacy-panel">
            <section class="legacy-card" id="detailPanel"><h2>Account details</h2><div class="legacy-empty">Select an account to view its ledger and history.</div></section>
            <section class="legacy-card legacy-section"><h2>Record payment</h2><form class="legacy-form" id="paymentForm"><label>Legacy account<select id="paymentAccount" required></select></label><label>Financial year<select id="paymentYear" required></select></label><label>Amount<input id="paymentAmount" type="number" min="0.01" step="0.01" required></label><label>Payment date<input id="paymentDate" type="date" required></label><label>Method<select id="paymentMethod"><option>CASH</option><option>BANK_TRANSFER</option><option>SALARY_DEDUCTION</option><option>OTHER</option></select></label><label>Reference<input id="paymentReference" required maxlength="150"></label><label>Reason<textarea id="paymentReason" required></textarea></label><button class="legacy-btn" type="submit">Post payment</button><small id="paymentRouteHint">Select an account to see routing.</small></form></section>
        </aside>
    </div>
    <section class="legacy-card legacy-section"><h2>Import legacy records</h2><form class="legacy-form" id="importForm"><label>Financial year<select id="importYear" required></select></label><label>Source file<input id="importFile" type="file" accept=".csv,.xls,.xlsx" required></label><button class="legacy-btn warning" type="submit">Upload and validate</button></form><div id="importResult"></div></section>
    <section class="legacy-card legacy-section"><h2>Pending approvals</h2><div class="legacy-table-wrap"><table><thead><tr><th>Action</th><th>Account</th><th>Requester</th><th>Amount</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody id="actionsBody"></tbody></table></div></section>
    <section class="legacy-card legacy-section"><h2>Import batches and reconciliation</h2><div id="reconciliationStatus" class="legacy-notice">Loading…</div><div class="legacy-table-wrap"><table><thead><tr><th>Batch</th><th>Source</th><th>Status</th><th>Detected</th><th>Valid</th><th>Imported</th><th>Duplicates</th><th>Uploaded</th></tr></thead><tbody id="batchesBody"></tbody></table></div></section>
</main>
<script>
const LEGACY_CSRF = <?= json_encode($csrf) ?>;
const LEGACY_ROLE = <?= json_encode($legacyContext['role']) ?>;
const LEGACY_USER_ID = <?= $userId ?>;
const money = v => 'KES ' + Number(v || 0).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const notice = (message, error=false) => { const el=document.getElementById('notice'); el.className=error?'legacy-notice legacy-error':'legacy-notice'; el.textContent=message; };
async function jsonFetch(url, options={}) { const response=await fetch(url,{credentials:'same-origin',...options}); const data=await response.json(); if(!response.ok||!data.success) throw new Error(data.message||'Request failed'); return data; }
async function loadDashboard(){
  try { const result=await jsonFetch('api/legacy_dashboard.php'); const d=result.data; renderSummary(d.summary); renderAccounts(d.accounts); renderActions(d.actions); renderBatches(d.batches,d.reconciliation); fillYears(d.financial_years); notice('Dashboard refreshed.'); }
  catch(e){ notice(e.message,true); }
}
function renderSummary(s){ const cards=[['Total accounts',s.total_accounts],['Outstanding balance',money(s.outstanding_balance)],['Payments',`${s.payment_count} (${money(s.payment_amount)})`],['Disputed',s.disputed_accounts],['Overdue',s.overdue_accounts],['Migrated',s.migrated_accounts],['Pending actions',s.pending_actions]]; document.getElementById('summaryCards').innerHTML=cards.map(c=>`<article class="legacy-card"><div class="label">${esc(c[0])}</div><div class="value">${esc(c[1])}</div></article>`).join(''); }
function renderAccounts(rows){ const body=document.getElementById('accountsBody'), select=document.getElementById('paymentAccount'); if(!rows.length){body.innerHTML='<tr><td colspan="7" class="legacy-empty">No legacy accounts found.</td></tr>';select.innerHTML='<option value="">No accounts</option>';return;} body.innerHTML=rows.map(a=>`<tr><td>${esc(a.full_name)}</td><td>${esc(a.pf_number||'—')}</td><td>${esc(a.account_reference)}</td><td>${money(a.balance)}</td><td><span class="legacy-badge ${a.status==='DISPUTED'||a.status==='OVERDUE'?'danger':''}">${esc(a.status)}</span></td><td><span class="legacy-badge ${a.migration_status==='MIGRATED'?'':'warn'}">${esc(a.migration_status)}</span></td><td><button onclick="showAccount(${Number(a.legacy_account_id)},${Number(a.balance)},${JSON.stringify(a.migration_status)})">View</button></td></tr>`).join(''); select.innerHTML=rows.map(a=>`<option value="${Number(a.legacy_account_id)}" data-balance="${Number(a.balance)}" data-migration="${esc(a.migration_status)}">${esc(a.full_name)} — ${esc(a.account_reference)}</option>`).join(''); updateRouteHint(); }
async function showAccount(id,balance,migration){ try{const r=await jsonFetch(`api/legacy_ledger.php?legacy_account_id=${id}`);const a=r.data.account; document.getElementById('detailPanel').innerHTML=`<h2>Account details</h2><div class="legacy-detail"><div><strong>Debtor:</strong> ${esc(a.full_name)}</div><div><strong>PF:</strong> ${esc(a.pf_number||'—')}</div><div><strong>National ID:</strong> ${esc(a.national_id||'—')}</div><div><strong>Account:</strong> ${esc(a.account_reference)}</div><div><strong>Balance:</strong> ${money(a.current_balance)}</div><div><strong>Status:</strong> ${esc(a.status)} / ${esc(a.dispute_status)}</div><div><strong>Migration:</strong> ${esc(a.migration_status)} ${a.migration_date?`from ${esc(a.migration_date)}`:''}</div></div><h3 class="legacy-section">Ledger and payments</h3><div class="legacy-table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Debit</th><th>Credit</th><th>Status</th></tr></thead><tbody>${r.data.transactions.map(t=>`<tr><td>${esc(t.transaction_date)}</td><td>${esc(t.transaction_type)}</td><td>${esc(t.transaction_reference)}</td><td>${money(t.debit_amount)}</td><td>${money(t.credit_amount)}</td><td>${esc(t.status)}</td></tr>`).join('')||'<tr><td colspan="6">No ledger entries.</td></tr>'}</tbody></table></div><div class="legacy-actions"><button class="legacy-btn secondary" onclick="loadAudit(${id})">View audit history</button></div>`; document.getElementById('paymentAccount').value=id; updateRouteHint(); }catch(e){notice(e.message,true);} }
async function loadAudit(id){ try{const r=await jsonFetch(`api/legacy_audit.php?legacy_account_id=${id}`);document.getElementById('detailPanel').insertAdjacentHTML('beforeend',`<h3 class="legacy-section">Audit history</h3><div class="legacy-table-wrap"><table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Reason</th></tr></thead><tbody>${r.data.events.map(e=>`<tr><td>${esc(e.created_at)}</td><td>${esc(e.actor_name||'System')}</td><td>${esc(e.action)}</td><td>${esc(e.reason_text||'')}</td></tr>`).join('')||'<tr><td colspan="4">No audit events.</td></tr>'}</tbody></table></div>`);}catch(e){notice(e.message,true);} }
function fillYears(years){ const opts=years.map(y=>`<option value="${Number(y.financial_year_id)}">${esc(y.label)} (${esc(y.status)})</option>`).join(''); document.getElementById('paymentYear').innerHTML=opts;document.getElementById('importYear').innerHTML=opts; }
function updateRouteHint(){const s=document.getElementById('paymentAccount');const o=s.options[s.selectedIndex];if(!o){return;} document.getElementById('paymentRouteHint').textContent=o.dataset.migration==='MIGRATED'?'Routing is determined by migration date; on/after that date posts to NORMAL LOAN.':'Payment will post to LEGACY ACCOUNT until migration.';}
function renderActions(rows){const body=document.getElementById('actionsBody');body.innerHTML=rows.length?rows.map(a=>{const self=Number(a.requested_by)===LEGACY_USER_ID;return `<tr><td>${esc(a.action_type)}</td><td>${esc(a.full_name)}<br><small>${esc(a.account_reference)}</small></td><td>${esc(a.requested_by)}</td><td>${money(a.requested_amount)}</td><td>${esc(a.reason_text)}</td><td>${esc(a.status)}</td><td><button ${self?'disabled title="Requester cannot approve own request"':''} onclick="approveAction(${Number(a.action_request_id)},${JSON.stringify(a.action_type)})">${self?'Cannot self-approve':'Approve'}</button> <button onclick="executeAction(${Number(a.action_request_id)},${JSON.stringify(a.action_type)})">Process</button></td></tr>`}).join(''):'<tr><td colspan="7" class="legacy-empty">No pending actions.</td></tr>';}
function renderBatches(rows,rec){document.getElementById('reconciliationStatus').textContent=rec?`Latest reconciliation: ${rec.status}; ${rec.mismatch_count} mismatches; completed ${rec.completed_at||'—'}`:'No reconciliation run recorded.';document.getElementById('batchesBody').innerHTML=rows.length?rows.map(b=>`<tr><td>${esc(b.batch_reference)}</td><td>${esc(b.source_filename||'—')}</td><td>${esc(b.status)}</td><td>${b.detected_count}</td><td>${b.valid_count}</td><td>${b.imported_count}</td><td>${b.duplicate_count}</td><td>${esc(b.uploaded_at)}</td></tr>`).join(''):'<tr><td colspan="8" class="legacy-empty">No import batches.</td></tr>';}
async function approveAction(id,type){const reason=prompt('Approval reason:');if(!reason)return;try{const endpoint=type==='MIGRATION'?'api/legacy_migration.php?action=APPROVE':'api/legacy_ledger.php?action=APPROVE_ACTION';const body={action_request_id:id,reason,csrf_token:LEGACY_CSRF};const r=await jsonFetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});notice(r.message);loadDashboard();}catch(e){notice(e.message,true);}}
async function executeAction(id,type){const reason=prompt('Processing reason:');if(!reason)return;try{const endpoint=type==='MIGRATION'?'api/legacy_migration.php?action=EXECUTE':'api/legacy_ledger.php?action=EXECUTE_ACTION';const r=await jsonFetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action_request_id:id,reason,csrf_token:LEGACY_CSRF})});notice(r.message);loadDashboard();}catch(e){notice(e.message,true);}}
document.getElementById('paymentAccount').addEventListener('change',updateRouteHint);
document.getElementById('paymentForm').addEventListener('submit',async e=>{e.preventDefault();if(!confirm('Confirm this payment posting?'))return;const body={legacy_account_id:Number(document.getElementById('paymentAccount').value),financial_year_id:Number(document.getElementById('paymentYear').value),amount:Number(document.getElementById('paymentAmount').value),payment_date:document.getElementById('paymentDate').value,payment_method:document.getElementById('paymentMethod').value,transaction_reference:document.getElementById('paymentReference').value,reason:document.getElementById('paymentReason').value,csrf_token:LEGACY_CSRF};try{const r=await jsonFetch('api/legacy_ledger.php?action=RECORD_PAYMENT',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});notice(`${r.message} (${r.data.route||'LEGACY_ACCOUNT'})`);e.target.reset();loadDashboard();}catch(err){notice(err.message,true);}});
document.getElementById('importForm').addEventListener('submit',async e=>{e.preventDefault();const file=document.getElementById('importFile').files[0];if(!file)return;try{const fd=new FormData();fd.append('financial_year_id',document.getElementById('importYear').value);fd.append('csrf_token',LEGACY_CSRF);fd.append('source_file',file);const uploaded=await jsonFetch('api/legacy_import.php?action=UPLOAD',{method:'POST',body:fd});const id=uploaded.data.import_batch_id;const validated=await jsonFetch('api/legacy_import.php?action=VALIDATE',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({import_batch_id:id,csrf_token:LEGACY_CSRF})});if(!confirm(`Validated ${validated.data.valid_count} records. Confirm import?`))return;await jsonFetch('api/legacy_import.php?action=CONFIRM',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({import_batch_id:id,csrf_token:LEGACY_CSRF})});const posted=await jsonFetch('api/legacy_import.php?action=POST',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({import_batch_id:id,csrf_token:LEGACY_CSRF})});document.getElementById('importResult').innerHTML=`<div class="legacy-notice">${esc(posted.message)}: ${posted.data.imported_count} records posted.</div>`;loadDashboard();}catch(err){document.getElementById('importResult').innerHTML=`<div class="legacy-notice legacy-error">${esc(err.message)}</div>`;}});
document.getElementById('refreshButton').addEventListener('click',loadDashboard);document.getElementById('backButton').addEventListener('click',()=>history.back());loadDashboard();
</script>
</body></html>
