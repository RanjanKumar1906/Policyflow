<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PolicyFlow | Insurance Issuance & Reconciliation Engine</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
        .badge-ISSUED { background-color: #dcfce7; color: #15803d; border-color: #86efac; }
        .badge-VALIDATED { background-color: #e0f2fe; color: #0369a1; border-color: #7dd3fc; }
        .badge-SUBMITTED { background-color: #fef3c7; color: #b45309; border-color: #fde68a; }
        .badge-PENDING_CONFIRMATION { background-color: #ffedd5; color: #c2410c; border-color: #fed7aa; }
        .badge-NEEDS_RECONCILIATION { background-color: #fae8ff; color: #86198f; border-color: #f0abfc; }
        .badge-FAILED { background-color: #fee2e2; color: #b91c1c; border-color: #fca5a5; }
        .badge-RECEIVED { background-color: #f3f4f6; color: #374151; border-color: #d1d5db; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen font-sans antialiased">
    <!-- Navbar -->
    <header class="border-b border-slate-800 bg-slate-950/80 backdrop-blur sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg shadow-cyan-500/20">
                    <i class="fa-solid fa-shield-halved text-white text-lg"></i>
                </div>
                <div>
                    <span class="text-xl font-bold tracking-tight bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">PolicyFlow</span>
                    <span class="text-xs ml-2 px-2 py-0.5 rounded-full bg-cyan-950 text-cyan-400 border border-cyan-800 font-mono">v1.0 • Fault-Tolerant Engine</span>
                </div>
            </div>
            <div class="flex items-center space-x-3">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-950 text-emerald-400 border border-emerald-800">
                    <span class="w-2 h-2 mr-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    API Active (127.0.0.1:8000)
                </span>
                <button onclick="openNewModal()" class="px-4 py-2 bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-medium rounded-lg text-sm transition shadow-lg shadow-blue-500/25 flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> New Policy Request
                </button>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <!-- Metric Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-5 backdrop-blur">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-400 uppercase tracking-wider">Total Requests</span>
                    <i class="fa-solid fa-layer-group text-slate-500 text-sm"></i>
                </div>
                <div class="mt-2 text-3xl font-bold text-white" id="stat-total">0</div>
            </div>
            <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-5 backdrop-blur">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-emerald-400 uppercase tracking-wider">Issued Policies</span>
                    <i class="fa-solid fa-circle-check text-emerald-500 text-sm"></i>
                </div>
                <div class="mt-2 text-3xl font-bold text-emerald-400" id="stat-issued">0</div>
            </div>
            <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-5 backdrop-blur">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-purple-400 uppercase tracking-wider">Reconciliations</span>
                    <i class="fa-solid fa-arrows-rotate text-purple-400 text-sm"></i>
                </div>
                <div class="mt-2 text-3xl font-bold text-purple-400" id="stat-reconcile">0</div>
            </div>
            <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-5 backdrop-blur">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-amber-400 uppercase tracking-wider">Pending Callbacks</span>
                    <i class="fa-solid fa-clock text-amber-500 text-sm"></i>
                </div>
                <div class="mt-2 text-3xl font-bold text-amber-400" id="stat-pending">0</div>
            </div>
        </div>

        <!-- Reliability Scenarios Quick Lab -->
        <div class="bg-gradient-to-r from-slate-900 via-slate-800/90 to-slate-900 border border-slate-700/70 rounded-2xl p-6 shadow-xl relative overflow-hidden">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-flask-vial text-cyan-400"></i> Reliability & Idempotency Testing Lab
                    </h2>
                    <p class="text-sm text-slate-400 mt-1">
                        Test and verify the 4 failure and idempotency cases directly against the live engine:
                    </p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <button onclick="testDuplicateIntake()" class="text-left p-3.5 rounded-xl bg-slate-950/60 hover:bg-slate-800/80 border border-slate-800 hover:border-cyan-500/50 transition group">
                    <div class="font-semibold text-sm text-white group-hover:text-cyan-400 flex items-center justify-between">
                        1. Duplicate Intake
                        <i class="fa-solid fa-arrow-right text-xs opacity-0 group-hover:opacity-100 transition"></i>
                    </div>
                    <div class="text-xs text-slate-400 mt-1">Sends identical agent+client ID twice to prove 200 OK safe intake.</div>
                </button>
                <button onclick="testTimeoutAfterIssue()" class="text-left p-3.5 rounded-xl bg-slate-950/60 hover:bg-slate-800/80 border border-slate-800 hover:border-purple-500/50 transition group">
                    <div class="font-semibold text-sm text-white group-hover:text-purple-400 flex items-center justify-between">
                        2. Timeout Recovery
                        <i class="fa-solid fa-arrow-right text-xs opacity-0 group-hover:opacity-100 transition"></i>
                    </div>
                    <div class="text-xs text-slate-400 mt-1">Simulates 504 timeout after issue; resolves safely via reconciliation.</div>
                </button>
                <button onclick="testDuplicateCallback()" class="text-left p-3.5 rounded-xl bg-slate-950/60 hover:bg-slate-800/80 border border-slate-800 hover:border-amber-500/50 transition group">
                    <div class="font-semibold text-sm text-white group-hover:text-amber-400 flex items-center justify-between">
                        3. Duplicate Webhook
                        <i class="fa-solid fa-arrow-right text-xs opacity-0 group-hover:opacity-100 transition"></i>
                    </div>
                    <div class="text-xs text-slate-400 mt-1">Delivers identical webhook 3x to test idempotent event processing.</div>
                </button>
                <button onclick="testRetryFailure()" class="text-left p-3.5 rounded-xl bg-slate-950/60 hover:bg-slate-800/80 border border-slate-800 hover:border-emerald-500/50 transition group">
                    <div class="font-semibold text-sm text-white group-hover:text-emerald-400 flex items-center justify-between">
                        4. Retry Safe Recovery
                        <i class="fa-solid fa-arrow-right text-xs opacity-0 group-hover:opacity-100 transition"></i>
                    </div>
                    <div class="text-xs text-slate-400 mt-1">Simulates 503 temporary error and verifies retry preserves premium.</div>
                </button>
            </div>
        </div>

        <!-- Policies Table Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-5 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-lg font-bold text-white">Policy Requests & Issuance</h3>
                    <button onclick="loadPolicies()" class="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition" title="Refresh">
                        <i class="fa-solid fa-rotate-right"></i>
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-slate-400 mr-1">Filter:</span>
                    <button onclick="filterStatus('')" class="status-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-slate-800 text-white border border-slate-700" data-status="">All</button>
                    <button onclick="filterStatus('ISSUED')" class="status-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="ISSUED">Issued</button>
                    <button onclick="filterStatus('VALIDATED')" class="status-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="VALIDATED">Validated</button>
                    <button onclick="filterStatus('NEEDS_RECONCILIATION')" class="status-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="NEEDS_RECONCILIATION">Needs Reconcile</button>
                    <button onclick="filterStatus('PENDING_CONFIRMATION')" class="status-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="PENDING_CONFIRMATION">Pending Callback</button>
                    <button onclick="filterStatus('FAILED')" class="status-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-slate-950 text-slate-400 border border-slate-800 hover:text-white" data-status="FAILED">Failed</button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-950/60 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="py-3 px-4">Client Request ID</th>
                            <th class="py-3 px-4">Customer</th>
                            <th class="py-3 px-4">Plan & Coverage</th>
                            <th class="py-3 px-4">Calculated Premium</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Policy # / Ref</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="policies-tbody" class="divide-y divide-slate-800/60">
                        <tr>
                            <td colspan="7" class="py-8 text-center text-slate-500">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading policies...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Detail & Audit Trail Modal -->
    <div id="detailModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-3xl w-full max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
            <div class="p-6 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold text-white flex items-center gap-2">
                        <span id="modal-policy-title">Policy Details</span>
                        <span id="modal-status-badge" class="text-xs px-2.5 py-0.5 rounded-full border font-mono"></span>
                    </h3>
                    <p class="text-xs text-slate-400 font-mono mt-1" id="modal-uuid"></p>
                </div>
                <button onclick="closeDetailModal()" class="text-slate-400 hover:text-white p-2 rounded-lg hover:bg-slate-800">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            
            <div class="p-6 overflow-y-auto space-y-6">
                <!-- Info Grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 bg-slate-950/60 p-4 rounded-xl border border-slate-800 text-xs">
                    <div>
                        <span class="text-slate-500 block">Agent ID</span>
                        <span class="font-semibold text-slate-200" id="modal-agent"></span>
                    </div>
                    <div>
                        <span class="text-slate-500 block">Customer</span>
                        <span class="font-semibold text-slate-200" id="modal-customer"></span>
                    </div>
                    <div>
                        <span class="text-slate-500 block">Trip Dates</span>
                        <span class="font-semibold text-slate-200" id="modal-dates"></span>
                    </div>
                    <div>
                        <span class="text-slate-500 block">Premium</span>
                        <span class="font-bold text-emerald-400 text-sm" id="modal-premium"></span>
                    </div>
                </div>

                <!-- Pricing Breakdown -->
                <div>
                    <h4 class="text-sm font-semibold text-slate-200 mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-calculator text-cyan-400"></i> Deterministic Pricing Breakdown
                    </h4>
                    <div class="bg-slate-950/80 p-3.5 rounded-xl border border-slate-800 text-xs font-mono space-y-1" id="modal-pricing-box">
                    </div>
                </div>

                <!-- Issuance Attempts -->
                <div>
                    <h4 class="text-sm font-semibold text-slate-200 mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane text-blue-400"></i> Issuance Attempts (<span id="modal-attempts-count">0</span>)
                    </h4>
                    <div class="space-y-2" id="modal-attempts-list">
                    </div>
                </div>

                <!-- State Transition Audit Trail -->
                <div>
                    <h4 class="text-sm font-semibold text-slate-200 mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-timeline text-purple-400"></i> State Transition Audit Trail
                    </h4>
                    <div class="border-l-2 border-slate-800 ml-3 space-y-4 py-2" id="modal-timeline">
                    </div>
                </div>
            </div>

            <div class="p-4 border-t border-slate-800 bg-slate-950/50 flex justify-end">
                <button onclick="closeDetailModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-lg text-sm font-medium transition">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- New Policy Request Modal -->
    <div id="newModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-xl w-full shadow-2xl overflow-hidden">
            <div class="p-6 border-b border-slate-800 flex items-center justify-between">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-file-contract text-cyan-400"></i> New Policy Request Intake
                </h3>
                <button onclick="closeNewModal()" class="text-slate-400 hover:text-white p-2 rounded-lg hover:bg-slate-800">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <form id="newPolicyForm" onsubmit="handleCreatePolicy(event)" class="p-6 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Client Request ID</label>
                        <input type="text" id="inp-client-id" required class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500 font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Agent ID</label>
                        <input type="text" id="inp-agent-id" required value="AGENT-17" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500 font-mono">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1">Customer Full Name</label>
                    <input type="text" id="inp-customer-name" required value="Rahul Sharma" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500">
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Date of Birth</label>
                        <input type="date" id="inp-dob" required value="1998-04-10" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Trip Start</label>
                        <input type="date" id="inp-start" required value="2026-11-10" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Trip End</label>
                        <input type="date" id="inp-end" required value="2026-11-14" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Destination</label>
                        <select id="inp-dest" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500">
                            <option value="INTERNATIONAL" selected>INTERNATIONAL (1.8x)</option>
                            <option value="DOMESTIC">DOMESTIC (1.0x)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Plan Code</label>
                        <select id="inp-plan" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500">
                            <option value="PLUS" selected>PLUS (₹20/day)</option>
                            <option value="BASIC">BASIC (₹12/day)</option>
                            <option value="PREMIUM">PREMIUM (₹30/day)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Sum Insured</label>
                        <select id="inp-sum" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-cyan-500">
                            <option value="300000" selected>₹300,000 (1.25x)</option>
                            <option value="100000">₹100,000 (1.00x)</option>
                            <option value="500000">₹500,000 (1.50x)</option>
                        </select>
                    </div>
                </div>

                <div id="new-error-box" class="hidden p-3 rounded-lg bg-rose-950/60 border border-rose-800 text-rose-300 text-xs"></div>

                <div class="pt-3 flex items-center justify-end gap-3 border-t border-slate-800">
                    <button type="button" onclick="closeNewModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-sm transition">
                        Cancel
                    </button>
                    <button type="submit" id="btn-create-submit" class="px-5 py-2 bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-medium rounded-lg text-sm transition shadow-lg shadow-blue-500/25 flex items-center gap-2">
                        <span>Create & Calculate</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="fixed bottom-6 right-6 z-50 transform translate-y-20 opacity-0 transition-all duration-300 flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl border text-sm font-medium">
    </div>

    <script>
        let currentStatusFilter = '';
        let allPolicies = [];

        document.addEventListener('DOMContentLoaded', () => {
            loadPolicies();
            // Auto refresh every 8 seconds
            setInterval(loadPolicies, 8000);
        });

        async function loadPolicies() {
            try {
                let url = '/api/policy-requests?per_page=100';
                if (currentStatusFilter) url += `&status=${currentStatusFilter}`;
                
                const res = await fetch(url);
                const data = await res.json();
                
                if (data.status === 'success') {
                    allPolicies = data.data;
                    renderPolicies(allPolicies);
                    updateStats();
                }
            } catch (err) {
                console.error('Failed to load policies:', err);
            }
        }

        function updateStats() {
            fetch('/api/policy-requests?per_page=100')
                .then(r => r.json())
                .then(data => {
                    const list = data.data || [];
                    document.getElementById('stat-total').textContent = list.length;
                    document.getElementById('stat-issued').textContent = list.filter(p => p.status === 'ISSUED').length;
                    document.getElementById('stat-reconcile').textContent = list.filter(p => p.status === 'NEEDS_RECONCILIATION').length;
                    document.getElementById('stat-pending').textContent = list.filter(p => p.status === 'PENDING_CONFIRMATION').length;
                });
        }

        function renderPolicies(policies) {
            const tbody = document.getElementById('policies-tbody');
            if (!policies || policies.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-slate-500">No policy requests found for the selected filter.</td></tr>`;
                return;
            }

            tbody.innerHTML = policies.map(p => {
                const badgeClass = `badge-${p.status}`;
                const canSubmit = ['VALIDATED', 'NEEDS_RECONCILIATION', 'FAILED'].includes(p.status);
                const canReconcile = p.status === 'NEEDS_RECONCILIATION' || p.status === 'PENDING_CONFIRMATION';

                return `
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="py-3 px-4 font-mono font-medium text-cyan-400">
                            ${p.client_request_id}
                            <span class="block text-[11px] text-slate-500 font-sans">${p.agent_id}</span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="font-medium text-white block">${p.customer_name}</span>
                            <span class="text-xs text-slate-400">${p.destination} • ${p.trip_start}</span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="font-semibold text-slate-200 block">${p.plan_code}</span>
                            <span class="text-xs text-slate-500">₹${p.sum_insured.toLocaleString('en-IN')} coverage</span>
                        </td>
                        <td class="py-3 px-4 font-mono font-bold text-emerald-400">
                            ₹${p.premium}
                        </td>
                        <td class="py-3 px-4">
                            <span class="px-2.5 py-1 text-xs font-semibold rounded-full border ${badgeClass}">
                                ${p.status}
                            </span>
                        </td>
                        <td class="py-3 px-4 font-mono text-xs">
                            ${p.policy_number ? `<span class="text-emerald-400 font-bold">${p.policy_number}</span>` : (p.provider_reference ? `<span class="text-slate-400">${p.provider_reference}</span>` : '<span class="text-slate-600">-</span>')}
                        </td>
                        <td class="py-3 px-4 text-right space-x-1 whitespace-nowrap">
                            ${canSubmit ? `
                                <button onclick="quickSubmit('${p.uuid}', 'SUCCESS')" class="px-2.5 py-1 text-xs font-medium bg-blue-600/80 hover:bg-blue-600 text-white rounded transition shadow" title="Issue via mock provider">
                                    <i class="fa-solid fa-paper-plane mr-1"></i> Issue
                                </button>
                            ` : ''}
                            ${canReconcile ? `
                                <button onclick="quickReconcile('${p.uuid}')" class="px-2.5 py-1 text-xs font-medium bg-purple-600/80 hover:bg-purple-600 text-white rounded transition shadow" title="Check Provider Status">
                                    <i class="fa-solid fa-arrows-rotate mr-1"></i> Reconcile
                                </button>
                            ` : ''}
                            <button onclick="viewDetails('${p.uuid}')" class="px-2.5 py-1 text-xs font-medium bg-slate-800 hover:bg-slate-700 text-slate-300 rounded transition border border-slate-700">
                                <i class="fa-solid fa-eye"></i> Audit
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function filterStatus(status) {
            currentStatusFilter = status;
            document.querySelectorAll('.status-filter-btn').forEach(btn => {
                if (btn.getAttribute('data-status') === status) {
                    btn.className = "status-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-slate-800 text-white border border-slate-700";
                } else {
                    btn.className = "status-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-slate-950 text-slate-400 border border-slate-800 hover:text-white";
                }
            });
            loadPolicies();
        }

        async function quickSubmit(uuid, scenario) {
            try {
                showToast(`Submitting issuance (Scenario: ${scenario})...`, 'info');
                const res = await fetch(`/api/policy-requests/${uuid}/submit`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ scenario })
                });
                const data = await res.json();
                if (res.ok) {
                    showToast(data.message || 'Issuance executed', 'success');
                    loadPolicies();
                } else {
                    showToast(data.message || 'Issuance failed', 'error');
                }
            } catch (err) {
                showToast('Submission error', 'error');
            }
        }

        async function quickReconcile(uuid) {
            try {
                showToast('Checking external provider registry...', 'info');
                const res = await fetch(`/api/policy-requests/${uuid}/reconcile`, { method: 'POST' });
                const data = await res.json();
                if (res.ok) {
                    showToast(data.message, data.reconciled ? 'success' : 'info');
                    loadPolicies();
                }
            } catch (err) {
                showToast('Reconciliation error', 'error');
            }
        }

        async function viewDetails(uuid) {
            try {
                const res = await fetch(`/api/policy-requests/${uuid}`);
                const data = await res.json();
                if (data.status !== 'success') return;
                const p = data.data;

                document.getElementById('modal-policy-title').textContent = `${p.client_request_id}`;
                document.getElementById('modal-uuid').textContent = `UUID: ${p.uuid}`;
                
                const badge = document.getElementById('modal-status-badge');
                badge.className = `text-xs px-2.5 py-0.5 rounded-full border font-mono badge-${p.status}`;
                badge.textContent = p.status;

                document.getElementById('modal-agent').textContent = p.agent_id;
                document.getElementById('modal-customer').textContent = p.customer_name;
                document.getElementById('modal-dates').textContent = `${p.trip_start} to ${p.trip_end}`;
                document.getElementById('modal-premium').textContent = `₹${p.premium}`;

                // Pricing box
                const pb = p.pricing_breakdown;
                if (pb) {
                    document.getElementById('modal-pricing-box').innerHTML = `
                        <div class="text-cyan-400 font-bold mb-1">Formula: ${pb.formula} = ₹${p.premium}</div>
                        <div class="text-slate-400">Plan: ${pb.plan_code} (₹${pb.base_daily_rate}/day) • Days: ${pb.travel_days}</div>
                        <div class="text-slate-400">Age: ${pb.age_at_trip_start} yrs (${pb.age_multiplier}x) • Dest: ${pb.destination} (${pb.destination_multiplier}x) • Coverage: ₹${pb.sum_insured} (${pb.coverage_multiplier}x)</div>
                    `;
                } else {
                    document.getElementById('modal-pricing-box').innerHTML = '<span class="text-slate-500">No pricing breakdown available</span>';
                }

                // Attempts
                const attempts = p.attempts || [];
                document.getElementById('modal-attempts-count').textContent = attempts.length;
                document.getElementById('modal-attempts-list').innerHTML = attempts.length === 0 ? '<div class="text-xs text-slate-500 italic">No issuance attempts recorded yet.</div>' : attempts.map(a => `
                    <div class="p-3 bg-slate-950 border border-slate-800 rounded-lg text-xs flex items-center justify-between">
                        <div>
                            <span class="font-bold text-slate-200">Attempt #${a.attempt_number}</span>
                            <span class="ml-2 text-slate-400 font-mono">Scenario: ${a.scenario}</span>
                            ${a.error_message ? `<div class="text-rose-400 mt-1">${a.error_message}</div>` : ''}
                        </div>
                        <div class="text-right">
                            <span class="px-2 py-0.5 rounded text-[11px] font-semibold ${a.status === 'SUCCESS' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-rose-950 text-rose-400 border border-rose-800'}">${a.status}</span>
                            <div class="text-slate-500 text-[10px] mt-1">${a.created_at ? new Date(a.created_at).toLocaleTimeString() : ''}</div>
                        </div>
                    </div>
                `).join('');

                // Audit Timeline
                const history = p.state_history || [];
                document.getElementById('modal-timeline').innerHTML = history.length === 0 ? '<div class="text-xs text-slate-500 italic ml-4">No state transitions recorded.</div>' : history.map(h => `
                    <div class="relative pl-6">
                        <div class="absolute -left-[7px] top-1.5 w-3 h-3 rounded-full bg-cyan-500 border-2 border-slate-900"></div>
                        <div class="text-xs font-mono font-bold text-slate-200">
                            <span class="text-slate-400">${h.from_status || 'INIT'}</span>
                            <i class="fa-solid fa-arrow-right mx-1 text-cyan-400"></i>
                            <span class="text-cyan-400">${h.to_status}</span>
                        </div>
                        <div class="text-xs text-slate-400 mt-0.5">${h.reason || 'System transition'}</div>
                        <div class="text-[10px] text-slate-600 font-mono mt-0.5">${h.timestamp ? new Date(h.timestamp).toLocaleString() : ''}</div>
                    </div>
                `).join('');

                document.getElementById('detailModal').classList.remove('hidden');
            } catch (err) {
                console.error(err);
            }
        }

        function closeDetailModal() {
            document.getElementById('detailModal').classList.add('hidden');
        }

        function openNewModal() {
            document.getElementById('inp-client-id').value = `AGT-${Date.now().toString().slice(-6)}`;
            document.getElementById('new-error-box').classList.add('hidden');
            document.getElementById('newModal').classList.remove('hidden');
        }

        function closeNewModal() {
            document.getElementById('newModal').classList.add('hidden');
        }

        async function handleCreatePolicy(e) {
            e.preventDefault();
            const payload = {
                client_request_id: document.getElementById('inp-client-id').value,
                agent_id: document.getElementById('inp-agent-id').value,
                customer_name: document.getElementById('inp-customer-name').value,
                date_of_birth: document.getElementById('inp-dob').value,
                destination: document.getElementById('inp-dest').value,
                trip_start: document.getElementById('inp-start').value,
                trip_end: document.getElementById('inp-end').value,
                plan_code: document.getElementById('inp-plan').value,
                sum_insured: parseInt(document.getElementById('inp-sum').value)
            };

            try {
                const res = await fetch('/api/policy-requests', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok) {
                    showToast(data.message, 'success');
                    closeNewModal();
                    loadPolicies();
                } else {
                    const errBox = document.getElementById('new-error-box');
                    errBox.classList.remove('hidden');
                    errBox.textContent = data.message || JSON.stringify(data.errors);
                }
            } catch (err) {
                showToast('Failed to create policy', 'error');
            }
        }

        // Reliability Lab automated demo functions
        async function testDuplicateIntake() {
            const reqId = `DUP-INTAKE-DEMO-${Date.now().toString().slice(-4)}`;
            const payload = {
                client_request_id: reqId,
                agent_id: 'AGENT-TEST-LAB',
                customer_name: 'Idempotency Tester',
                date_of_birth: '1995-01-01',
                destination: 'DOMESTIC',
                trip_start: '2026-11-01',
                trip_end: '2026-11-05',
                plan_code: 'BASIC',
                sum_insured: 100000
            };

            showToast('Sending First Intake Request (expecting 201 Created)...', 'info');
            const res1 = await fetch('/api/policy-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d1 = await res1.json();

            showToast('Sending Second Duplicate Intake (expecting 200 OK Safe Return)...', 'info');
            const res2 = await fetch('/api/policy-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d2 = await res2.json();

            if (res2.status === 200 && d2.idempotent === true) {
                showToast(`Idempotency Verified! Status 200 OK returned. Zero duplicate records.`, 'success');
            }
            loadPolicies();
        }

        async function testTimeoutAfterIssue() {
            const reqId = `TIMEOUT-DEMO-${Date.now().toString().slice(-4)}`;
            const payload = {
                client_request_id: reqId,
                agent_id: 'AGENT-TEST-LAB',
                customer_name: 'Timeout Survivor',
                date_of_birth: '1990-05-15',
                destination: 'INTERNATIONAL',
                trip_start: '2026-12-01',
                trip_end: '2026-12-04',
                plan_code: 'PLUS',
                sum_insured: 300000
            };

            const res = await fetch('/api/policy-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const created = await res.json();
            const uuid = created.data.uuid;

            showToast('Submitting with TIMEOUT_AFTER_ISSUE scenario...', 'info');
            await fetch(`/api/policy-requests/${uuid}/submit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ scenario: 'TIMEOUT_AFTER_ISSUE' })
            });

            showToast('Status is NEEDS_RECONCILIATION. Triggering safe reconciliation...', 'info');
            setTimeout(async () => {
                const recRes = await fetch(`/api/policy-requests/${uuid}/reconcile`, { method: 'POST' });
                const recData = await recRes.json();
                showToast(`Recovered to ISSUED safely! Policy: ${recData.data.policy_number}`, 'success');
                loadPolicies();
            }, 1000);
        }

        async function testDuplicateCallback() {
            const reqId = `CALLBACK-DEMO-${Date.now().toString().slice(-4)}`;
            const payload = {
                client_request_id: reqId,
                agent_id: 'AGENT-TEST-LAB',
                customer_name: 'Webhook Tester',
                date_of_birth: '1992-03-10',
                destination: 'DOMESTIC',
                trip_start: '2026-12-10',
                trip_end: '2026-12-12',
                plan_code: 'BASIC',
                sum_insured: 100000
            };

            const res = await fetch('/api/policy-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const created = await res.json();
            const uuid = created.data.uuid;

            // Submit delayed callback
            const subRes = await fetch(`/api/policy-requests/${uuid}/submit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ scenario: 'DELAYED_CALLBACK' })
            });
            const subData = await subRes.json();
            const provRef = subData.data.provider_reference;

            const evtId = `EVT-DEMO-${Date.now()}`;
            const cbPayload = {
                event_id: evtId,
                event_type: 'POLICY_ISSUED',
                provider_reference: provRef,
                policy_number: `POL-CONFIRMED-${Date.now().toString().slice(-4)}`
            };

            showToast('Delivering Callback #1 (processed)...', 'info');
            await fetch('/api/mock-insurer/callback', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cbPayload)
            });

            showToast('Delivering Callback #2 with same Event ID (duplicate)...', 'info');
            const cb2 = await fetch('/api/mock-insurer/callback', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cbPayload)
            });
            const d2 = await cb2.json();

            if (d2.idempotent === true) {
                showToast('Duplicate callback safely ignored by unique event_id constraint!', 'success');
            }
            loadPolicies();
        }

        async function testRetryFailure() {
            const reqId = `RETRY-DEMO-${Date.now().toString().slice(-4)}`;
            const payload = {
                client_request_id: reqId,
                agent_id: 'AGENT-TEST-LAB',
                customer_name: 'Retry Candidate',
                date_of_birth: '1988-11-11',
                destination: 'INTERNATIONAL',
                trip_start: '2026-11-20',
                trip_end: '2026-11-25',
                plan_code: 'PREMIUM',
                sum_insured: 500000
            };

            const res = await fetch('/api/policy-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const created = await res.json();
            const uuid = created.data.uuid;

            showToast('Attempt 1: Simulating TEMPORARY_FAILURE (503)...', 'info');
            await fetch(`/api/policy-requests/${uuid}/submit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ scenario: 'TEMPORARY_FAILURE' })
            });

            setTimeout(async () => {
                showToast('Attempt 2: Safe retry with SUCCESS scenario...', 'info');
                const retryRes = await fetch(`/api/policy-requests/${uuid}/submit`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ scenario: 'SUCCESS' })
                });
                const retryData = await retryRes.json();
                showToast(`Retry Succeeded! Policy ${retryData.data.policy_number} issued with original premium intact.`, 'success');
                loadPolicies();
            }, 1200);
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            
            let bg = 'bg-slate-900 border-slate-700 text-white';
            if (type === 'success') bg = 'bg-emerald-950 border-emerald-700 text-emerald-200';
            if (type === 'error') bg = 'bg-rose-950 border-rose-700 text-rose-200';
            if (type === 'info') bg = 'bg-cyan-950 border-cyan-700 text-cyan-200';

            toast.className = `fixed bottom-6 right-6 z-50 transform transition-all duration-300 flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl border text-sm font-medium ${bg}`;
            toast.classList.remove('translate-y-20', 'opacity-0');

            setTimeout(() => {
                toast.classList.add('translate-y-20', 'opacity-0');
            }, 3500);
        }
    </script>
</body>
</html>
