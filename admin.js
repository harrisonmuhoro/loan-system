/* ===========================
   🌐 Centralized Storage Keys
   =========================== */
const STORAGE = {
  STAFF: 'staffAccounts',
  CASHIERS: 'cashiers',
  ACCOUNTANTS: 'accountantAccounts',
  MANAGERS: 'managers',
  LOANS: 'loanApplications',
  FUNDS: 'fundingAccounts',
  AMORT: 'amortizationRate',
  ADMIN_FLAG: 'adminLoggedIn'
};

/* ===========================
   ⚙️ Utilities & Persistence
   =========================== */
const ADMIN_PF = '10101010101';
const ADMIN_PW = 'admin123';

function fmtKES(n) {
  n = Number(n) || 0;
  return 'KES ' + new Intl.NumberFormat('en-KE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(n);
}

function readJSON(key, fallback = []) {
  return JSON.parse(localStorage.getItem(key) || JSON.stringify(fallback));
}
function writeJSON(key, val) {
  localStorage.setItem(key, JSON.stringify(val));
}

/* ---------------------------
   Loan & Funding Utilities
--------------------------- */
function getLoans() { return readJSON(STORAGE.LOANS); }
function saveLoans(list) { writeJSON(STORAGE.LOANS, list); }

function getFundingAccounts() { return readJSON(STORAGE.FUNDS); }
function saveFundingAccounts(list) { writeJSON(STORAGE.FUNDS, list); }

/* ---------------------------
   Staff Utilities
--------------------------- */
function getStaff() { return readJSON(STORAGE.STAFF); }

function saveStaff(list) {
  writeJSON(STORAGE.STAFF, list);

  const cashiers = list.filter(s => s.role === 'cashier');
  const accountants = list.filter(s => s.role === 'accountant');

  writeJSON(STORAGE.CASHIERS, cashiers);
  writeJSON(STORAGE.MANAGERS, cashiers); // Managers share cashier pool

  const accountantAccounts = accountants.map(a => ({
    pfNumber: a.pfNumber,
    fullName: a.name,
    email: a.email || `${a.name.toLowerCase().replace(/\s+/g, '')}@example.com`,
    password: a.password,
    role: 'accountant',
    createdAt: a.createdAt
  }));
  writeJSON(STORAGE.ACCOUNTANTS, accountantAccounts);

  console.log('✅ Staff synced:', {
    cashiers: cashiers.length,
    accountants: accountants.length,
    accountantAccounts: accountantAccounts.length
  });
}

/* ===========================
   🔐 Authentication & DOM Refs
   =========================== */
const authScreen = document.getElementById('authScreen');
const mainHeader = document.getElementById('mainHeader');
const sidebar = document.getElementById('sidebar');
const main = document.getElementById('main');
const staffForm = document.getElementById('staffForm');
const staffMsg = document.getElementById('staffMsg');
const cancelEditBtn = document.getElementById('cancelEditBtn');
let editingPf = null;

/* ===========================
   🧭 Mount Portal
   =========================== */
function mountPortal() {
  authScreen.style.display = 'none';
  mainHeader.classList.remove('hidden');
  sidebar.classList.remove('hidden');
  main.classList.remove('hidden');
  showSection('dashboard');
  renderFundingAccounts();
  renderLoans();
  renderStaff();
  updateStats();
  loadAmortRate();
}

/* ✅ Auto-login if admin already logged in */
document.addEventListener('DOMContentLoaded', () => {
  if (localStorage.getItem(STORAGE.ADMIN_FLAG)) mountPortal();
});

/* ===========================
   🔑 Login / Logout
   =========================== */
document.getElementById('loginBtn').addEventListener('click', () => {
  const pf = document.getElementById('loginPf').value.trim();
  const pw = document.getElementById('loginPwd').value.trim();
  if (pf === ADMIN_PF && pw === ADMIN_PW) {
    localStorage.setItem(STORAGE.ADMIN_FLAG, '1');
    mountPortal();
  } else alert('Invalid admin credentials');
});

document.getElementById('logoutBtn').addEventListener('click', () => {
  localStorage.removeItem(STORAGE.ADMIN_FLAG);
  location.reload();
});

/* ===========================
   📋 Sidebar Navigation
   =========================== */
document.querySelectorAll('#sidebar li').forEach(li => {
  li.addEventListener('click', () => {
    document.querySelectorAll('#sidebar li').forEach(x => x.classList.remove('active'));
    li.classList.add('active');
    const id = li.id.replace('nav', '').toLowerCase();
    document.querySelectorAll('main section').forEach(s => s.classList.add('hidden'));
    document.getElementById(id + 'Section').classList.remove('hidden');

    if (id === 'dashboard') updateStats();
    if (id === 'accounts') renderFundingAccounts();
    if (id === 'staff') renderStaff();
    if (id === 'loans') renderLoans();
  });
});

function showSection(name) {
  document.querySelectorAll('main section').forEach(s => s.classList.add('hidden'));
  document.getElementById(name + 'Section').classList.remove('hidden');
}

/* Sidebar toggle */
document.getElementById('menuToggle').addEventListener('click', () => {
  sidebar.classList.toggle('collapsed');
});

/* ===========================
   💰 Funding Accounts
   =========================== */
document.getElementById('addFundBtn').addEventListener('click', () => {
  const name = document.getElementById('newFundName').value.trim();
  const bal = Number(document.getElementById('newFundBalance').value) || 0;
  if (!name) return alert('Enter account name');
  const list = getFundingAccounts();
  list.push({ name, balance: bal });
  saveFundingAccounts(list);
  document.getElementById('newFundName').value = '';
  document.getElementById('newFundBalance').value = '';
  renderFundingAccounts();
  updateStats();
});

function renderFundingAccounts() {
  const cont = document.getElementById('fundingAccountsContainer');
  const list = getFundingAccounts();
  cont.innerHTML = '';
  if (list.length === 0) {
    cont.innerHTML = '<p class="small">No funding accounts yet.</p>';
    return;
  }
  list.forEach((a, i) => {
    const div = document.createElement('div');
    div.className = 'account-card';
    div.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <strong>${a.name}</strong>
          <div class="small">Balance: ${fmtKES(a.balance)}</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
          <div style="display:flex;gap:6px">
            <input id="admin_topup_${i}" type="number" placeholder="Top-up (KES)" style="width:140px;padding:6px"/>
            <button data-i="${i}" class="adminTopupBtn">Top Up</button>
          </div>
          <button data-i="${i}" class="removeFund danger" style="width:100%">Remove</button>
        </div>
      </div>`;
    cont.appendChild(div);
  });

  document.querySelectorAll('.adminTopupBtn').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = Number(btn.dataset.i);
      const val = Number(document.getElementById(`admin_topup_${i}`).value) || 0;
      if (val <= 0) return alert('Enter a valid top-up amount');
      const list = getFundingAccounts();
      list[i].balance += val;
      saveFundingAccounts(list);
      renderFundingAccounts();
      updateStats();
      flashMessage('✅ Top-up successful');
    });
  });

  document.querySelectorAll('.removeFund').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = Number(btn.dataset.i);
      if (!confirm('Remove funding account?')) return;
      const list = getFundingAccounts();
      list.splice(i, 1);
      saveFundingAccounts(list);
      renderFundingAccounts();
      updateStats();
    });
  });
}

/* ===========================
   🧭 Flash Message Utility
   =========================== */
function flashMessage(txt, ms = 1500) {
  const el = document.createElement('div');
  el.style.position = 'fixed';
  el.style.right = '12px';
  el.style.bottom = '12px';
  el.style.background = '#e6ffed';
  el.style.border = '1px solid #3a6b9a';
  el.style.padding = '10px';
  el.style.borderRadius = '8px';
  el.textContent = txt;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), ms);
}

/* ===========================
   👥 Staff Management
   =========================== */
function renderStaff() {
  const list = getStaff();
  const tbody = document.querySelector('#staffTable tbody');
  tbody.innerHTML = '';
  if (list.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="small">No staff members yet.</td></tr>';
    return;
  }

  list.forEach((s, i) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${i + 1}</td>
      <td>${s.name}</td>
      <td>${s.pfNumber}</td>
      <td>${s.role}</td>
      <td>${s.password}</td>
      <td>${new Date(s.createdAt).toLocaleDateString()}</td>
      <td>
        <button data-i="${i}" class="editStaff">✏️ Edit</button>
        <button data-i="${i}" class="removeStaff danger">🗑 Remove</button>
      </td>
    `;
    tbody.appendChild(tr);
  });

  document.querySelectorAll('.editStaff').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = Number(btn.dataset.i);
      const s = getStaff()[i];
      document.getElementById('staffName').value = s.name;
      document.getElementById('staffPf').value = s.pfNumber;
      document.getElementById('staffPwd').value = s.password;
      document.getElementById('staffRole').value = s.role;
      editingPf = s.pfNumber;
      staffMsg.textContent = 'Editing: ' + s.name;
      cancelEditBtn.classList.remove('hidden');
    });
  });

  document.querySelectorAll('.removeStaff').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = Number(btn.dataset.i);
      if (!confirm('Remove this staff member?')) return;
      const list = getStaff();
      list.splice(i, 1);
      saveStaff(list);
      renderStaff();
      flashMessage('🗑️ Staff removed');
    });
  });
}

staffForm.addEventListener('submit', e => {
  e.preventDefault();

  const data = {
    name: document.getElementById('staffName').value.trim(),
    pfNumber: document.getElementById('staffPf').value.trim(),
    password: document.getElementById('staffPwd').value.trim(),
    role: document.getElementById('staffRole').value,
    createdAt: new Date().toISOString()
  };

  if (!data.name || !data.pfNumber || !data.password) {
    alert('Fill all fields');
    return;
  }

  const list = getStaff();

  if (editingPf) {
    const idx = list.findIndex(s => s.pfNumber === editingPf);
    if (idx >= 0) list[idx] = data;
    editingPf = null;
    staffMsg.textContent = '';
    cancelEditBtn.classList.add('hidden');
  } else {
    if (list.some(s => s.pfNumber === data.pfNumber)) {
      alert('PF number already exists');
      return;
    }
    list.push(data);
  }

  saveStaff(list);
  renderStaff();
  staffForm.reset();
  flashMessage('✅ Staff saved successfully');
});

cancelEditBtn.addEventListener('click', () => {
  editingPf = null;
  staffForm.reset();
  staffMsg.textContent = '';
  cancelEditBtn.classList.add('hidden');
});

/* ===========================
   📊 Loans & Statistics
   =========================== */
function renderLoans() {
  const list = getLoans();
  const tbody = document.querySelector('#loansTable tbody');
  tbody.innerHTML = '';

  if (list.length === 0) {
    tbody.innerHTML = '<tr><td colspan="9" class="small">No loan applications found.</td></tr>';
    return;
  }

  list.forEach((l, i) => {
    const loaneeName = l.clientName || l.name || '—';
    const loaneeId = l.clientId || l.idNumber || '—';
    const date = l.date ? new Date(l.date).toLocaleDateString() : '—';
    const amount = fmtKES(l.amount || l.loanAmount || 0);

    const tr = document.createElement('tr');
    tr.className = l.status || '';
    tr.innerHTML = `
      <td>${l.applicationDate}</td>
      <td>${l.fullName}</td>
      <td>${l.idNumber}</td>
      <td>${l.pfNumber || '—'}</td>
      <td>${l.loanType || '—'}</td>
      <td>${amount}</td>
      <td>${l.status || 'Pending'}</td>
      <td>${l.feedback || '—'}</td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('lastUpdated').textContent =
    'Last updated: ' + new Date().toLocaleTimeString();
}

function updateStats() {
  const loans = getLoans();
  const funds = getFundingAccounts();

  document.getElementById('totalLoans').textContent = loans.length;
  document.getElementById('pendingLoans').textContent = loans.filter(l => l.status === 'pending').length;
  document.getElementById('forwardedLoans').textContent = loans.filter(l => l.status === 'forwarded').length;
  document.getElementById('acceptedLoans').textContent = loans.filter(l => l.status === 'accepted').length;
  document.getElementById('dispatchedLoans').textContent = loans.filter(l => l.status === 'dispatched').length;
  document.getElementById('returnedLoans').textContent = loans.filter(l => l.status === 'returned').length;
  document.getElementById('rejectedLoans').textContent = loans.filter(l => l.status === 'rejected').length;

  const totalFunds = funds.reduce((s, f) => s + (Number(f.balance) || 0), 0);
  const fundStats = document.getElementById('fundStats');
  fundStats.innerHTML = `<div class="stat-card"><div class="stat-number">${fmtKES(totalFunds)}</div><div class="small">Total Fund Balance</div></div>`;
}

/* ===========================
   ⚙️ Amortization Settings
   =========================== */
function loadAmortRate() {
  const v = Number(localStorage.getItem(STORAGE.AMORT) || 10);
  document.getElementById('amortRate').value = v;
  document.getElementById('amortStatus').textContent = `Saved: ${v.toFixed(1)}%`;
}

document.getElementById('saveAmort').addEventListener('click', () => {
  const v = Number(document.getElementById('amortRate').value);
  if (v < 0 || v > 100) return alert('Enter a valid interest rate');
  localStorage.setItem(STORAGE.AMORT, v);
  document.getElementById('amortStatus').textContent = `Saved: ${v.toFixed(1)}%`;
  flashMessage('💾 Amortization rate saved (applies dynamically to loans)');
});

/* ===========================
   🔄 Auto-Sync Across Tabs
   =========================== */
window.addEventListener('storage', (e) => {
  const syncKeys = Object.values(STORAGE);
  if (!e.key || syncKeys.includes(e.key)) {
    setTimeout(() => {
      renderFundingAccounts();
      renderLoans();
      renderStaff();
      updateStats();
      flashMessage('🔁 Synced across tabs', 1000);
    }, 100);
  }
});

/* ===========================
   👀 Auto-Refresh on Focus
   =========================== */
window.addEventListener('focus', () => {
  if (localStorage.getItem(STORAGE.ADMIN_FLAG)) {
    renderLoans();
    renderFundingAccounts();
    renderStaff();
    updateStats();
  }
});

/* ===========================
   🧮 Loan Filters
   =========================== */
document.getElementById('filterBtn').addEventListener('click', () => {
  const status = document.getElementById('filterStatus').value.toLowerCase();
  const type = document.getElementById('filterLoanType').value.toLowerCase();
  const loans = getLoans();
  const filtered = loans.filter(l => {
    const sMatch = !status || (l.status || '').toLowerCase() === status;
    const tMatch = !type || (l.loanType || '').toLowerCase() === type;
    return sMatch && tMatch;
  });

  const tbody = document.querySelector('#loansTable tbody');
  tbody.innerHTML = '';

  if (!filtered.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="small">No matching loans found.</td></tr>';
    return;
  }

  filtered.forEach(l => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${l.applicationDate || '-'}</td>
      <td>${l.fullName || '-'}</td>
      <td>${l.idNumber || '-'}</td>
      <td>${l.pfNumber || '-'}</td>
      <td>${l.loanType || '-'}</td>
      <td>${fmtKES(l.loanAmount || 0)}</td>
      <td>${l.status || '-'}</td>
      <td>${l.feedback || '-'}</td>
    `;
    tbody.appendChild(tr);
  });

  document.getElementById('lastUpdated').textContent =
    'Filtered at: ' + new Date().toLocaleTimeString();
});

document.getElementById('clearFilters').addEventListener('click', () => {
  document.getElementById('filterStatus').value = '';
  document.getElementById('filterLoanType').value = '';
  renderLoans();
});

document.getElementById('refreshBtn').addEventListener('click', () => {
  renderLoans();
  updateStats();
  flashMessage('🔄 Loans refreshed');
});

/* ===========================
   🧹 Danger Zone Actions
   =========================== */
document.getElementById('clearStaffBtn').addEventListener('click', () => {
  if (!confirm('Are you sure you want to clear ALL staff records?')) return;
  localStorage.removeItem(STORAGE.STAFF);
  localStorage.removeItem(STORAGE.CASHIERS);
  localStorage.removeItem(STORAGE.ACCOUNTANTS);
  renderStaff();
  flashMessage('🧹 All staff cleared');
});

document.getElementById('clearSystemBtn').addEventListener('click', () => {
  if (!confirm('💣 This will erase ALL system, staff, and loanee data, and log out everyone. Continue?')) return;

  // Comprehensive list of all keys used across portals
  const allKeys = [
    // Core system
    'staffAccounts', 'cashiers', 'accountantAccounts', 'managers',
    'loanApplications', 'fundingAccounts', 'amortizationRate',

    // Auth flags (for all portals)
    'adminLoggedIn', 'accountant_loggedInPf', 'cashier_loggedInPf', 'loanee_loggedInId',

    // Loanee data
    'loaneeAccount', 'loaneeAccounts', 'loaneeData',
    'loaneeNotifications', 'loaneeLoans',

    // Decision & notifications cross-portal
    'AccDecisions', 'cashierNotifications', 'accountantNotifications',

    // Sync flags
    'systemReset'
  ];

  // 🧹 Remove all listed keys
  allKeys.forEach(k => localStorage.removeItem(k));

  // 🧨 Optional: fully nuke everything else (good for dev or reset scenarios)
  // localStorage.clear();
  // sessionStorage.clear();

  // 🔁 Broadcast to all portals (cashier, accountant, loanee, etc.)
  localStorage.setItem('systemReset', Date.now());

  // 🧍 Force logout on all portals
  ['adminLoggedIn', 'accountant_loggedInPf', 'cashier_loggedInPf', 'loanee_loggedInId'].forEach(k => localStorage.removeItem(k));

  flashMessage('💣 All data cleared and all users logged out. Reloading...');
  setTimeout(() => location.reload(), 1500);
});



/* ===========================
   👨‍💼 Accountant Account Creation
   =========================== */
document.getElementById('createAccountantForm').addEventListener('submit', e => {
  e.preventDefault();
  const pf = document.getElementById('acctPf').value.trim();
  const email = document.getElementById('acctEmail').value.trim();
  const pw = document.getElementById('acctPw').value.trim();

  if (!pf || !email || !pw) return alert('Fill all fields');

  const list = getStaff();
  if (list.some(s => s.pfNumber === pf)) return alert('Accountant with this PF already exists');

  list.push({
    name: email.split('@')[0],
    pfNumber: pf,
    email,
    password: pw,
    role: 'accountant',
    createdAt: new Date().toISOString()
  });

  saveStaff(list);
  renderStaff();
  e.target.reset();
  flashMessage('✅ Accountant created successfully');
});

// 🔄 Detect full system reset (from Admin clear button)
window.addEventListener('storage', e => {
  if (e.key === 'systemReset') {
    alert('⚠️ The system was reset by the Administrator. You will be logged out now.');
    localStorage.clear();
    sessionStorage.clear();
    location.reload();
  }
});


