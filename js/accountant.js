    // Authentication and User Data
    const APP_STATE = {
        currentUser: null,
        currentPaymentLoan: null,
        currentActionLoan: null,
        currentActionType: null,
        csrfToken: null,
        selectedFundingAccountId: null,
        currentDispatchLoan: null
    };

    // Define state for client-side filters
    const loanFilters = {
        search: '',
        type: 'all'
    };

    // DOM Element References
    const DOM_ELEMENTS = {
        sidebar: document.getElementById('sidebar'),
        sectionTitle: document.getElementById('sectionTitle'),
        loansTable: document.getElementById('loansTable'),
        notification: document.getElementById('notification'),
        profileBadge: document.getElementById('profileBadge'),
        profileDropdown: document.getElementById('profileDropdown'),
        mainContent: document.getElementById('mainContent'), 
        fundingAccountModal: document.getElementById('fundingAccountModal'),
        modalLoaneeName: document.getElementById('modalLoaneeName'),
        modalLoanAmount: document.getElementById('modalLoanAmount'),
        modalLoanType: document.getElementById('modalLoanType'),
        fundingAccountsList: document.getElementById('fundingAccountsList'),
        noAccountsMessage: document.getElementById('noAccountsMessage'),
        confirmDispatch: document.getElementById('confirmDispatch')
    };

    // ==========================================
    // UTILITY FUNCTIONS
    // ==========================================
    function formatCurrency(amount) {
        return new Intl.NumberFormat().format(Math.round(amount));
    }

    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }

    function formatDateTime(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleString();
    }

    function formatApplicationId(applicationId) {
        if (!applicationId) return 'N/A';
        return applicationId;
    }

    function formatAccountNumber(accountNumber) {
        if (!accountNumber || accountNumber === 'N/A') return 'N/A';
        return accountNumber;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ==========================================
    // API FUNCTIONS
    // ==========================================
    async function apiCall(endpoint, method = 'GET', data = null) {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
            }
        };
        if (data) {
            options.body = JSON.stringify(data);
        }
        try {
            const response = await fetch(`${API_BASE_URL}?api&endpoint=${endpoint}`, options);
            if (!response.ok) {
                let errorMessage = `HTTP error! status: ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (e) {
                    errorMessage = response.statusText || errorMessage;
                }
                throw new Error(errorMessage);
            }
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error('Server returned an invalid response. Please contact support.');
            }
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Request failed');
            }
            return result;
        } catch (error) {
            throw error;
        }
    }

    // ==========================================
    // NOTIFICATION SYSTEM
    // ==========================================
    function showNotification(message, type = 'success', duration = 3000) {
        const notification = document.getElementById('notification');
        if (!notification) {
            return;
        }

        notification.textContent = message;
        notification.className = `notification show ${type}`;
        notification.style.display = 'block';

        setTimeout(() => {
            notification.classList.remove('show');
            // CRITICAL FIX: Set display to none so it actually disappears
            notification.style.display = 'none';
        }, duration);
    }

    // ==========================================
    // FUNDING ACCOUNT SELECTION MODAL
    // ==========================================
    async function showFundingAccountModal(loan) {
        APP_STATE.currentDispatchLoan = loan;
        APP_STATE.selectedFundingAccountId = null;

        // Populate loan details in modal
        DOM_ELEMENTS.modalLoaneeName.textContent = loan.full_name || 'N/A';
        DOM_ELEMENTS.modalLoanAmount.textContent = formatCurrency(loan.loan_amount || 0);
        DOM_ELEMENTS.modalLoanType.textContent = loan.loan_type ?
            loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) : 'N/A';

        // Fetch funding accounts
        try {
            const response = await apiCall('funding');
            const accounts = response.data || [];

            if (accounts.length === 0) {
                DOM_ELEMENTS.fundingAccountsList.innerHTML = '';
                DOM_ELEMENTS.noAccountsMessage.classList.remove('hidden');
                DOM_ELEMENTS.confirmDispatch.disabled = true;
            } else {
                DOM_ELEMENTS.noAccountsMessage.classList.add('hidden');

                const loanAmount = loan.loan_amount || 0;
                DOM_ELEMENTS.fundingAccountsList.innerHTML = accounts.map(account => {
                    const hasSufficientFunds = account.balance >= loanAmount;
                    const balanceClass = hasSufficientFunds ? '' : 'insufficient-funds';
                    const disabledAttr = hasSufficientFunds ? '' : 'disabled';

                    return `
                    <div class="funding-account-option ${!hasSufficientFunds ? 'disabled' : ''}" data-id="${account.id}">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <input type="radio" name="fundingAccount" value="${account.id}" class="radio-btn" ${disabledAttr}>
                                <strong>${escapeHtml(account.name || 'Unnamed Account')}</strong>
                            </div>
                            <div class="${balanceClass}">
                                Balance: KES ${formatCurrency(account.balance || 0)}
                                ${!hasSufficientFunds ? '<br><small>(Insufficient funds)</small>' : ''}
                            </div>
                        </div>
                    </div>
                `;
                }).join('');

                // Add click handlers to account options
                document.querySelectorAll('.funding-account-option:not(.disabled)').forEach(option => {
                    option.addEventListener('click', function() {
                        const radio = this.querySelector('input[type="radio"]');
                        if (radio && !radio.disabled) {
                            // Uncheck all radios
                            document.querySelectorAll('.funding-account-option input[type="radio"]')
                                .forEach(r => r.checked = false);
                            // Check this radio
                            radio.checked = true;
                            // Update selected state
                            document.querySelectorAll('.funding-account-option').forEach(opt => opt
                                .classList.remove('selected'));
                            this.classList.add('selected');
                            // Store selected ID
                            APP_STATE.selectedFundingAccountId = this.dataset.id;
                            // Enable confirm button
                            DOM_ELEMENTS.confirmDispatch.disabled = false;
                        }
                    });
                });
                DOM_ELEMENTS.confirmDispatch.disabled = true;
            }

            // Show modal
            DOM_ELEMENTS.fundingAccountModal.style.display = 'block';

        } catch (error) {
            showNotification('Failed to load funding accounts: ' + error.message, 'error');
        }
    }

    // ==========================================
    // LOAN CALCULATIONS
    // ==========================================
    function calculateMonthlyInterest(principal, annualRate, months = 1) {
        if (principal <= 0 || annualRate <= 0) return 0;
        const monthlyRate = annualRate / 100 / 12;
        return principal * monthlyRate * Math.max(1, months);
    }

    async function recalculateLoanBalance(loan) {
        const annualRate = 3.0; // Default interest rate
        const payments = Array.isArray(loan.payments) ? loan.payments : [];

        let totalPaid = 0;
        let principalPaid = 0;
        let interestPaid = 0;

        payments.forEach(payment => {
            const amount = parseFloat(payment.amount) || 0;
            const principal = parseFloat(payment.principal_amount) || 0;
            const interest = parseFloat(payment.interest_amount) || 0;

            totalPaid += amount;
            principalPaid += principal;
            interestPaid += interest;
        });

        const originalAmount = parseFloat(loan.loan_amount) || 0;
        const currentPrincipal = Math.max(0, originalAmount - principalPaid);

        let lastPaymentDate = null;
        if (payments.length > 0) {
            const sortedPayments = [...payments].sort((a, b) =>
                new Date(b.payment_date) - new Date(a.payment_date)
            );
            lastPaymentDate = new Date(sortedPayments[0].payment_date);
        } else {
            lastPaymentDate = loan.dispatch_date ? new Date(loan.dispatch_date) : new Date();
        }

        const today = new Date();
        const timeDiff = today - lastPaymentDate;
        const daysSinceLastPayment = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
        const monthsSinceLastPayment = Math.max(1, Math.round(daysSinceLastPayment / 30));

        const accruedInterest = currentPrincipal > 0 ?
            calculateMonthlyInterest(currentPrincipal, annualRate, monthsSinceLastPayment) :
            0;

        const totalBalanceDue = currentPrincipal + accruedInterest;

        return {
            originalAmount,
            totalPaid,
            principalPaid,
            interestPaid,
            currentPrincipal,
            accruedInterest: accruedInterest.toFixed(2),
            totalBalanceDue: totalBalanceDue.toFixed(2),
            lastPaymentDate: lastPaymentDate.toISOString().split('T')[0],
            monthsSinceLastPayment,
            daysSinceLastPayment
        };
    }

    // ==========================================
    // LOAN DATA MANAGEMENT
    // ==========================================
    async function getAllLoans() {
        try {
            const response = await apiCall('loans');
            return response.data || [];
        } catch (error) {
            return [];
        }
    }

    // ==========================================
    // RENDER FUNCTIONS (Updated with Search, Type, Clear)
    // ==========================================
    async function renderLoans(filter) {
        let loans = [];

        try {
            loans = await getAllLoans();
        } catch (error) {
            showNotification('Failed to load loan data: ' + error.message, 'error');
            return;
        }

        // Show appropriate section
        const loanSection = document.getElementById('loansSection');
        const paymentsSection = document.getElementById('paymentsSection');
        const fundingSection = document.getElementById('fundingSection');

        loanSection.classList.remove('hidden');
        paymentsSection.classList.add('hidden');
        fundingSection.classList.add('hidden');
        document.getElementById('dashboardSection')?.classList.add('hidden');

        if (!DOM_ELEMENTS.loansTable) {
            showNotification('The loan table could not be initialized. Please refresh the page.', 'error');
            return;
        }

        // ==========================================
        // 1. APPLY SIDEBAR STATUS FILTER (Server/State Filter)
        // ==========================================
        let statusFilteredLoans = [];
        let displayTitle = 'All Loans';

        switch (filter) {
            case 'forwarded':
                statusFilteredLoans = loans.filter(l => l.status === 'forwarded');
                displayTitle = 'Loans Forwarded to Accountant';
                break;
            case 'accepted':
                statusFilteredLoans = loans.filter(l => l.status === 'accepted');
                displayTitle = 'Accepted Loans (Awaiting Dispatch)';
                break;
            case 'dispatched':
                statusFilteredLoans = loans.filter(l => l.status === 'dispatched' || l.status === 'active');
                displayTitle = 'Dispatched/Active Loans';
                break;
            case 'active':
                statusFilteredLoans = loans.filter(l => l.status === 'active');
                displayTitle = 'Active Loans (In Repayment)';
                break;
            case 'rejected':
                statusFilteredLoans = loans.filter(l => l.status === 'rejected');
                displayTitle = 'Rejected Loans';
                break;
            case 'closed':
                statusFilteredLoans = loans.filter(l => l.status === 'closed');
                displayTitle = 'Closed Loans';
                break;
            case 'all':
            default:
                statusFilteredLoans = loans;
                displayTitle = 'All Loans';
        }

        // ==========================================
        // 2. APPLY CLIENT-SIDE FILTERS (Search & Type)
        // ==========================================
        let filteredLoans = [...statusFilteredLoans];
        let isClientFiltered = false;

        // Apply Loan Type Filter
        if (loanFilters.type && loanFilters.type !== 'all') {
            filteredLoans = filteredLoans.filter(l => l.loan_type === loanFilters.type);
            isClientFiltered = true;
        }

        // Apply Search Filter
        if (loanFilters.search && loanFilters.search.trim() !== '') {
            const term = loanFilters.search.trim().toLowerCase();
            filteredLoans = filteredLoans.filter(loan => {
                return loan.full_name.toLowerCase().includes(term) ||
                    loan.id_number.toLowerCase().includes(term) ||
                    loan.pf_number.toLowerCase().includes(term) ||
                    loan.application_id.toLowerCase().includes(term);
            });
            isClientFiltered = true;
        }

        // Update Title to reflect filtering status
        DOM_ELEMENTS.sectionTitle.textContent = isClientFiltered ? `${displayTitle} (Filtered)` : displayTitle;

        // Manage Clear Button Visibility (Assumed helper from previous step)
        if (typeof updateClearButtonVisibility === 'function') {
            updateClearButtonVisibility();
        }

        // ==========================================
        // 3. RENDER TABLE
        // ==========================================
        const loansTableBody = DOM_ELEMENTS.loansTable.querySelector('tbody');
        if (!loansTableBody) {
            return;
        }

        loansTableBody.innerHTML = '';

        // Show "No Results" message if empty
        if (filteredLoans.length === 0) {
            loansTableBody.innerHTML =
                '<tr><td colspan="11" style="text-align:center; padding: 30px;">No loans found matching your criteria.</td></tr>';
            return;
        }

        // Sort and Render Rows
        filteredLoans.sort((a, b) => new Date(b.application_date) - new Date(a.application_date));

        filteredLoans.forEach(loan => {
            const row = loansTableBody.insertRow();
            row.className = `status-${loan.status}`;
            row.insertCell().textContent = loan.full_name || 'N/A';
            row.insertCell().textContent = loan.id_number || 'N/A';
            row.insertCell().textContent = loan.pf_number || 'N/A';
            row.insertCell().textContent = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan
                .loan_type.slice(1) : 'N/A';

            const detailsCell = row.insertCell();
            const detailsBtn = document.createElement('button');
            detailsBtn.textContent = 'View';
            detailsBtn.className = 'action-btn';
            detailsBtn.onclick = () => showLoanDetails(loan);
            detailsCell.appendChild(detailsBtn);

            row.insertCell().textContent = loan.basic_salary ? `KES ${formatCurrency(loan.basic_salary)}` :
                'N/A';
            row.insertCell().textContent = loan.loan_amount ? `KES ${formatCurrency(loan.loan_amount)}` :
                'N/A';

            const balanceCell = row.insertCell();
            if (loan.status === 'active' || loan.status === 'dispatched') {
                recalculateLoanBalance(loan).then(balanceInfo => {
                    balanceCell.innerHTML =
                        `<strong>KES ${formatCurrency(balanceInfo.totalBalanceDue)}</strong>`;
                    if (balanceInfo.accruedInterest > 0) {
                        balanceCell.innerHTML +=
                            `<br><small class="val-interest">+KES ${formatCurrency(balanceInfo.accruedInterest)} interest</small>`;
                    }
                }).catch(() => {
                    balanceCell.textContent = 'Error';
                    balanceCell.style.color = 'red';
                });
            } else {
                balanceCell.textContent = '-';
            }

            // Add Amortization button for all loans
            const amortCell = row.insertCell();
            const amortBtn = document.createElement('button');
            amortBtn.textContent = 'Schedule';
            amortBtn.className = 'action-btn';
            amortBtn.onclick = () => showAmortization(loan);
            amortCell.appendChild(amortBtn);

            // Update status cell to use status badge
            const statusCell = row.insertCell();
            statusCell.innerHTML =
                `<span class="status-badge ${loan.status}">${loan.status ? loan.status.toUpperCase() : 'PENDING'}</span>`;

            // Remove the duplicate status text cell and keep only feedback
            row.insertCell().textContent = loan.feedback || 'None';

            const actionsCell = row.insertCell();
            actionsCell.className = 'actions-cell';
            actionsCell.innerHTML = '';

            if (loan.status === 'forwarded') {
                const acceptBtn = document.createElement('button');
                acceptBtn.textContent = 'Accept';
                acceptBtn.className = 'action-btn';
                acceptBtn.onclick = () => handleLoanAction(loan, 'accept');
                actionsCell.appendChild(acceptBtn);

                const rejectBtn = document.createElement('button');
                rejectBtn.textContent = 'Reject';
                rejectBtn.className = 'action-btn reject-btn';
                rejectBtn.onclick = () => showFeedbackModal(loan, 'reject');
                actionsCell.appendChild(rejectBtn);
            } else if (loan.status === 'accepted') {
                const dispatchBtn = document.createElement('button');
                dispatchBtn.textContent = 'Dispatch';
                dispatchBtn.className = 'action-btn';
                dispatchBtn.onclick = () => showFundingAccountModal(loan);
                actionsCell.appendChild(dispatchBtn);
            } else if (loan.status === 'dispatched' || loan.status === 'active') {
                // We already added the amortization button above, so no need to add it again
            }
        });
    }

    // ==========================================
    // MODAL FUNCTIONS
    // ==========================================
    async function showLoanDetails(loan) {
        let detailsHtml = `
        <button onclick="exportLoanDetails()" class="action-btn" style="float:right;background:#2196F3;margin-bottom:15px;">
            Export as PDF
        </button>
       
        <div class="balance-highlight">
            <h3 class="section-heading-green">Current Loan Financial Status</h3>
    `;

        const balanceInfo = await recalculateLoanBalance(loan);

        detailsHtml += `
        <table style="width:100%; font-size:0.95rem;">
            <tr>
                <td><strong>Original Loan Amount:</strong></td>
                <td style="text-align:right;">KES ${formatCurrency(loan.loan_amount)}</td>
            </tr>
            <tr>
                <td><strong>Total Paid:</strong></td>
                <td class="val-paid">KES ${formatCurrency(balanceInfo.totalPaid)}</td>
            </tr>
            <tr>
                <td><strong>Principal Paid:</strong></td>
                <td style="text-align:right;">KES ${formatCurrency(balanceInfo.principalPaid)}</td>
            </tr>
            <tr>
                <td><strong>Interest Paid:</strong></td>
                <td class="val-interest">KES ${formatCurrency(balanceInfo.interestPaid)}</td>
            </tr>
            <tr style="border-top: 1px solid #ccc;">
                <td><strong>Current Principal Balance:</strong></td>
                <td style="text-align:right;">KES ${formatCurrency(balanceInfo.currentPrincipal)}</td>
            </tr>
            <tr>
                <td><strong>Accrued Interest (${balanceInfo.monthsSinceLastPayment} months):</strong></td>
                <td class="val-interest">KES ${formatCurrency(balanceInfo.accruedInterest)}</td>
            </tr>
            <tr style="border-top:2px solid #2e7d32; font-weight:bold; font-size:1.1rem;">
                <td><strong>Total Balance Due:</strong></td>
                <td class="val-due">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
            </tr>
        </table>
        <p style="margin:10px 0 0 0; font-size:0.85rem; color:#666;">
            Last Payment: ${formatDate(balanceInfo.lastPaymentDate)} (${balanceInfo.monthsSinceLastPayment} months ago)
        </p>
    </div>
    
    <h3 style="color:#4a6b4a;">Loanee Information</h3>
    <p><strong>Full Name:</strong> ${loan.full_name}</p>
    <p><strong>ID Number:</strong> ${loan.id_number}</p>
    <p><strong>PF Number:</strong> ${loan.pf_number}</p>
    <p><strong>Email:</strong> ${loan.email || 'N/A'}</p>
    <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
    <h3 style="color:#4a6b4a;">Loan Details</h3>
    <p><strong>Loan Type:</strong> ${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) : 'N/A'}</p>
    <p><strong>Application Date:</strong> ${loan.application_date ? formatDateTime(loan.application_date) : 'N/A'}</p>
    <p><strong>Status:</strong> <span class="status-indicator ${loan.status}">${loan.status ? loan.status.toUpperCase() : 'PENDING'}</span></p>
    <p><strong>Payment Duration:</strong> ${loan.payment_duration || 'N/A'} years</p>
    <p><strong>Payment Frequency:</strong> Monthly</p>
    <p><strong>Basic Salary:</strong> KES ${loan.basic_salary ? formatCurrency(loan.basic_salary) : 'N/A'}</p>
    <p><strong>Employment Type:</strong> ${loan.employment_type || 'N/A'}</p>
    `;

        if (loan.employment_type === 'contracted') {
            detailsHtml += ` 
            <p><strong>Contract Period:</strong> ${loan.contract_period || 'N/A'} months</p>
            <p><strong>Contract Start:</strong> ${loan.contract_start_date || 'N/A'}</p>
            <p><strong>Contract End:</strong> ${loan.contract_end_date || 'N/A'}</p>
        `;
        }
        if (loan.loan_type === 'mortgage') {
            detailsHtml += ` 
            <p><strong>Property Location:</strong> ${loan.property_location || 'N/A'}</p> 
            <p><strong>Property Value:</strong> KES ${loan.property_value ? formatCurrency(loan.property_value) : 'N/A'}</p>
        `;
        }
        if (loan.loan_type === 'car') {
            detailsHtml += ` 
            <p><strong>Car Model:</strong> ${loan.car_type || 'N/A'}</p> 
            <p><strong>Car Value:</strong> KES ${loan.car_value ? formatCurrency(loan.car_value) : 'N/A'}</p>
        `;
        }
        detailsHtml += `
        <p><strong>Bank:</strong> ${loan.bank_name || 'N/A'} (${loan.bank_branch || 'N/A'})</p>
        <p><strong>Account Number:</strong> ${loan.bank_account_number || 'N/A'}</p>
    `;

        if (loan.dispatch_date) {
            detailsHtml += `
            <p><strong>Dispatch Date:</strong> ${formatDateTime(loan.dispatch_date)}</p>
        `;

            const dispatchDate = new Date(loan.dispatch_date);
            const gracePeriodEndDate = new Date(dispatchDate);
            gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);

            detailsHtml += `
            <div class="grace-period-notice">
                <h4>Grace Period Information</h4>
                <p><strong>Dispatch Date:</strong> ${formatDate(loan.dispatch_date)}</p>
                <p><strong>Grace Period End:</strong> ${formatDate(gracePeriodEndDate.toISOString())}</p>
                <p><strong>First Payment Due:</strong> ${formatDate(new Date(gracePeriodEndDate.getFullYear(), gracePeriodEndDate.getMonth() + 1, gracePeriodEndDate.getDate()))}</p>
                <p>The loanee has a one-month grace period after funds are dispatched before the first payment is due.</p>
            </div>
        `;
        }

        if (loan.payments && loan.payments.length > 0) {
            detailsHtml += `
            <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
            <h3 style="color:#4a6b4a;">Payment History (${loan.payments.length} payments)</h3>
            <div class="table-responsive">
                <table style="width:100%; font-size:0.85rem; border-collapse:collapse; margin-top:10px;">
                    <thead>
                        <tr style="background:#3a6b9a; color:white;">
                            <th style="padding:8px; border:1px solid #ccc;">#</th>
                            <th style="padding:8px; border:1px solid #ccc;">Date</th>
                            <th style="padding:8px; border:1px solid #ccc;">Amount</th>
                            <th style="padding:8px; border:1px solid #ccc;">Interest</th>
                            <th style="padding:8px; border:1px solid #ccc;">Principal</th>
                            <th style="padding:8px; border:1px solid #ccc;">Source</th>
                            <th style="padding:8px; border:1px solid #ccc;">Ref ID</th>
                            <th style="padding:8px; border:1px solid #ccc;">Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

            loan.payments.sort((a, b) => new Date(b.payment_date) - new Date(a.payment_date));

            loan.payments.forEach((p, idx) => {
                detailsHtml += `
                <tr>
                    <td style="padding:8px; border:1px solid #ccc;">${idx + 1}</td>
                    <td style="padding:8px; border:1px solid #ccc;">${formatDate(p.payment_date)}</td>
                    <td style="padding:8px; border:1px solid #ccc; font-weight:bold;">KES ${formatCurrency(p.amount)}</td>
                    <td style="padding:8px; border:1px solid #ccc; color:#d84315;">KES ${formatCurrency(p.interest_amount || 0)}</td>
                    <td style="padding:8px; border:1px solid #ccc; color:#2e7d32;">KES ${formatCurrency(p.principal_amount || 0)}</td>
                    <td style="padding:8px; border:1px solid #ccc; font-size:0.8rem;">${p.payment_source}</td>
                    <td style="padding:8px; border:1px solid #ccc; font-size:0.75rem; color:#666;">${p.transaction_reference || '-'}</td>
                    <td style="padding:8px; border:1px solid #ccc; font-size:0.8rem;">${p.recorded_by || 'Cashier'}</td>
                </tr>
            `;
            });

            detailsHtml += `
                </tbody>
            </table>
        </div>
    `;
        } else {
            detailsHtml += `
            <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
            <h3 style="color:#4a6b4a;">Payment History</h3>
            <p style="text-align:center; padding:20px; color:#666;">No payments recorded yet.</p>
        `;
        }

        detailsHtml += `
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
        <p><strong>Accountant Process Date:</strong> ${loan.accountant_processed_date ? formatDateTime(loan.accountant_processed_date) : 'N/A'}</p>
        <p><strong>Feedback:</strong> ${loan.feedback || 'None'}</p>
    `;

        document.getElementById('loanDetailsContent').innerHTML = detailsHtml;
        document.getElementById('detailsModal').style.display = 'block';
    }

    function showFeedbackModal(loan, actionType) {
        if (!loan || !actionType) return;
        APP_STATE.currentActionLoan = loan;
        APP_STATE.currentActionType = actionType;

        const modal = document.getElementById('feedbackModal');
        const title = document.getElementById('feedbackTitle');
        const textarea = document.getElementById('feedbackTextarea');

        title.textContent = actionType === 'reject' ? 'Reject Loan: Provide Reason' : 'Provide Feedback';
        textarea.value = '';
        modal.style.display = 'block';
    }

    // ==========================================
    // AMORTIZATION FUNCTIONS
    // ==========================================
    async function generatePaymentSchedule(loan) {
        const rate = 3.0; // Default interest rate
        loan.payment_frequency = 'monthly';

        const dispatchDate = new Date(loan.dispatch_date || loan.application_date);
        const gracePeriodEndDate = new Date(dispatchDate);
        gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);

        const startDate = new Date(gracePeriodEndDate);
        const principal = parseFloat(loan.loan_amount);
        const years = loan.payment_duration || 1;
        let schedule = [];
        let paymentsPerYear = 12;
        const totalPayments = years * paymentsPerYear;
        const periodicRate = (rate / 100) / paymentsPerYear;
        const periodicPayment = principal * (periodicRate * Math.pow(1 + periodicRate, totalPayments)) /
            (Math.pow(1 + periodicRate, totalPayments) - 1);
        let balance = principal;

        for (let i = 0; i < totalPayments; i++) {
            const dueDate = new Date(startDate);
            dueDate.setMonth(startDate.getMonth() + i);
            const interestPayment = balance * periodicRate;
            const principalPayment = periodicPayment - interestPayment;
            balance -= principalPayment;
            schedule.push({
                payment_number: i + 1,
                due_date: dueDate.toISOString(),
                expected_amount: periodicPayment,
                expected_principal: principalPayment,
                expected_interest: interestPayment,
                expected_balance: Math.max(0, balance),
                status: 'pending',
                actual_amount: 0,
                actual_interest: 0,
                actual_date: null,
                variance: 0
            });
        }

        return {
            schedule,
            grace_period_end_date: gracePeriodEndDate.toISOString(),
            start_date: startDate.toISOString()
        };
    }

    async function updatePaymentScheduleWithActuals(loan) {
        const scheduleData = await generatePaymentSchedule(loan);
        loan.payment_schedule = scheduleData.schedule;
        loan.grace_period_end_date = scheduleData.grace_period_end_date;
        loan.payment_start_date = scheduleData.start_date;

        const payments = loan.payments || [];
        payments.forEach(payment => {
            const paymentDate = new Date(payment.payment_date);
            const paymentMonth = paymentDate.getMonth();
            const paymentYear = paymentDate.getFullYear();

            const scheduleIndex = loan.payment_schedule.findIndex(scheduled => {
                const scheduledDate = new Date(scheduled.due_date);
                return scheduledDate.getMonth() === paymentMonth &&
                    scheduledDate.getFullYear() === paymentYear;
            });

            if (scheduleIndex >= 0) {
                const scheduled = loan.payment_schedule[scheduleIndex];
                scheduled.actual_amount = parseFloat(payment.amount) || 0;
                scheduled.actual_interest = parseFloat(payment.interest_amount) || 0;
                scheduled.actual_date = payment.payment_date;
                scheduled.variance = scheduled.actual_amount - scheduled.expected_amount;
                scheduled.status = scheduled.actual_amount >= scheduled.expected_amount ? 'paid' :
                    'partial';
            }
        });

        return loan;
    }

    async function showAmortization(loan) {
        if (!loan) return;
        const amortTableBody = document.getElementById('amortTableBody');
        amortTableBody.innerHTML = '';
        const balanceInfo = await recalculateLoanBalance(loan);
        const schedule = await updatePaymentScheduleWithActuals(loan);
        const amortInfo = document.getElementById('amortInfo');

        let gracePeriodNotice = '';
        if (loan.dispatch_date) {
            const dispatchDate = new Date(loan.dispatch_date);
            const gracePeriodEndDate = new Date(dispatchDate);
            gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);
            gracePeriodNotice = `
            <div class="grace-period-notice">
                <h4>Grace Period Information</h4>
                <p><strong>Dispatch Date:</strong> ${formatDate(loan.dispatch_date)}</p>
                <p><strong>Grace Period End:</strong> ${formatDate(gracePeriodEndDate.toISOString())}</p>
                <p><strong>First Payment Due:</strong> ${formatDate(new Date(gracePeriodEndDate.getFullYear(), gracePeriodEndDate.getMonth() + 1, gracePeriodEndDate.getDate()))}</p>
                <p>The loanee has a one-month grace period after funds are dispatched before the first payment is due.</p>
            </div>
        `;
        }

        amortInfo.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="section-heading-green">Payment Schedule Summary</h3>
            <button onclick="exportAmortization()" class="action-btn" style="background:#2196F3;">Export Schedule</button>
        </div>
        ${gracePeriodNotice}
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;margin-top:20px;">
            <div>
                <strong>Original Loan: KES ${formatCurrency(loan.loan_amount)}</strong><br>
                <strong>Current Balance:</strong> <span class="val-due">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</span>
            </div>
            <div>
                <strong>Total Paid:</strong> KES ${formatCurrency(balanceInfo.totalPaid)}<br>
                <strong>Interest Rate:</strong> 3% per year
            </div>
            <div>
                <strong>Payment Frequency:</strong> Monthly<br>
                <strong>Months Since Last Payment:</strong> ${balanceInfo.monthsSinceLastPayment}
            </div>
        </div>
    `;

        if (!schedule.payment_schedule || schedule.payment_schedule.length === 0) {
            amortTableBody.innerHTML =
                '<tr><td colspan="9" style="text-align:center; padding:30px;">No payment schedule generated yet. Loan must be dispatched first.</td></tr>';
            document.getElementById('amortizationModal').style.display = 'block';
            return;
        }

        schedule.payment_schedule.forEach((payment) => {
            const statusColors = {
                'paid': '#d4edda',
                'partial': '#fff3cd',
                'pending': '#ffffff',
                'overdue': '#f8d7da'
            };
            const row = `
            <tr style="background:${statusColors[payment.status] || '#fff'};">
                <td>${payment.payment_number}</td>
                <td>${formatDate(payment.due_date)}</td>
                <td>KES ${formatCurrency(payment.expected_amount)}</td>
                <td>${payment.actual_amount > 0 ? 'KES ' + formatCurrency(payment.actual_amount) : '-'}</td>
                <td class="val-interest">KES ${formatCurrency(payment.expected_interest)}</td>
                <td class="val-interest">${payment.actual_interest > 0 ? 'KES ' + formatCurrency(payment.actual_interest) : '-'}</td>
                <td>KES ${formatCurrency(payment.expected_principal)}</td>
                <td>KES ${formatCurrency(payment.expected_balance)}</td>
                <td><strong>${payment.status.toUpperCase()}</strong>${payment.variance !== 0 && payment.actual_amount > 0 ?
                    `<br><small style="color:${payment.variance > 0 ? 'green' : 'red'};">${payment.variance > 0 ? '+' : ''}KES ${Math.round(payment.variance)}</small>`:
                    ''
                }</td>
            </tr>
        `;
            amortTableBody.innerHTML += row;
        });
        document.getElementById('amortizationModal').style.display = 'block';
    }

    // ==========================================
    // LOAN ACTIONS
    // ==========================================
    async function handleLoanAction(loan, action) {
        try {
            if (action === 'accept') {
                const result = await apiCall('loans', 'POST', {
                    action: 'update_status',
                    loan_id: loan.id,
                    status: 'accepted',
                    feedback: 'Accepted by Accountant'
                });
                if (result.success) {
                    showNotification(`Loan for ${loan.full_name} accepted.`, 'success');
                }
            } else if (action === 'dispatch') {
                showFundingAccountModal(loan);
                return;
            }
            renderLoans(document.querySelector('#sidebar li.active').dataset.filter);
        } catch (error) {
            showNotification('Failed to update loan: ' + error.message, 'error');
        }
    }

    async function confirmDispatch() {
        if (!APP_STATE.selectedFundingAccountId) {
            showNotification('Please select a funding account', 'error');
            return;
        }

        if (!APP_STATE.currentDispatchLoan) {
            showNotification('No loan selected for dispatch', 'error');
            return;
        }

        try {
            const result = await apiCall('loans', 'POST', {
                action: 'dispatch',
                loan_id: APP_STATE.currentDispatchLoan.id,
                funding_account_id: APP_STATE.selectedFundingAccountId
            });

            if (result.success) {
                showNotification(`Loan for ${APP_STATE.currentDispatchLoan.full_name} dispatched successfully.`,
                    'success');
                document.getElementById('fundingAccountModal').style.display = 'none';
                renderLoans(document.querySelector('#sidebar li.active').dataset.filter);
            } else {
                showNotification(result.message || 'Failed to dispatch loan', 'error');
            }
        } catch (error) {
            showNotification('Failed to dispatch loan: ' + error.message, 'error');
        }
    }

    async function submitFeedback() {
        const feedback = document.getElementById('feedbackTextarea').value.trim();
        if (!feedback) {
            showNotification('Feedback/Reason is required.', 'error');
            return;
        }
        try {
            const result = await apiCall('loans', 'POST', {
                action: 'update_status',
                loan_id: APP_STATE.currentActionLoan.id,
                status: APP_STATE.currentActionType,
                feedback: feedback
            });

            if (result.success) {
                showNotification(
                    `Loan ${APP_STATE.currentActionType === 'reject' ? 'rejected' : 'updated'} successfully!`,
                    'success');
                document.getElementById('feedbackModal').style.display = 'none';
                renderLoans(document.querySelector('#sidebar li.active').dataset.filter);
            }
        } catch (error) {
            showNotification('Failed to update loan: ' + error.message, 'error');
        }
    }

    // ==========================================
    // LOAD PAYMENTS AND ACCOUNTS
    // ==========================================
    async function loadPayments() {
        try {
            const response = await apiCall('payments');
            const payments = response.data || [];

            document.getElementById('loansSection')?.classList.add('hidden');
            document.getElementById('fundingSection')?.classList.add('hidden');
            document.getElementById('dashboardSection')?.classList.add('hidden');
            document.getElementById('paymentsSection')?.classList.remove('hidden');

            const tbody = document.querySelector('#paymentsTable tbody');
            if (!tbody) {
                showNotification('Payments table not found.', 'error');
                return;
            }

            tbody.innerHTML = '';

            if (payments.length === 0) {
                tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align:center; padding: 30px; color: #666;">
                        No payment records found.
                    </td>
                </tr>
            `;
                return;
            }

            payments.forEach(payment => {
                const row = tbody.insertRow();

                row.insertCell().textContent = escapeHtml(payment.loanee_name || '—');
                row.insertCell().textContent = payment.pf_number || '—';
                row.insertCell().textContent = formatDate(payment.payment_date);
                row.insertCell().textContent = `KES ${formatCurrency(payment.principal_amount)}`;
                row.insertCell().textContent = `KES ${formatCurrency(payment.interest_amount)}`;
                row.insertCell().textContent = `KES ${formatCurrency(payment.amount)}`;
                row.insertCell().textContent = payment.payment_source || '—';
                row.insertCell().textContent = payment.recorded_by || '—';

                const actionsCell = row.insertCell();
                const viewBtn = document.createElement('button');
                viewBtn.textContent = 'View';
                viewBtn.className = 'btn btn-sm btn-info';
                viewBtn.style.margin = '2px';
                viewBtn.onclick = () => showPaymentDetails(payment);
                actionsCell.appendChild(viewBtn);
            });

        } catch (error) {
            showNotification('Failed to load payments: ' + (error.message || 'Network error'), 'error');
        }
    }

    async function loadFundingAccounts() {
        try {
            const response = await apiCall('funding');
            const accounts = response.data || [];

            document.getElementById('loansSection')?.classList.add('hidden');
            document.getElementById('paymentsSection')?.classList.add('hidden');
            document.getElementById('dashboardSection')?.classList.add('hidden');
            document.getElementById('fundingSection')?.classList.remove('hidden');

            const container = document.getElementById('fundingAccountsContainer');
            if (!container) {
                showNotification('Funding container not found.', 'error');
                return;
            }

            container.innerHTML = '';

            if (accounts.length === 0) {
                container.innerHTML =
                    '<p style="text-align:center; padding: 30px; color: #666;">No funding accounts found.</p>';
                return;
            }

            let html = `
            <div class="table-container">
                <table style="width:100%; border-collapse:collapse; margin-top: 10px;">
                    <thead>
                        <tr style="background:#3a6b9a; color:white; text-align:left;">
                            <th style="padding:12px;">Account Name</th>
                            <th style="padding:12px;">Balance</th>
                            <th style="padding:12px;">Created At</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

            accounts.forEach(account => {
                html += `
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding:12px;">${escapeHtml(account.name || '—')}</td>
                    <td style="padding:12px; font-weight:600;">KES ${formatCurrency(account.balance)}</td>
                    <td style="padding:12px; color:#555;">${formatDate(account.created_at)}</td>
                </tr>
            `;
            });

            html += `
                    </tbody>
                </table>
            </div>
        `;

            container.innerHTML = html;

        } catch (error) {
            showNotification('Failed to load funding accounts: ' + (error.message || 'Network error'), 'error');
        }
    }

    function showPaymentDetails(payment) {
        const modal = document.getElementById('paymentDetailsModal');
        const content = document.getElementById('paymentDetailsContent');

        content.innerHTML = `
        <p><strong>Loanee Name:</strong> ${payment.loanee_name}</p>
        <p><strong>PF Number:</strong> ${payment.pf_number}</p>
        <p><strong>Payment Date:</strong> ${formatDate(payment.payment_date)}</p>
        <p><strong>Principal Amount:</strong> KES ${formatCurrency(payment.principal_amount)}</p>
        <p><strong>Interest Amount:</strong> KES ${formatCurrency(payment.interest_amount)}</p>
        <p><strong>Total Amount:</strong> KES ${formatCurrency(payment.amount)}</p>
        <p><strong>Payment Source:</strong> ${payment.payment_source}</p>
        <p><strong>Transaction Reference:</strong> ${payment.transaction_reference || 'N/A'}</p>
        <p><strong>Recorded By:</strong> ${payment.recorded_by}</p>
        <p><strong>Notes:</strong> ${payment.notes || 'None'}</p>
    `;

        modal.style.display = 'block';
    }

    // ==========================================
    // FINANCIAL REPORTS VIEW FUNCTION (Updated with Filters)
    // ==========================================
    // ==========================================
    // FINANCIAL REPORTS VIEW FUNCTION
    // ==========================================
    async function showReportsView(loans) {
        // Hide loading indicator if exists
        if (DOM_ELEMENTS.loadingIndicator) DOM_ELEMENTS.loadingIndicator.style.display = 'none';

        if (!loans) loans = await getAllLoans();

        // Hide other sections, show reports
        document.getElementById('loansSection')?.classList.add('hidden');
        document.getElementById('paymentsSection')?.classList.add('hidden');
        document.getElementById('fundingSection')?.classList.add('hidden');
        document.getElementById('reportsSection')?.classList.remove('hidden');

        // Ensure sidebar navigation is updated
        DOM_ELEMENTS.sectionTitle.textContent = '<i data-lucide="bar-chart-3" class="icon-sm"></i> Financial Reports & Analytics';

        // Create reports container if missing
        let reportsDiv = document.getElementById('reportsContent');
        if (!reportsDiv) {
            reportsDiv = document.createElement('div');
            reportsDiv.id = 'reportsContent';
            document.getElementById('reportsSection').appendChild(reportsDiv);
        }

        // State for filters
        let reportFilters = {
            fromDate: null,
            toDate: null,
            loanType: 'all',
            pfNumber: null
        };

        // --- RENDER FILTER SECTION HTML ---
        let filterHtml = `
        <div class="filter-section">
            <h3 style="margin-top:0; color:var(--green);"><i data-lucide="search" class="icon-sm"></i> Filter Reports</h3>
            <div class="filter-row">
                <div class="filter-group">
                    <label for="reportFromDate">From Date:</label>
                    <input type="date" id="reportFromDate" value="">
                </div>
                <div class="filter-group">
                    <label for="reportToDate">To Date:</label>
                    <input type="date" id="reportToDate" value="">
                </div>
                <div class="filter-group">
                    <label for="reportLoanType">Loan Type:</label>
                    <select id="reportLoanType">
                        <option value="all">All Loans</option>
                        <option value="mortgage">Mortgage</option>
                        <option value="car">Car</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="reportPfNumber">PF Number:</label>
                    <input type="text" id="reportPfNumber" placeholder="Enter PF Number">
                </div>
            </div>
            <div class="filter-buttons">
                <button onclick="applyReportFilters()">Apply Filters</button>
                <button class="btn-secondary" onclick="resetReportFilters()">Reset</button>
            </div>
        </div>
    `;

        // Attach Window Functions for Filter Buttons
        window.applyReportFilters = function() {
            reportFilters.fromDate = document.getElementById('reportFromDate').value;
            reportFilters.toDate = document.getElementById('reportToDate').value;
            reportFilters.loanType = document.getElementById('reportLoanType').value;
            reportFilters.pfNumber = document.getElementById('reportPfNumber').value.trim();
            showReportsViewWithFilters(loans, reportFilters);
        };

        window.resetReportFilters = function() {
            document.getElementById('reportFromDate').value = '';
            document.getElementById('reportToDate').value = '';
            document.getElementById('reportLoanType').value = 'all';
            document.getElementById('reportPfNumber').value = '';
            reportFilters = {
                fromDate: null,
                toDate: null,
                loanType: 'all',
                pfNumber: null
            };
            showReportsView(loans);
        };

        // Initial Render
        reportsDiv.innerHTML = filterHtml;
        showReportsViewWithFilters(loans, reportFilters);
    }

    // ==========================================
    // HELPER FUNCTIONS FOR FILTERS & EXPORTS
    // ==========================================

    async function showReportsViewWithFilters(loans, filters) {
        // Apply filters to loans
        let filteredLoans = [...loans];

        // Filter by date range
        if (filters.fromDate) {
            const fromDate = new Date(filters.fromDate);
            filteredLoans = filteredLoans.filter(loan => {
                const loanDate = new Date(loan.application_date);
                return loanDate >= fromDate;
            });
        }

        if (filters.toDate) {
            const toDate = new Date(filters.toDate);
            toDate.setHours(23, 59, 59, 999); // Include the entire day
            filteredLoans = filteredLoans.filter(loan => {
                const loanDate = new Date(loan.application_date);
                return loanDate <= toDate;
            });
        }

        // Filter by loan type
        if (filters.loanType !== 'all') {
            filteredLoans = filteredLoans.filter(loan => loan.loan_type === filters.loanType);
        }

        // Filter by PF number
        if (filters.pfNumber) {
            filteredLoans = filteredLoans.filter(loan =>
                loan.pf_number && loan.pf_number.toLowerCase().includes(filters.pfNumber.toLowerCase())
            );
        }

        // Fetch payment data for filtered loans (active and closed)
        const activeLoans = filteredLoans.filter(l => l.status === 'active' || l.status === 'dispatched');
        const closedLoans = filteredLoans.filter(l => l.status === 'closed');

        // Calculate statistics based on filtered data
        const mortgageLoans = filteredLoans.filter(l => l.loan_type === 'mortgage');
        const carLoans = filteredLoans.filter(l => l.loan_type === 'car');

        // Overall statistics
        let totalLoanedAmount = 0;
        let totalPaidAmount = 0;
        let totalPrincipalPaid = 0;
        let totalInterestPaid = 0;
        let totalOutstandingBalance = 0;

        // By loan type
        const mortgageStats = {
            loaned: 0,
            paid: 0,
            principal: 0,
            interest: 0,
            balance: 0,
            count: 0
        };
        const carStats = {
            loaned: 0,
            paid: 0,
            principal: 0,
            interest: 0,
            balance: 0,
            count: 0
        };

        // By year statistics
        const yearlyStats = {};

        // Calculate stats asynchronously
        const loansToProcess = [...activeLoans, ...closedLoans];

        for (const loan of loansToProcess) {
            const loanAmount = parseFloat(loan.loan_amount) || 0;
            const balanceInfo = await recalculateLoanBalance(loan);

            totalLoanedAmount += loanAmount;
            totalPaidAmount += parseFloat(balanceInfo.totalPaid) || 0;
            totalPrincipalPaid += parseFloat(balanceInfo.principalPaid) || 0;
            totalInterestPaid += parseFloat(balanceInfo.interestPaid) || 0;

            if (loan.status !== 'closed') {
                totalOutstandingBalance += parseFloat(balanceInfo.totalBalanceDue) || 0;
            }

            // By loan type
            const stats = loan.loan_type === 'mortgage' ? mortgageStats : carStats;
            stats.loaned += loanAmount;
            stats.paid += parseFloat(balanceInfo.totalPaid) || 0;
            stats.principal += parseFloat(balanceInfo.principalPaid) || 0;
            stats.interest += parseFloat(balanceInfo.interestPaid) || 0;
            if (loan.status !== 'closed') {
                stats.balance += parseFloat(balanceInfo.totalBalanceDue) || 0;
            }
            stats.count++;

            // By year
            const dispatchDate = loan.dispatch_date || loan.application_date;
            if (dispatchDate) {
                const year = new Date(dispatchDate).getFullYear();
                if (!yearlyStats[year]) {
                    yearlyStats[year] = {
                        loaned: 0,
                        paid: 0,
                        principal: 0,
                        interest: 0,
                        balance: 0,
                        count: 0,
                        mortgage: {
                            count: 0,
                            amount: 0
                        },
                        car: {
                            count: 0,
                            amount: 0
                        }
                    };
                }
                yearlyStats[year].loaned += loanAmount;
                yearlyStats[year].paid += parseFloat(balanceInfo.totalPaid) || 0;
                yearlyStats[year].principal += parseFloat(balanceInfo.principalPaid) || 0;
                yearlyStats[year].interest += parseFloat(balanceInfo.interestPaid) || 0;
                if (loan.status !== 'closed') {
                    yearlyStats[year].balance += parseFloat(balanceInfo.totalBalanceDue) || 0;
                }
                yearlyStats[year].count++;

                if (loan.loan_type === 'mortgage') {
                    yearlyStats[year].mortgage.count++;
                    yearlyStats[year].mortgage.amount += loanAmount;
                } else {
                    yearlyStats[year].car.count++;
                    yearlyStats[year].car.amount += loanAmount;
                }
            }
        }

        // Generate HTML for reports (Matching Cashier Layout)
        let html = `
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <!-- Overall Summary Card -->
            <div class="balance-highlight" style="background: #3b82f6; color: white; border: none; position: relative;">
                <button onclick="exportOverallSummary()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.2); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.4)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.2)'"><i data-lucide="download" class="icon-sm"></i> Export</button>
                <h3 style="margin-top:0; color: white; padding-right: 80px;"><i data-lucide="briefcase" class="icon-sm" style="margin-right: 8px;"></i>Overall Summary</h3>
                <p style="color: white; font-size: 0.95rem; line-height: 1.8; text-shadow: 1px 1px 2px rgba(0,0,0,0.2);"><strong style="color: rgba(255,255,255,0.9);">Total Loaned:</strong> <span style="font-weight: 600;">KES ${formatCurrency(totalLoanedAmount)}</span></p>
                <p style="color: white; font-size: 0.95rem; line-height: 1.8; text-shadow: 1px 1px 2px rgba(0,0,0,0.2);"><strong style="color: rgba(255,255,255,0.9);">Total Loans:</strong> <span style="font-weight: 600;">${activeLoans.length + closedLoans.length}</span></p>
                <p style="color: white; font-size: 0.95rem; line-height: 1.8; text-shadow: 1px 1px 2px rgba(0,0,0,0.2);"><strong style="color: rgba(255,255,255,0.9);">Active Loans:</strong> <span style="font-weight: 600;">${activeLoans.length}</span></p>
                <p style="color: white; font-size: 0.95rem; line-height: 1.8; text-shadow: 1px 1px 2px rgba(0,0,0,0.2);"><strong style="color: rgba(255,255,255,0.9);">Closed Loans:</strong> <span style="font-weight: 600;">${closedLoans.length}</span></p>
            </div>

            <!-- Mortgage Summary Card -->
            <div class="balance-highlight" style="background: #14b8a6; color: white; border: none; position: relative;">
                <button onclick="exportMortgageLoans()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.2); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.4)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.2)'"><i data-lucide="download" class="icon-sm"></i> Export</button>
                <h3 style="margin-top:0; color: white; padding-right: 80px;"><i data-lucide="home" class="icon-sm" style="margin-right: 8px;"></i>Mortgage Loans</h3>
                <p><strong>Total Count:</strong> ${mortgageStats.count}</p>
                <p><strong>Total Loaned:</strong> KES ${formatCurrency(mortgageStats.loaned)}</p>
                <p><strong>Total Paid:</strong> KES ${formatCurrency(mortgageStats.paid)}</p>
                <p><strong>Outstanding:</strong> KES ${formatCurrency(mortgageStats.balance)}</p>
            </div>

            <!-- Car Loan Summary Card -->
            <div class="balance-highlight" style="background: #f59e0b; color: white; border: none; position: relative;">
                <button onclick="exportCarLoans()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.2); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.4)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.2)'"><i data-lucide="download" class="icon-sm"></i> Export</button>
                <h3 style="margin-top:0; color: white; padding-right: 80px;"><i data-lucide="car" class="icon-sm" style="margin-right: 8px;"></i>Car Loans</h3>
                <p><strong>Total Count:</strong> ${carStats.count}</p>
                <p><strong>Total Loaned:</strong> KES ${formatCurrency(carStats.loaned)}</p>
                <p><strong>Total Paid:</strong> KES ${formatCurrency(carStats.paid)}</p>
                <p><strong>Outstanding:</strong> KES ${formatCurrency(carStats.balance)}</p>
            </div>

            <!-- Payment Summary Card -->
            <div class="balance-highlight" style="background: #10b981; color: white; border: none; position: relative;">
                <button onclick="exportPaymentSummary()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.2); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.4)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.2)'"><i data-lucide="download" class="icon-sm"></i> Export</button>
                <h3 style="margin-top:0; color: white; padding-right: 80px;"><i data-lucide="coins" class="icon-sm" style="margin-right: 8px;"></i>Payment Summary</h3>
                <p><strong>Total Paid:</strong> KES ${formatCurrency(totalPaidAmount)}</p>
                <p><strong>Principal Paid:</strong> KES ${formatCurrency(totalPrincipalPaid)}</p>
                <p><strong>Interest Paid:</strong> KES ${formatCurrency(totalInterestPaid)}</p>
                <p><strong>Outstanding:</strong> KES ${formatCurrency(totalOutstandingBalance)}</p>
            </div>
        </div>

        <!-- Yearly Breakdown -->
        <div class="balance-highlight">
            <h3 style="margin-top:0; color:var(--green); display:flex; align-items:center; gap:8px;"><i data-lucide="calendar" class="icon-sm"></i> Yearly Breakdown</h3>
            <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                <thead>
                    <tr style="background:var(--blue); color:white;">
                        <th style="padding:12px; text-align:left;">Year</th>
                        <th style="padding:12px; text-align:center;">Total Loans</th>
                        <th style="padding:12px; text-align:center;">Mortgage</th>
                        <th style="padding:12px; text-align:center;">Car</th>
                        <th style="padding:12px; text-align:right;">Amount Loaned</th>
                        <th style="padding:12px; text-align:right;">Principal Paid</th>
                        <th style="padding:12px; text-align:right;">Interest Paid</th>
                        <th style="padding:12px; text-align:right;">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
    `;

        const sortedYears = Object.keys(yearlyStats).sort((a, b) => b - a);
        sortedYears.forEach(year => {
            const stats = yearlyStats[year];
            html += `
            <tr style="border-bottom: 1px solid var(--border-color);">
                <td style="padding:12px; font-weight:bold;">${year}</td>
                <td style="padding:12px; text-align:center;">${stats.count}</td>
                <td style="padding:12px; text-align:center;">${stats.mortgage.count} (KES ${formatCurrency(stats.mortgage.amount)})</td>
                <td style="padding:12px; text-align:center;">${stats.car.count} (KES ${formatCurrency(stats.car.amount)})</td>
                <td style="padding:12px; text-align:right; font-weight:bold;">KES ${formatCurrency(stats.loaned)}</td>
                <td style="padding:12px; text-align:right; color:var(--green);">KES ${formatCurrency(stats.principal)}</td>
                <td style="padding:12px; text-align:right; color:var(--warning);">KES ${formatCurrency(stats.interest)}</td>
                <td style="padding:12px; text-align:right; color:var(--danger);">KES ${formatCurrency(stats.balance)}</td>
            </tr>
        `;
        });

        html += `
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-tertiary); font-weight:bold; border-top: 2px solid var(--blue);">
                        <td style="padding:12px;">TOTAL</td>
                        <td style="padding:12px; text-align:center;">${activeLoans.length + closedLoans.length}</td>
                        <td style="padding:12px; text-align:center;">${mortgageStats.count}</td>
                        <td style="padding:12px; text-align:center;">${carStats.count}</td>
                        <td style="padding:12px; text-align:right;">KES ${formatCurrency(totalLoanedAmount)}</td>
                        <td style="padding:12px; text-align:right; color:var(--green);">KES ${formatCurrency(totalPrincipalPaid)}</td>
                        <td style="padding:12px; text-align:right; color:var(--warning);">KES ${formatCurrency(totalInterestPaid)}</td>
                        <td style="padding:12px; text-align:right; color:var(--danger);">KES ${formatCurrency(totalOutstandingBalance)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Detailed Active Loans -->
        <div class="balance-highlight" style="margin-top:20px;">
            <h3 style="margin-top:0; color:var(--green);"><i data-lucide="clipboard-list" class="icon-sm"></i> Active Loans Details</h3>
            <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                <thead>
                    <tr style="background:var(--blue); color:white;">
                        <th style="padding:12px;">Loanee</th>
                        <th style="padding:12px;">PF Number</th>
                        <th style="padding:12px;">Type</th>
                        <th style="padding:12px;">Start Date</th>
                        <th style="padding:12px; text-align:right;">Original Amount</th>
                        <th style="padding:12px; text-align:right;">Principal Paid</th>
                        <th style="padding:12px; text-align:right;">Interest Paid</th>
                        <th style="padding:12px; text-align:right;">Balance Due</th>
                    </tr>
                </thead>
                <tbody>
    `;

        for (const loan of activeLoans) {
            const balanceInfo = await recalculateLoanBalance(loan);
            html += `
            <tr style="border-bottom: 1px solid var(--border-color);">
                <td style="padding:12px;">${loan.full_name}</td>
                <td style="padding:12px;">${loan.pf_number}</td>
                <td style="padding:12px;">${loan.loan_type === 'mortgage' ? '<i data-lucide="home" class="icon-sm" style="vertical-align: middle;"></i> Mortgage' : '<i data-lucide="car" class="icon-sm" style="vertical-align: middle;"></i> Car'}</td>
                <td style="padding:12px;">${formatDate(loan.dispatch_date || loan.application_date)}</td>
                <td style="padding:12px; text-align:right;">KES ${formatCurrency(loan.loan_amount)}</td>
                <td style="padding:12px; text-align:right; color:var(--green);">KES ${formatCurrency(balanceInfo.principalPaid)}</td>
                <td style="padding:12px; text-align:right; color:var(--warning);">KES ${formatCurrency(balanceInfo.interestPaid)}</td>
                <td style="padding:12px; text-align:right; color:var(--danger); font-weight:bold;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
            </tr>
        `;
        }

        html += `
    </tbody>
</table>
</div>
`;

        // Append to reports container
        // Preserve the filter section (it's already in reportsDiv.innerHTML at this point)
        const reportsDiv = document.getElementById('reportsContent');
        const filterSection = reportsDiv.querySelector('.filter-section');
        const filterHtml = filterSection ? filterSection.outerHTML : '';

        // Replace content (not append) to avoid duplicates
        reportsDiv.innerHTML = filterHtml + html;
        reportsDiv.style.display = 'block';
    }

    // Function to export filtered report data
    async function exportFilteredReportData(loans, filters, isExcel) {
        try {
            // Apply same filters as in the view
            let filteredLoans = [...loans];

            // Filter by date range
            if (filters.fromDate) {
                const fromDate = new Date(filters.fromDate);
                filteredLoans = filteredLoans.filter(loan => {
                    const loanDate = new Date(loan.application_date);
                    return loanDate >= fromDate;
                });
            }

            if (filters.toDate) {
                const toDate = new Date(filters.toDate);
                toDate.setHours(23, 59, 59, 999); // Include the entire day
                filteredLoans = filteredLoans.filter(loan => {
                    const loanDate = new Date(loan.application_date);
                    return loanDate <= toDate;
                });
            }

            // Filter by loan type
            if (filters.loanType !== 'all') {
                filteredLoans = filteredLoans.filter(loan => loan.loan_type === filters.loanType);
            }

            // Filter by PF number
            if (filters.pfNumber) {
                filteredLoans = filteredLoans.filter(loan =>
                    loan.pf_number && loan.pf_number.toLowerCase().includes(filters.pfNumber.toLowerCase())
                );
            }

            // Prepare data for export
            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'ID Number',
                'Loan Type',
                'Original Amount (KES)',
                'Total Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Status',
                'Application Date',
                'Dispatch Date',
                'Bank Name',
                'Account Number'
            ];

            const data = [];

            // Calculate stats for export (need payments)
            const activeLoans = filteredLoans.filter(l => l.status === 'active' || l.status === 'dispatched');
            const closedLoans = filteredLoans.filter(l => l.status === 'closed');

            for (const loan of [...activeLoans, ...closedLoans]) {
                const balanceInfo = await recalculateLoanBalance(loan);
                data.push([
                    formatApplicationId(loan.application_id),
                    loan.full_name,
                    loan.pf_number,
                    loan.id_number,
                    loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) :
                    'N/A',
                    formatCurrency(loan.loan_amount),
                    formatCurrency(balanceInfo.totalPaid),
                    formatCurrency(balanceInfo.principalPaid),
                    formatCurrency(balanceInfo.interestPaid),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) : '0',
                    loan.status.toUpperCase(),
                    formatDate(loan.application_date),
                    loan.dispatch_date ? formatDate(loan.dispatch_date) : 'N/A',
                    loan.bank_name || 'N/A',
                    formatAccountNumber(loan.bank_account_number)
                ]);
            }

            // Export based on selected format
            if (isExcel && typeof XLSX !== 'undefined') {
                // Export to Excel using SheetJS
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.aoa_to_sheet([headers, ...data]);

                // Set column widths
                const wscols = [{
                        wch: 15
                    }, {
                        wch: 20
                    }, {
                        wch: 15
                    }, {
                        wch: 15
                    },
                    {
                        wch: 15
                    }, {
                        wch: 18
                    }, {
                        wch: 15
                    }, {
                        wch: 15
                    }, {
                        wch: 15
                    },
                    {
                        wch: 18
                    },
                    {
                        wch: 12
                    },
                    {
                        wch: 15
                    },
                    {
                        wch: 15
                    },
                    {
                        wch: 15
                    },
                    {
                        wch: 20
                    }
                ];
                ws['!cols'] = wscols;

                XLSX.utils.book_append_sheet(wb, ws, "Filtered Report");

                // Generate filename with filter info
                let filename = `Filtered_Report_${new Date().toISOString().split('T')[0]}`;
                if (filters.fromDate) filename += `_From_${filters.fromDate}`;
                if (filters.toDate) filename += `_To_${filters.toDate}`;
                if (filters.loanType !== 'all') filename += `_${filters.loanType}`;
                if (filters.pfNumber) filename += `_PF_${filters.pfNumber}`;
                filename += '.xlsx';

                XLSX.writeFile(wb, filename);
                showNotification('Excel report exported successfully!', 'success');
            } else {
                // Export to CSV
                const csv = convertToCSV(data, headers);

                // Generate filename with filter info
                let filename = `Filtered_Report_${new Date().toISOString().split('T')[0]}`;
                if (filters.fromDate) filename += `_From_${filters.fromDate}`;
                if (filters.toDate) filename += `_To_${filters.toDate}`;
                if (filters.loanType !== 'all') filename += `_${filters.loanType}`;
                if (filters.pfNumber) filename += `_PF_${filters.pfNumber}`;
                filename += '.csv';

                downloadCSV(filename, csv);
            }
        } catch (error) {
            showNotification('Error exporting filtered report: ' + error.message, 'error');
        }
    }

    // ==========================================
    // EXPORT FUNCTIONS
    // ==========================================
    // Helper function to convert data to CSV format (Excel compatible)
    function convertToCSV(data, headers) {
        let csv = headers.join(',') + '\n';
        data.forEach(row => {
            const values = row.map((value, index) => {
                const stringValue = String(value);

                // For Application ID and Account Number columns, add tab character to preserve formatting
                if (index === 0 || (headers[index] && (headers[index].includes('Application ID') ||
                        headers[index].includes('Account Number')))) {
                    // Use tab character prefix to force Excel to treat as text
                    return `"${stringValue}\t"`;
                }

                // Escape quotes and wrap in quotes if contains comma, quotes, or newlines
                if (stringValue.includes(',') || stringValue.includes('"') || stringValue.includes(
                        '\n')) {
                    return `"${stringValue.replace(/"/g, '""')}"`;
                }

                return stringValue;
            });
            csv += values.join(',') + '\n';
        });
        return csv;
    }

    // Helper function to download CSV file
    function downloadCSV(filename, csvContent) {
        const BOM = '\uFEFF'; // UTF-8 BOM for Excel compatibility
        const blob = new Blob([BOM + csvContent], {
            type: 'text/csv;charset=utf-8;'
        });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();

        showNotification(
            `${filename} exported successfully! Note: In Excel, adjust column widths to view all data properly.`,
            'success');
    }

    // 1. EXPORT OVERALL SUMMARY
    async function exportOverallSummary() {
        try {
            const loans = await getAllLoans();
            const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
            const closedLoans = loans.filter(l => l.status === 'closed');

            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'ID Number',
                'Loan Type',
                'Original Amount (KES)',
                'Total Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Status',
                'Application Date',
                'Dispatch Date',
                'Bank Name',
                'Account Number'
            ];

            const data = [];

            for (const loan of [...activeLoans, ...closedLoans]) {
                const balanceInfo = await recalculateLoanBalance(loan);
                data.push([
                    loan.application_id || 'N/A',
                    loan.full_name || 'N/A',
                    loan.pf_number || 'N/A',
                    loan.id_number || 'N/A',
                    loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) :
                    'N/A',
                    formatCurrency(loan.loan_amount || 0),
                    formatCurrency(balanceInfo.totalPaid || 0),
                    formatCurrency(balanceInfo.principalPaid || 0),
                    formatCurrency(balanceInfo.interestPaid || 0),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue || 0) : '0',
                    loan.status ? loan.status.toUpperCase() : 'UNKNOWN',
                    formatDate(loan.application_date) || 'N/A',
                    formatDate(loan.dispatch_date) || 'N/A',
                    loan.bank_name || 'N/A',
                    loan.bank_account_number || 'N/A'
                ]);
            }

            const csv = convertToCSV(data, headers);
            const filename = `Overall_Summary_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting overall summary: ' + error.message, 'error');
        }
    }

    // 2. EXPORT MORTGAGE LOANS
    async function exportMortgageLoans() {
        try {
            const loans = await getAllLoans();
            const mortgageLoans = loans.filter(l => l.loan_type === 'mortgage');

            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'ID Number',
                'Original Amount (KES)',
                'Property Location',
                'Property Value (KES)',
                'Total Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Status',
                'Payment Duration (Years)',
                'Application Date',
                'Dispatch Date',
                'Bank Name',
                'Account Number'
            ];

            const data = [];

            for (const loan of mortgageLoans) {
                const balanceInfo = await recalculateLoanBalance(loan);
                data.push([
                    loan.application_id || 'N/A',
                    loan.full_name || 'N/A',
                    loan.pf_number || 'N/A',
                    loan.id_number || 'N/A',
                    formatCurrency(loan.loan_amount || 0),
                    loan.property_location || 'N/A',
                    loan.property_value ? formatCurrency(loan.property_value) : 'N/A',
                    formatCurrency(balanceInfo.totalPaid || 0),
                    formatCurrency(balanceInfo.principalPaid || 0),
                    formatCurrency(balanceInfo.interestPaid || 0),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue || 0) : '0',
                    loan.status ? loan.status.toUpperCase() : 'UNKNOWN',
                    loan.payment_duration || 'N/A',
                    formatDate(loan.application_date) || 'N/A',
                    formatDate(loan.dispatch_date) || 'N/A',
                    loan.bank_name || 'N/A',
                    loan.bank_account_number || 'N/A'
                ]);
            }

            const csv = convertToCSV(data, headers);
            const filename = `Mortgage_Loans_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting mortgage loans: ' + error.message, 'error');
        }
    }

    // 3. EXPORT CAR LOANS
    async function exportCarLoans() {
        try {
            const loans = await getAllLoans();
            const carLoans = loans.filter(l => l.loan_type === 'car');

            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'ID Number',
                'Original Amount (KES)',
                'Car Type',
                'Car Value (KES)',
                'Total Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Status',
                'Payment Duration (Years)',
                'Application Date',
                'Dispatch Date',
                'Bank Name',
                'Account Number'
            ];

            const data = [];

            for (const loan of carLoans) {
                const balanceInfo = await recalculateLoanBalance(loan);

                // Determine car info - prioritize whichever field has data
                let carInfo = 'Not Specified';
                if (loan.car_model && loan.car_model !== 'N/A') {
                    carInfo = loan.car_model;
                } else if (loan.car_type && loan.car_type !== 'N/A') {
                    carInfo = loan.car_type;
                }

                // Dispatch date handling
                let dispatchDate = 'Pending Dispatch';
                if (loan.dispatch_date && loan.dispatch_date !== null) {
                    dispatchDate = formatDate(loan.dispatch_date);
                }

                data.push([
                    formatApplicationId(loan.application_id),
                    loan.full_name || 'N/A',
                    loan.pf_number || 'N/A',
                    loan.id_number || 'N/A',
                    formatCurrency(loan.loan_amount || 0),
                    carInfo,
                    loan.car_value ? formatCurrency(loan.car_value) : 'Not Specified',
                    formatCurrency(balanceInfo.totalPaid),
                    formatCurrency(balanceInfo.principalPaid),
                    formatCurrency(balanceInfo.interestPaid),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) : '0',
                    loan.status ? loan.status.toUpperCase() : 'UNKNOWN',
                    loan.payment_duration || 'N/A',
                    formatDate(loan.application_date) || 'N/A',
                    dispatchDate,
                    loan.bank_name || 'Not Specified',
                    formatAccountNumber(loan.bank_account_number)
                ]);
            }

            const csv = convertToCSV(data, headers);
            const filename = `Car_Loans_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting car loans: ' + error.message, 'error');
        }
    }

    // 4. EXPORT PAYMENT SUMMARY
    async function exportPaymentSummary() {
        try {
            const loans = await getAllLoans();
            const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
            const closedLoans = loans.filter(l => l.status === 'closed');

            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'Loan Type',
                'Original Amount (KES)',
                'Total Amount Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Number of Payments',
                'Last Payment Date',
                'Months Since Last Payment',
                'Payment Status'
            ];

            const data = [];

            for (const loan of [...activeLoans, ...closedLoans]) {
                const balanceInfo = await recalculateLoanBalance(loan);
                const paymentStatus = loan.status === 'closed' ? 'FULLY PAID' :
                    balanceInfo.totalPaid > 0 ? 'IN PROGRESS' : 'NO PAYMENTS';

                data.push([
                    loan.application_id || 'N/A',
                    loan.full_name || 'N/A',
                    loan.pf_number || 'N/A',
                    loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) :
                    'N/A',
                    formatCurrency(loan.loan_amount || 0),
                    formatCurrency(balanceInfo.totalPaid || 0),
                    formatCurrency(balanceInfo.principalPaid || 0),
                    formatCurrency(balanceInfo.interestPaid || 0),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue || 0) : '0',
                    loan.payments ? loan.payments.length : 0,
                    balanceInfo.lastPaymentDate || 'N/A',
                    balanceInfo.monthsSinceLastPayment || 0,
                    paymentStatus
                ]);
            }

            const csv = convertToCSV(data, headers);
            const filename = `Payment_Summary_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting payment summary: ' + error.message, 'error');
        }
    }

    // 5. EXPORT COMPLETE FINANCIAL REPORT
    function exportFinancialReport() {
        // Create CSV content
        let csv = 'Financial Report - Generated on: ' + new Date().toLocaleString() + '\n\n';

        // Extract data from the reports view
        const reportsDiv = document.getElementById('reportsContent');
        if (!reportsDiv) {
            showNotification('No report data available to export', 'error');
            return;
        }

        // Get all tables
        const tables = reportsDiv.querySelectorAll('table');

        tables.forEach((table, index) => {
            if (index === 0) {
                csv += '\nYearly Breakdown\n';
            } else if (index === 1) {
                csv += '\n\nActive Loans Details\n';
            }

            const rows = table.querySelectorAll('tr');
            rows.forEach(row => {
                const cols = row.querySelectorAll('th, td');
                const rowData = [];
                cols.forEach(col => {
                    let text = col.innerText.replace(/"/g, '""');
                    // Remove extra whitespace and newlines
                    text = text.replace(/\s+/g, ' ').trim();
                    rowData.push(`"${text}"`);
                });
                csv += rowData.join(',') + '\n';
            });
        });

        // Download CSV
        const blob = new Blob([csv], {
            type: 'text/csv'
        });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `financial_report_${new Date().toISOString().split('T')[0]}.csv`;
        link.click();

        showNotification('Financial report exported successfully!', 'success');
    }

    // PDF EXPORT FUNCTIONS
    function exportLoanDetails() {
        const {
            jsPDF
        } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');

        // Get the current loan data from the modal
        const loanDetailsContent = document.getElementById('loanDetailsContent');
        const loaneeName = loanDetailsContent.querySelector('p:nth-child(2)')?.textContent.replace(
                'Full Name: ', '') ||
            'Unknown';

        // Set up PDF dimensions
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        let yPosition = 20;

        // Helper function to add text with word wrap
        function addText(text, fontSize = 12, isBold = false) {
            doc.setFontSize(fontSize);
            doc.setFont(isBold ? 'helvetica' : 'helvetica', isBold ? 'bold' : 'normal');

            const lines = doc.splitTextToSize(text, pageWidth - 40);
            lines.forEach(line => {
                if (yPosition > pageHeight - 20) {
                    doc.addPage();
                    yPosition = 20;
                }
                doc.text(line, 20, yPosition);
                yPosition += 7;
            });
            return yPosition;
        }

        // Header
        doc.setFontSize(20);
        doc.setFont('helvetica', 'bold');
        doc.text('Loan Details Report', pageWidth / 2, 15, {
            align: 'center'
        });

        yPosition = 30;

        // Extract loan information
        const loanInfo = extractLoanInfo(loanDetailsContent);

        // Add loanee information section
        addText('Loanee Information', 14, true);
        yPosition += 3;

        // Create a simple table for loanee information
        const loaneeData = [
            ['Full Name:', loanInfo.fullName],
            ['ID Number:', loanInfo.idNumber],
            ['PF Number:', loanInfo.pfNumber],
            ['Email:', loanInfo.email],
            ['Loan Type:', loanInfo.loanType],
            ['Application Date:', loanInfo.applicationDate],
            ['Status:', loanInfo.status],
            ['Payment Duration:', loanInfo.paymentDuration],
            ['Basic Salary:', loanInfo.basicSalary]
        ];

        yPosition = createSimpleTable(doc, loaneeData, yPosition, pageWidth, pageHeight);

        // Add loan financial status section
        yPosition += 10;
        addText('Current Loan Financial Status', 14, true);
        yPosition += 3;

        // Create a table for financial status
        const financialData = [
            ['Original Loan Amount:', loanInfo.originalAmount],
            ['Total Paid:', loanInfo.totalPaid],
            ['Principal Paid:', loanInfo.principalPaid],
            ['Interest Paid:', loanInfo.interestPaid],
            ['Current Principal Balance:', loanInfo.currentPrincipal],
            ['Accrued Interest:', loanInfo.accruedInterest],
            ['Total Balance Due:', loanInfo.totalBalanceDue]
        ];

        yPosition = createSimpleTable(doc, financialData, yPosition, pageWidth, pageHeight);

        // Add payment history if available
        if (loanInfo.payments && loanInfo.payments.length > 0) {
            yPosition += 10;
            addText('Payment History', 14, true);
            yPosition += 5;

            // Create a table for payment history
            yPosition = createPaymentTable(doc, loanInfo.payments, yPosition, pageWidth, pageHeight);
        }

        // Add footer
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Page ${i} of ${pageCount}`, pageWidth / 2, pageHeight - 10, {
                align: 'center'
            });
            doc.text(`Generated on ${new Date().toLocaleString()}`, pageWidth / 2, pageHeight - 5, {
                align: 'center'
            });
        }

        // Save the PDF
        doc.save(
            `Loan_Details_${loaneeName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`);

        showNotification('Loan details exported successfully!', 'success');
    }

    // Helper function to extract loan information from the modal
    function extractLoanInfo(contentElement) {
        const info = {};

        // Extract loanee information
        info.fullName = contentElement.querySelector('p:nth-child(2)')?.textContent.replace('Full Name: ', '') || 'N/A';
        info.idNumber = contentElement.querySelector('p:nth-child(3)')?.textContent.replace('ID Number: ', '') || 'N/A';
        info.pfNumber = contentElement.querySelector('p:nth-child(4)')?.textContent.replace('PF Number: ', '') || 'N/A';
        info.email = contentElement.querySelector('p:nth-child(5)')?.textContent.replace('Email: ', '') || 'N/A';

        // Extract loan details
        const loanDetailsSection = Array.from(contentElement.querySelectorAll('h3')).find(h => h.textContent.includes(
            'Loan Details'));
        if (loanDetailsSection) {
            let nextElement = loanDetailsSection.nextElementSibling;
            while (nextElement && nextElement.tagName !== 'H3') {
                if (nextElement.tagName === 'P') {
                    const text = nextElement.textContent;
                    if (text.includes('Loan Type:')) info.loanType = text.replace('Loan Type: ', '');
                    else if (text.includes('Application Date:')) info.applicationDate = text.replace(
                        'Application Date: ', '');
                    else if (text.includes('Status:')) info.status = text.replace('Status: ', '');
                    else if (text.includes('Payment Duration:')) info.paymentDuration = text.replace(
                        'Payment Duration: ', '');
                    else if (text.includes('Basic Salary:')) info.basicSalary = text.replace('Basic Salary: ', '');
                }
                nextElement = nextElement.nextElementSibling;
            }
        }

        // Extract financial status
        const financialSection = Array.from(contentElement.querySelectorAll('h3')).find(h => h.textContent.includes(
            'Current Loan Financial Status'));
        if (financialSection) {
            const table = financialSection.nextElementSibling;
            if (table && table.tagName === 'TABLE') {
                const rows = table.querySelectorAll('tr');
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length === 2) {
                        const label = cells[0].textContent.replace(':', '');
                        const value = cells[1].textContent;

                        if (label.includes('Original Loan Amount')) info.originalAmount = value;
                        else if (label.includes('Total Paid')) info.totalPaid = value;
                        else if (label.includes('Principal Paid')) info.principalPaid = value;
                        else if (label.includes('Interest Paid')) info.interestPaid = value;
                        else if (label.includes('Current Principal Balance')) info.currentPrincipal = value;
                        else if (label.includes('Accrued Interest')) info.accruedInterest = value;
                        else if (label.includes('Total Balance Due')) info.totalBalanceDue = value;
                    }
                });
            }
        }

        // Extract payment history
        const paymentSection = Array.from(contentElement.querySelectorAll('h3')).find(h => h.textContent.includes(
            'Payment History'));
        if (paymentSection) {
            const tableContainer = paymentSection.nextElementSibling;
            if (tableContainer && tableContainer.querySelector('table')) {
                const table = tableContainer.querySelector('table');
                const rows = table.querySelectorAll('tbody tr');

                info.payments = [];
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 8) {
                        info.payments.push({
                            number: cells[0].textContent,
                            date: cells[1].textContent,
                            amount: cells[2].textContent,
                            interest: cells[3].textContent,
                            principal: cells[4].textContent,
                            source: cells[5].textContent,
                            refId: cells[6].textContent,
                            recordedBy: cells[7].textContent
                        });
                    }
                });
            }
        }

        return info;
    }

    // Helper function to create a simple table
    function createSimpleTable(doc, data, startY, pageWidth, pageHeight) {
        let yPosition = startY;
        data.forEach(row => {
            // Check if we need a new page
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;
            }

            // Draw row data
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(11);
            doc.text(row[0], 20, yPosition);

            doc.setFont('helvetica', 'normal');
            const valueText = doc.splitTextToSize(row[1], pageWidth * 0.6);
            valueText.forEach(line => {
                doc.text(line, pageWidth * 0.4, yPosition);
                yPosition += 7;
            });

            yPosition += 8;
        });
        return yPosition;
    }

    // Helper function to create a payment history table
    function createPaymentTable(doc, payments, startY, pageWidth, pageHeight) {
        let yPosition = startY;

        // Table headers
        const headers = ['#', 'Date', 'Amount', 'Interest', 'Principal', 'Source', 'Ref ID', 'Recorded By'];
        const columnWidths = [0.08, 0.12, 0.12, 0.12, 0.12, 0.12, 0.12, 0.2];

        // Draw headers
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);

        let xPos = 15;
        headers.forEach((header, index) => {
            doc.text(header, xPos, yPosition);
            xPos += pageWidth * columnWidths[index];
        });

        yPosition += 10;

        // Draw a line under headers
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);
        yPosition += 3;

        // Draw table rows
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9);

        payments.forEach(payment => {
            // Check if we need a new page
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;

                // Repeat headers on new page
                xPos = 15;
                doc.setFont('helvetica', 'bold');
                headers.forEach((header, index) => {
                    doc.text(header, xPos, yPosition);
                    xPos += pageWidth * columnWidths[index];
                });

                yPosition += 10;
                doc.line(15, yPosition, pageWidth - 15, yPosition);
                yPosition += 3;

                doc.setFont('helvetica', 'normal');
            }

            // Draw row data
            xPos = 15;
            const rowData = [
                payment.number,
                payment.date,
                payment.amount,
                payment.interest,
                payment.principal,
                payment.source,
                payment.refId,
                payment.recordedBy
            ];

            rowData.forEach((data, index) => {
                // Clean up the data
                let cleanData = data.replace(/KES/g, '').replace(/,/g, '');

                // Truncate if too long
                if (cleanData.length > 15) {
                    cleanData = cleanData.substring(0, 12) + '...';
                }

                doc.text(cleanData, xPos, yPosition);
                xPos += pageWidth * columnWidths[index];
            });

            yPosition += 6;
        });

        // Draw a line at the bottom of the table
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);

        return yPosition + 5;
    }

    function exportAmortization() {
        const {
            jsPDF
        } = window.jspdf;
        const doc = new jsPDF('l', 'mm', 'a4'); // Landscape orientation for better table fit

        // Get amortization data
        const amortInfo = document.getElementById('amortInfo');
        const amortTableBody = document.getElementById('amortTableBody');
        const loaneeName = amortInfo.querySelector('strong')?.textContent.split(':')[1]?.trim() || 'Unknown';

        // Set up PDF dimensions
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        let yPosition = 20;

        // Header
        doc.setFontSize(16);
        doc.setFont('helvetica', 'bold');
        doc.text('Payment Schedule & Amortization Report', pageWidth / 2, 15, {
            align: 'center'
        });

        yPosition = 30;

        // Add summary information
        const summaryText = amortInfo.innerText;
        const summaryLines = doc.splitTextToSize(summaryText, pageWidth - 40);
        summaryLines.forEach(line => {
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;
            }
            doc.text(line, 20, yPosition);
            yPosition += 7;
        });

        yPosition += 10;

        // Create a proper table for the amortization schedule
        const tableHeaders = ['#', 'Due Date', 'Expected Payment', 'Actual Payment', 'Expected Interest',
            'Actual Interest', 'Principal', 'Balance', 'Status'
        ];
        const columnWidths = [15, 30, 35, 35, 35, 30, 35, 25];

        // Draw table headers
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);

        let xPos = 15;
        tableHeaders.forEach((header, index) => {
            doc.text(header, xPos, yPosition);
            xPos += columnWidths[index];
        });

        yPosition += 8;

        // Draw a line under headers
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);
        yPosition += 3;

        // Draw table rows
        const rows = amortTableBody.querySelectorAll('tr');
        rows.forEach((row, rowIndex) => {
            // Check if we need a new page
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;

                // Repeat headers on new page
                xPos = 15;
                doc.setFont('helvetica', 'bold');
                tableHeaders.forEach((header, index) => {
                    doc.text(header, xPos, yPosition);
                    xPos += columnWidths[index];
                });

                yPosition += 8;
                doc.line(15, yPosition, pageWidth - 15, yPosition);
                yPosition += 3;
            }

            const cells = row.querySelectorAll('td');
            xPos = 15;
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);

            cells.forEach((cell, cellIndex) => {
                if (cellIndex < columnWidths.length) {
                    let text = cell.textContent.trim();
                    // Clean up currency formatting and status text
                    text = text.replace(/KES/g, '').replace(/\s+/g, ' ');

                    // Truncate if too long
                    if (text.length > 20) {
                        text = text.substring(0, 17) + '...';
                    }

                    doc.text(text, xPos, yPosition);
                    xPos += columnWidths[cellIndex];
                }
            });

            yPosition += 7;
        });

        // Draw a line at the bottom of the table
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);

        // Footer
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Page ${i} of ${pageCount}`, pageWidth / 2, pageHeight - 10, {
                align: 'center'
            });
            doc.text(`Generated on ${new Date().toLocaleString()}`, pageWidth / 2, pageHeight - 5, {
                align: 'center'
            });
        }

        // Save the PDF
        doc.save(
            `Amortization_Schedule_${loaneeName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`
        );

        showNotification('Amortization schedule exported successfully!', 'success');
    }

    // ==========================================
    // AUTHENTICATION
    // ==========================================
    function logoutAccountant() {
        // Clear the session storage
        sessionStorage.removeItem('loginTime');
        sessionStorage.removeItem('lastActivityTime');

        // Session data will be cleared on the server side
        // No need to clear localStorage

        // Show notification
        showNotification('Logging out...', 'success', 2000);

        // Call logout (but don't wait for response in case of network issues)
        fetch('<?php echo $_SERVER["PHP_SELF"]; ?>?action=logout', {
            method: 'GET'
        }).catch(error => {
            //console.error('Logout error:', error);
        }).finally(() => {
            // Clear app state
            APP_STATE.currentUser = null;
            APP_STATE.csrfToken = null;

            // Redirect after a brief delay
            setTimeout(() => {
                window.location.href = '/loan-system/index.php?logout=success';
            }, 500);
        });
    }

    // ==========================================
    // PROFILE BADGE FUNCTIONS
    // ==========================================
    function initializeProfileBadge() {
        // Profile badge click handler
        DOM_ELEMENTS.profileBadge.addEventListener('click', (e) => {
            e.stopPropagation();
            DOM_ELEMENTS.profileDropdown.classList.toggle('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', () => {
            DOM_ELEMENTS.profileDropdown.classList.remove('active');
        });

        // Logout button in profile dropdown
        document.getElementById('logoutBtn').addEventListener('click', () => {
            if (confirm('Are you sure you want to logout?')) {
                logoutAccountant();
            }
        });

        // Logout button in sidebar
        document.getElementById('logoutBtnSidebar').addEventListener('click', () => {
            if (confirm('Are you sure you want to logout?')) {
                logoutAccountant();
            }
        });
    }

    // ==========================================
    // SIDEBAR TOGGLE FUNCTION
    // ==========================================
    function initializeSidebar() {
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const main = document.querySelector('.main');

        // Add state management
        let sidebarOpen = true;
        let sidebarCollapsed = false;

        // Set initial state based on screen size
        function updateSidebar() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('open', 'collapsed');
                main.style.paddingLeft = '15px';
                sidebarOpen = false;
                sidebarCollapsed = false;
            } else {
                sidebar.classList.remove('collapsed');
                sidebar.classList.add('open');
                main.style.paddingLeft = '260px';
                sidebarOpen = true;
                sidebarCollapsed = false;
            }
        }

        // Toggle on hamburger click
        menuToggle.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                // Mobile behavior - toggle sidebar visibility
                sidebarOpen = !sidebarOpen;
                if (sidebarOpen) {
                    sidebar.classList.add('open');
                } else {
                    sidebar.classList.remove('open');
                }
            } else {
                // Desktop behavior - toggle collapse
                if (!sidebarCollapsed) {
                    sidebar.classList.add('collapsed');
                    main.style.paddingLeft = '90px';
                    sidebarCollapsed = true;
                } else {
                    sidebar.classList.remove('collapsed');
                    main.style.paddingLeft = '260px';
                    sidebarCollapsed = false;
                }
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (event) => {
            if (window.innerWidth <= 768 &&
                sidebarOpen &&
                !sidebar.contains(event.target) &&
                !menuToggle.contains(event.target)) {
                sidebar.classList.remove('open');
                sidebarOpen = false;
            }
        });

        // Update on resize
        window.addEventListener('resize', updateSidebar);

        // Initial check
        updateSidebar();
    }

   


    // FIXED: Apply for loan function with proper error handling
    function applyForLoan() {
        const confirmSwitch = confirm("You're about to switch to the loan application portal. Continue?");

        if (!confirmSwitch) {
            return;
        }

        // Show loading state
        showNotification('Switching to loanee portal...', 'info');

        // Create loading overlay
        const loadingOverlay = document.createElement('div');
        loadingOverlay.id = 'portal-switch-overlay';
        loadingOverlay.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.9);z-index:9999;display:flex;justify-content:center;align-items:center;flex-direction:column;';

        const spinner = document.createElement('div');
        spinner.style.cssText =
            'border:5px solid #f3f3f3;border-top:5px solid #4a6b4a;border-radius:50%;width:50px;height:50px;animation:spin 1s linear infinite;';

        const loadingText = document.createElement('p');
        loadingText.textContent = 'Switching to loanee portal...';
        loadingText.style.cssText = 'margin-top:20px;font-weight:bold;color:#4a6b4a;';

        loadingOverlay.appendChild(spinner);
        loadingOverlay.appendChild(loadingText);
        document.body.appendChild(loadingOverlay);

        // Get current portal name
        const currentPortal = window.location.pathname.includes('admin.php') ? 'admin' :
            window.location.pathname.includes('cashier.php') ? 'cashier' :
            window.location.pathname.includes('accountant.php') ? 'accountant' : 'index';

        // Redirect with proper parameters
        setTimeout(() => {
            window.location.href = `loanee.php?from=${currentPortal}&action=switch_portal`;
        }, 500);
    }

    // FIXED: Return to staff portal function
    function returnToStaffPortal() {
        if (!confirm('Return to your staff portal? Any unsaved loan application data will be lost.')) {
            return;
        }

        // Show loading notification
        showNotification('Returning to staff portal...', 'info');

        // Create loading overlay
        const loadingOverlay = document.createElement('div');
        loadingOverlay.id = 'portal-return-overlay';
        loadingOverlay.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.9);z-index:9999;display:flex;justify-content:center;align-items:center;flex-direction:column;';

        const spinner = document.createElement('div');
        spinner.style.cssText =
            'border:5px solid #f3f3f3;border-top:5px solid #4a6b4a;border-radius:50%;width:50px;height:50px;animation:spin 1s linear infinite;';

        const loadingText = document.createElement('p');
        loadingText.textContent = 'Returning to staff portal...';
        loadingText.style.cssText = 'margin-top:20px;font-weight:bold;color:#4a6b4a;';

        loadingOverlay.appendChild(spinner);
        loadingOverlay.appendChild(loadingText);
        document.body.appendChild(loadingOverlay);

        // Redirect after brief delay
        setTimeout(() => {
            window.location.href = 'loanee.php?action=return_to_staff';
        }, 500);
    }

    // Add CSS animation for spinner
    const style = document.createElement('style');
    style.textContent = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
    document.head.appendChild(style);

    // Make functions globally available
    window.applyForLoan = applyForLoan;
    window.returnToStaffPortal = returnToStaffPortal;

    // Add the missing showLoading function
    function showLoading() {
        // Create loading overlay if it doesn't exist
        let loadingOverlay = document.getElementById('loading-overlay');

        if (!loadingOverlay) {
            loadingOverlay = document.createElement('div');
            loadingOverlay.id = 'loading-overlay';
            loadingOverlay.style.position = 'fixed';
            loadingOverlay.style.top = '0';
            loadingOverlay.style.left = '0';
            loadingOverlay.style.width = '100%';
            loadingOverlay.style.height = '100%';
            loadingOverlay.style.backgroundColor = 'rgba(255, 255, 255, 0.8)';
            loadingOverlay.style.zIndex = '9999';
            loadingOverlay.style.display = 'flex';
            loadingOverlay.style.justifyContent = 'center';
            loadingOverlay.style.alignItems = 'center';
            loadingOverlay.style.flexDirection = 'column';

            const spinner = document.createElement('div');
            spinner.style.border = '5px solid #f3f3f3';
            spinner.style.borderTop = '5px solid var(--blue)';
            spinner.style.borderRadius = '50%';
            spinner.style.width = '50px';
            spinner.style.height = '50px';
            spinner.style.animation = 'spin 1s linear infinite';

            const loadingText = document.createElement('p');
            loadingText.textContent = 'Redirecting to loan application portal...';
            loadingText.style.marginTop = '20px';
            loadingText.style.fontWeight = 'bold';
            loadingText.style.color = 'var(--text-primary)';

            loadingOverlay.appendChild(spinner);
            loadingOverlay.appendChild(loadingText);
            document.body.appendChild(loadingOverlay);

            // Add spinner animation if not already in CSS
            if (!document.getElementById('spinner-style')) {
                const style = document.createElement('style');
                style.id = 'spinner-style';
                style.textContent = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
                document.head.appendChild(style);
            }
        } else {
            loadingOverlay.style.display = 'flex';
        }
    }

    // Export loans function
    async function exportLoans(filter = 'all') {
        try {
            // Show loading notification
            showNotification('Preparing export...', 'info');

            // Create a temporary link element for download
            const link = document.createElement('a');

            // Set the URL to the API endpoint with the filter parameter
            link.href = `${API_BASE_URL}?api&endpoint=export&filter=${filter}`;

            // Set the download attribute with a filename
            link.download = `loans_export_${filter}_${new Date().toISOString().split('T')[0]}.csv`;

            // Temporarily add the link to the DOM, click it, and remove it
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showNotification('Export completed successfully!', 'success');
        } catch (error) {
            showNotification('Error exporting loans: ' + error.message, 'error');
        }
    }

    // Export payments function
    async function exportPaymentsData() {
        try {
            // Show loading notification
            showNotification('Preparing payment export...', 'info');

            // Create a temporary link element for download
            const link = document.createElement('a');

            // Set the URL to the API endpoint with the type parameter
            link.href = `${API_BASE_URL}?api&endpoint=export&type=payments`;

            // Set the download attribute with a filename
            link.download = `payments_export_${new Date().toISOString().split('T')[0]}.csv`;

            // Temporarily add the link to the DOM, click it, and remove it
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showNotification('Payment export completed successfully!', 'success');
        } catch (error) {
            showNotification('Error exporting payments: ' + error.message, 'error');
        }
    }

    /**
     * Load external script dynamically
     */
    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    // ==========================================
    // THEME MANAGEMENT (Similar to Cashier Portal)
    // ==========================================

    /**
     * Initialize theme selector with proper event handlers
     */
    function initializeThemeSelector() {
        const themeButtons = document.querySelectorAll('.theme-option');

        // Load saved theme from LocalStorage
        const savedTheme = localStorage.getItem('accountant_theme_preference') || 'light';
        applyTheme(savedTheme);

        // Add click listeners
        themeButtons.forEach(button => {
            button.addEventListener('click', function() {
                const theme = this.getAttribute('data-theme');

                // Remove active class from all buttons
                themeButtons.forEach(btn => btn.classList.remove('active'));

                // Add active class to clicked button
                this.classList.add('active');

                // Apply theme
                applyTheme(theme);

                // Save to LocalStorage (Persists across page reloads)
                localStorage.setItem('accountant_theme_preference', theme);

                // Optional: Show notification
                showNotification(`Theme changed to ${theme.charAt(0).toUpperCase() + theme.slice(1)}`,
                    'success', 2000);
            });
        });
    }

    /**
     * Apply theme to the document
     * @param {string} theme - Theme name ('light' or 'dark')
     */
    function applyTheme(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }

        // Update active state of buttons
        document.querySelectorAll('.theme-option').forEach(btn => {
            if (btn.getAttribute('data-theme') === theme) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    // ==========================================
    // SESSION TIMEOUT WARNING SYSTEM
    // ==========================================

    /**
     * Enhanced session management with visual warnings
     */
    class SessionManager {
        constructor() {
            this.sessionDuration = 4 * 60 * 60 * 1000; // 4 hours in ms (server idle limit)
            this.warningTime = 5 * 60 * 1000; // 5 minutes warning
            this.checkInterval = 60 * 1000; // Check every minute
            this.loginTime = null;
            this.warningShown = false;
            this.timeoutTimer = null;
            this.warningTimer = null;
            this.checkTimer = null;

            this.init();
        }

        init() {
            // Get or set login time
            this.loginTime = sessionStorage.getItem('loginTime');

            if (!this.loginTime) {
                this.loginTime = Date.now().toString();
                sessionStorage.setItem('loginTime', this.loginTime);
            }

            // Update last activity
            sessionStorage.setItem('lastActivityTime', Date.now().toString());

            // Set up timers
            this.setupTimers();

            // Track user activity
            this.trackActivity();

            // Periodic session checks
            this.startPeriodicChecks();
        }

        setupTimers() {
            // The server owns expiry and measures inactivity. A client-side
            // login-time countdown incorrectly logged out active users.
            this.timeoutTimer = null;
            this.warningTimer = null;
        }

        showWarning() {
            if (this.warningShown) return;
            this.warningShown = true;

            const currentTime = Date.now();
            const timeElapsed = currentTime - parseInt(this.loginTime);
            const remainingTime = Math.max(0, this.sessionDuration - timeElapsed);
            const remainingMinutes = Math.floor(remainingTime / 60000);

            // Create warning modal
            const modal = document.createElement('div');
            modal.id = 'sessionWarningModal';
            modal.className = 'modal';
            modal.style.display = 'block';
            modal.style.zIndex = '10000';

            modal.innerHTML = `
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header" style="background: #ff9800; color: white;">
                    <h2 style="margin: 0; color: white; border: none; padding: 0;">
                        <i data-lucide="alert-triangle" class="icon-sm"></i> Session Expiring Soon
                    </h2>
                </div>
                <div class="modal-body">
                    <p style="font-size: 1.1rem; margin-bottom: 15px;">
                        Your session will expire in <strong id="timeRemaining">${remainingMinutes}</strong> minute(s).
                    </p>
                    <p style="color: #666;">
                        To continue working, please click "Stay Logged In" below. 
                        Otherwise, you will be automatically logged out.
                    </p>
                    <div style="margin-top: 20px; padding: 15px; background: #fff3e0; border-left: 4px solid #ff9800; border-radius: 4px;">
                        <strong>💡 Tip:</strong> Any unsaved export operations may fail after session expires.
                    </div>
                </div>
                <div class="modal-footer">
                    <button id="extendSession" class="btn-primary" style="padding: 12px 24px; font-size: 1rem;">
                        <i data-lucide="refresh-cw" class="icon-sm"></i> Stay Logged In
                    </button>
                    <button id="logoutNow" class="btn-secondary">
                        <i data-lucide="log-out" class="icon-sm"></i> Logout Now
                    </button>
                </div>
            </div>
        `;

            document.body.appendChild(modal);

            // Update countdown every second
            const countdownInterval = setInterval(() => {
                const now = Date.now();
                const elapsed = now - parseInt(this.loginTime);
                const remaining = Math.max(0, this.sessionDuration - elapsed);
                const minutes = Math.floor(remaining / 60000);
                const seconds = Math.floor((remaining % 60000) / 1000);

                const timeDisplay = document.getElementById('timeRemaining');
                if (timeDisplay) {
                    timeDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                }

                if (remaining <= 0) {
                    clearInterval(countdownInterval);
                    modal.remove();
                    this.handleSessionExpiry();
                }
            }, 1000);

            // Event listeners
            document.getElementById('extendSession').addEventListener('click', () => {
                clearInterval(countdownInterval);
                modal.remove();
                this.extendSession();
            });

            document.getElementById('logoutNow').addEventListener('click', () => {
                clearInterval(countdownInterval);
                modal.remove();
                this.logout();
            });

            // Prevent closing by clicking outside
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    // Don't close on outside click for security
                    showNotification('Please choose an option to continue', 'warning');
                }
            });
        }

        async extendSession() {
            try {
                // Verify session is still valid on server
                const response = await fetch(`${API_BASE_URL}?api&endpoint=session_check`);

                if (response.ok) {
                    const data = await response.json();

                    if (data.success) {
                        // Reset timers
                        this.loginTime = Date.now().toString();
                        sessionStorage.setItem('loginTime', this.loginTime);
                        sessionStorage.setItem('lastActivityTime', this.loginTime);

                        this.warningShown = false;

                        // Clear existing timers
                        if (this.timeoutTimer) clearTimeout(this.timeoutTimer);
                        if (this.warningTimer) clearTimeout(this.warningTimer);

                        // Setup new timers
                        this.setupTimers();

                        showNotification('Session extended successfully! <i data-lucide="check-circle" class="icon-sm"></i>', 'success');
                    } else {
                        throw new Error('Session validation failed');
                    }
                } else {
                    throw new Error('Server error');
                }
            } catch (error) {
                showNotification('Failed to extend session. Please log in again.', 'error');
                setTimeout(() => this.logout(), 2000);
            }
        }

        handleSessionExpiry() {
            showNotification('Your session has expired. Redirecting to login...', 'error', 5000);

            // Clean up
            sessionStorage.removeItem('loginTime');
            sessionStorage.removeItem('lastActivityTime');

            setTimeout(() => {
                window.location.href = 'index.php?error=session_expired';
            }, 2000);
        }

        logout() {
            sessionStorage.removeItem('loginTime');
            sessionStorage.removeItem('lastActivityTime');

            showNotification('Logging out...', 'info');

            setTimeout(() => {
                window.location.href = 'Accountant.php?action=logout';
            }, 500);
        }

        trackActivity() {
            const events = ['click', 'keypress', 'scroll', 'mousemove'];

            events.forEach(eventType => {
                document.addEventListener(eventType, () => {
                    sessionStorage.setItem('lastActivityTime', Date.now().toString());
                }, {
                    passive: true
                });
            });
        }

        async startPeriodicChecks() {
            this.checkTimer = setInterval(async () => {
                try {
                    const response = await fetch(`${API_BASE_URL}?api&endpoint=session_check`);

                    if (!response.ok || response.status === 401) {
                        clearInterval(this.checkTimer);
                        this.handleSessionExpiry();
                    }
                } catch (error) {
                    console.error('Session check failed:', error);
                }
            }, this.checkInterval);
        }

        destroy() {
            if (this.timeoutTimer) clearTimeout(this.timeoutTimer);
            if (this.warningTimer) clearTimeout(this.warningTimer);
            if (this.checkTimer) clearInterval(this.checkTimer);
        }
    }

    // Initialize session manager when DOM is ready
    let sessionManager;

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize session manager
        sessionManager = new SessionManager();

        // Also add to window for debugging
        window.sessionManager = sessionManager;
    });

    // Clean up on page unload
    window.addEventListener('beforeunload', () => {
        if (sessionManager) {
            sessionManager.destroy();
        }
    });

    // ==========================================
    // ACTIVITY INDICATOR
    // ==========================================

    /**
     * Optional: Show session time remaining in header
     */
    function addSessionIndicator() {
        const headerControls = document.querySelector('.header-controls');

        if (headerControls) {
            const indicator = document.createElement('div');
            indicator.id = 'sessionIndicator';
            indicator.style.cssText = `
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            padding: 5px 12px;
            border-radius: 20px;
            color: white;
            font-size: 0.85rem;
        `;

            indicator.innerHTML = `
            <span>🕐</span>
            <span id="sessionTime">--:--</span>
        `;

            headerControls.insertBefore(indicator, headerControls.firstChild);

            // Update every minute
            updateSessionIndicator();
            setInterval(updateSessionIndicator, 60000);
        }
    }

    function updateSessionIndicator() {
        const loginTime = sessionStorage.getItem('loginTime');
        const sessionDuration = 4 * 60 * 60 * 1000; // 4 hours (server idle limit)

        if (!loginTime) return;

        const timeElapsed = Date.now() - parseInt(loginTime);
        const remaining = Math.max(0, sessionDuration - timeElapsed);
        const hours = Math.floor(remaining / 3600000);
        const minutes = Math.floor((remaining % 3600000) / 60000);

        const timeDisplay = document.getElementById('sessionTime');
        if (timeDisplay) {
            timeDisplay.textContent = `${hours}h ${minutes}m`;

            // Change color based on time remaining
            if (remaining < 5 * 60 * 1000) { // Less than 5 minutes
                timeDisplay.style.color = '#ff5252';
            } else if (remaining < 15 * 60 * 1000) { // Less than 15 minutes
                timeDisplay.style.color = '#ffa726';
            } else {
                timeDisplay.style.color = 'white';
            }
        }
    }

    // ==========================================
    // SECTION VISIBILITY MANAGEMENT
    // ==========================================

    /**
     * Hide all main sections to ensure clean switching
     * Includes Loans, Payments, Funding, Reports, and Debtors
     */
    function hideAllSections() {
        // Hide Main Sections
        const loansSection = document.getElementById('loansSection');
        const paymentsSection = document.getElementById('paymentsSection');
        const fundingSection = document.getElementById('fundingSection');
        const reportsSection = document.getElementById('reportsSection');
        const dashboardSection = document.getElementById('dashboardSection');

        if (loansSection) loansSection.classList.add('hidden');
        if (paymentsSection) paymentsSection.classList.add('hidden');
        if (fundingSection) fundingSection.classList.add('hidden');
        if (reportsSection) reportsSection.classList.add('hidden');
        if (dashboardSection) dashboardSection.classList.add('hidden');

    }

    async function showOutstandingDebtors() {
        window.location.href = '../legacy-debt.php';
        return;

        hideAllSections();

        try {
            showLoadingIndicator();

            // Get summary to find years with pending debtors
            const summaryResponse = await apiCall('debtors');

            if (!summaryResponse.success) {
                throw new Error(summaryResponse.message);
            }

            const summary = summaryResponse.data || [];

            // Fetch all unconverted debtors from all years
            const debtorPromises = summary.map(year =>
                // FIXED: Pass financial_year as query parameter through apiCall
                fetch(
                    `${API_BASE_URL}?api&endpoint=debtors&financial_year=${encodeURIComponent(year.financial_year)}`
                )
                .then(res => res.json())
            );

            const responses = await Promise.all(debtorPromises);

            // Combine all debtors and filter only unconverted ones
            let allDebtors = [];
            responses.forEach(response => {
                if (response.success && response.data.debtors) {
                    const unconverted = response.data.debtors.filter(d => !d.loan_application_id);
                    allDebtors = allDebtors.concat(unconverted);
                }
            });

            const totalAmount = allDebtors.reduce((sum, d) => sum + parseFloat(d.amount || 0), 0);

            let html = `
            <h2><i data-lucide="coins" class="icon-sm"></i> Outstanding Debtors (Not Yet Converted to Loans)</h2>
            
            <div class="debtor-stats">
                <div class="debtor-stat-card danger">
                    <div class="debtor-stat-number">${allDebtors.length}</div>
                    <div class="debtor-stat-label">Pending Conversion</div>
                </div>
                <div class="debtor-stat-card warning">
                    <div class="debtor-stat-number">KES ${formatCurrency(totalAmount)}</div>
                    <div class="debtor-stat-label">Total Amount Due</div>
                </div>
            </div>
            
            ${allDebtors.length > 0 ? `
            <div style="margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ff9800; border-radius: 6px;">
                <h4 style="margin: 0 0 10px 0; color: #856404;"><i data-lucide="alert-triangle" class="icon-sm"></i> Action Required</h4>
                <p style="margin: 0; color: #856404;">
                    These debtors have been imported but not yet converted to active loan records. 
                    Click "Convert All" to migrate them to the main loan system.
                </p>
                <button onclick="convertAllDebtors()" class="action-btn" style="background: #FF9800; margin-top: 10px;">
                    <i data-lucide="refresh-cw" class="icon-sm"></i> Convert All ${allDebtors.length} Debtors to Loans
                </button>
            </div>
            ` : ''}
        `;

            if (allDebtors.length === 0) {
                html += `
                <div class="balance-highlight">
                    <p style="text-align: center; padding: 30px; color: #666;">
                        <i data-lucide="check-circle" class="icon-sm"></i> No outstanding debtors. All imported debtors have been converted to loans.
                    </p>
                </div>
            `;
            } else {
                html += `
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>PF Number</th>
                                <th>ID Number</th>
                                <th style="text-align: right;">Amount (KES)</th>
                                <th>Financial Year</th>
                                <th>Status</th>
                                <th>Import Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

                allDebtors.forEach((debtor, index) => {
                    html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${escapeHtml(debtor.name)}</td>
                        <td>${debtor.pf_number || 'N/A'}</td>
                        <td>${debtor.id_number || 'N/A'}</td>
                        <td style="text-align: right; font-weight: 600;">
                            KES ${formatCurrency(debtor.amount)}
                        </td>
                        <td>${debtor.financial_year}</td>
                        <td>
                            <span style="background: #f57c00; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.85em;">
                                ${debtor.status || 'Pending'}
                            </span>
                        </td>
                        <td>${formatDate(debtor.created_at)}</td>
                        <td>
                            <button class="action-btn" onclick="convertSingleDebtor(${debtor.debtor_id}, '${escapeHtml(debtor.name)}')" 
                                    style="background: #4CAF50; font-size: 0.85em; padding: 6px 12px;">
                                Convert to Loan
                            </button>
                        </td>
                    </tr>
                `;
                });

                html += `
                        </tbody>
                    </table>
                </div>
            `;
            }

            document.getElementById('mainContent').innerHTML = html;
            document.getElementById('mainContent').style.display = 'block';

        } catch (error) {
            console.error('Error loading outstanding debtors:', error);
            showNotification('Error loading debtors: ' + error.message, 'error');
        } finally {
            hideLoadingIndicator();
        }
    }

    /**
     * Convert all outstanding debtors to loans
     */
    async function convertAllDebtors() {
        if (!confirm(
                'Convert ALL pending debtors to active loan records?\n\nThis will create loan applications for all unconverted debtors.'
            )) {
            return;
        }

        try {
            showLoadingIndicator();

            const response = await apiCall('migrate_outstanding_to_loans.php', 'POST', {
                csrf_token: APP_STATE.csrfToken
            });

            if (!response.success) {
                throw new Error(response.message);
            }

            const imported = response.data.imported || 0;
            const totalAmount = response.data.total_amount || 0;

            showNotification(
                `Successfully converted ${imported} debtor(s) worth KES ${formatCurrency(totalAmount)} to loan applications!`,
                'success',
                5000
            );

            // Refresh the outstanding debtors view
            showOutstandingDebtors();

            // Also refresh loans view if it's open
            const activeFilter = document.querySelector('#sidebar button.active')?.dataset.filter;
            if (activeFilter && activeFilter !== 'outstanding-debtors') {
                // Optionally trigger a background refresh of loans
                getAllLoans().catch(err => console.error('Error refreshing loans:', err));
            }

        } catch (error) {
            console.error('Conversion error:', error);
            showNotification('Conversion failed: ' + error.message, 'error');
        } finally {
            hideLoadingIndicator();
        }
    }

    /**
     * Convert a single debtor to a loan (future feature)
     * Note: This would require updating the migration API to support single conversions
     */
    async function convertSingleDebtor(debtorId, debtorName) {
        showNotification('Single debtor conversion coming soon. Use "Convert All" for now.', 'info');

        // Future implementation:
        /*
        if (!confirm(`Convert ${debtorName} to an active loan record?`)) {
            return;
        }
        
        try {
            showLoadingIndicator();
            
            const response = await apiCall('migrate_outstanding_to_loans.php', 'POST', {
                csrf_token: APP_STATE.csrfToken,
                debtor_id: debtorId
            });
            
            if (!response.success) {
                throw new Error(response.message);
            }
            
            showNotification(`Successfully converted ${debtorName} to a loan!`, 'success');
            
            // Refresh view
            showOutstandingDebtors();
            
        } catch (error) {
            showNotification('Conversion failed: ' + error.message, 'error');
        } finally {
            hideLoadingIndicator();
        }
        */
    }

    /**
     * View converted debtors (those now in loan system)
     */
    async function viewConvertedDebtors() {
        window.location.href = '../legacy-debt.php';
        return;

        hideAllSections();

        try {
            showLoadingIndicator();

            // Get summary
            const summaryResponse = await apiCall('debtors');

            if (!summaryResponse.success) {
                throw new Error(summaryResponse.message);
            }

            const summary = summaryResponse.data || [];

            // Fetch all debtors from all years
            const debtorPromises = summary.map(year =>
                fetch(
                    `${API_BASE_URL}?api&endpoint=debtors&financial_year=${encodeURIComponent(year.financial_year)}`
                )
                .then(res => res.json())
            );

            const responses = await Promise.all(debtorPromises);

            // Combine all debtors and filter only converted ones
            let convertedDebtors = [];
            responses.forEach(response => {
                if (response.success && response.data.debtors) {
                    const converted = response.data.debtors.filter(d => d.loan_application_id);
                    convertedDebtors = convertedDebtors.concat(converted);
                }
            });

            const totalAmount = convertedDebtors.reduce((sum, d) => sum + parseFloat(d.amount || 0), 0);

            let html = `
            <h2><i data-lucide="check-circle" class="icon-sm"></i> Converted Debtors (Now Active Loans)</h2>
            
            <div class="debtor-stats">
                <div class="debtor-stat-card success">
                    <div class="debtor-stat-number">${convertedDebtors.length}</div>
                    <div class="debtor-stat-label">Converted to Loans</div>
                </div>
                <div class="debtor-stat-card info">
                    <div class="debtor-stat-number">KES ${formatCurrency(totalAmount)}</div>
                    <div class="debtor-stat-label">Total Amount</div>
                </div>
            </div>
        `;

            if (convertedDebtors.length === 0) {
                html += `
                <div class="balance-highlight">
                    <p style="text-align: center; padding: 30px; color: #666;">
                        No debtors have been converted to loans yet.
                    </p>
                </div>
            `;
            } else {
                html += `
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>PF Number</th>
                                <th style="text-align: right;">Amount (KES)</th>
                                <th>Financial Year</th>
                                <th>Loan Status</th>
                                <th>Application ID</th>
                                <th>Conversion Date</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

                convertedDebtors.forEach((debtor, index) => {
                    const statusColor = debtor.loan_status === 'active' ? '#4CAF50' : '#2196F3';

                    html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${escapeHtml(debtor.name)}</td>
                        <td>${debtor.pf_number || 'N/A'}</td>
                        <td style="text-align: right; font-weight: 600;">
                            KES ${formatCurrency(debtor.amount)}
                        </td>
                        <td>${debtor.financial_year}</td>
                        <td>
                            <span style="background: ${statusColor}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.85em;">
                                ${debtor.loan_status || 'Active'}
                            </span>
                        </td>
                        <td style="font-family: monospace; font-size: 0.85em;">
                            ${debtor.loan_application_id}
                        </td>
                        <td>${formatDate(debtor.application_date || debtor.created_at)}</td>
                    </tr>
                `;
                });

                html += `
                        </tbody>
                    </table>
                </div>
            `;
            }

            document.getElementById('mainContent').innerHTML = html;
            document.getElementById('mainContent').style.display = 'block';

        } catch (error) {
            console.error('Error loading converted debtors:', error);
            showNotification('Error loading data: ' + error.message, 'error');
        } finally {
            hideLoadingIndicator();
        }
    }

    // ==========================================
    // LOADING INDICATOR FUNCTIONS
    // ==========================================

    /**
     * Show loading indicator overlay
     */
    function showLoadingIndicator(message = 'Loading...') {
        // Remove existing indicator if present
        hideLoadingIndicator();

        const overlay = document.createElement('div');
        overlay.id = 'loadingIndicator';
        overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        flex-direction: column;
    `;

        overlay.innerHTML = `
        <div style="
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        ">
            <div style="
                border: 5px solid #f3f3f3;
                border-top: 5px solid #4a6b4a;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
                margin: 0 auto 15px;
            "></div>
            <p style="
                margin: 0;
                font-weight: 600;
                color: #333;
                font-size: 16px;
            ">${message}</p>
        </div>
    `;

        document.body.appendChild(overlay);

        // Add spinner animation if not already present
        if (!document.getElementById('spinner-animation-style')) {
            const style = document.createElement('style');
            style.id = 'spinner-animation-style';
            style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
            document.head.appendChild(style);
        }
    }

    /**
     * Hide loading indicator overlay
     */
    function hideLoadingIndicator() {
        const indicator = document.getElementById('loadingIndicator');
        if (indicator) {
            indicator.remove();
        }
    }

    // FULL FILTER BAR FUNCTIONALITY (exact copy from Cashier portal)
    function applyLoanFilters() {
        const searchText = (document.getElementById('loanSearch')?.value || '').toLowerCase().trim();
        const statusVal = document.getElementById('statusFilter')?.value || 'all';
        const typeVal = document.getElementById('loanTypeFilter')?.value || 'all';

        const table = document.getElementById('loansTable');
        if (!table) return;

        const rows = table.tBodies[0]?.rows || [];

        for (let row of rows) {
            if (!row.cells || row.cells.length < 5) continue;

            const name = (row.cells[0]?.textContent || '').toLowerCase();
            const idNum = (row.cells[1]?.textContent || '').toLowerCase();
            const pfNum = (row.cells[2]?.textContent || '').toLowerCase();
            const loanType = (row.cells[3]?.textContent || '').toLowerCase();
            const status = row.className.match(/status-([a-z-]+)/)?. [1] || '';

            const matchesSearch = !searchText ||
                name.includes(searchText) ||
                idNum.includes(searchText) ||
                pfNum.includes(searchText);

            const matchesStatus = statusVal === 'all' || status === statusVal;
            const matchesType = typeVal === 'all' || loanType.includes(typeVal);

            row.style.display = (matchesSearch && matchesStatus && matchesType) ? '' : 'none';
        }
    }

    function updateClearButtonVisibility() {
        const clearBtn = document.getElementById('clearFilters');
        if (!clearBtn) return;
        const hasFilter = Boolean(loanFilters.search.trim()) || loanFilters.type !== 'all';
        clearBtn.style.display = hasFilter ? 'inline-flex' : 'none';
    }

    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = document.getElementById('loanSearch');
        const statusSelect = document.getElementById('statusFilter');
        const typeSelect = document.getElementById('loanTypeFilter');
        const applyBtn = document.getElementById('applyFilters');
        const clearBtn = document.getElementById('clearFilters');
        const refreshBtn = document.getElementById('refreshLoans');
        const exportBtn = document.getElementById('exportLoansBtn');

        if (searchInput) searchInput.addEventListener('input', debounce(applyLoanFilters, 300));
        if (statusSelect) statusSelect.addEventListener('change', applyLoanFilters);
        if (typeSelect) typeSelect.addEventListener('change', applyLoanFilters);
        if (applyBtn) applyBtn.addEventListener('click', applyLoanFilters);

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (statusSelect) statusSelect.value = 'all';
                if (typeSelect) typeSelect.value = 'all';
                applyLoanFilters();
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                const activeFilter = document.querySelector('#sidebar li.active')?.dataset.filter || 'all';
                renderLoans(activeFilter);
            });
        }

        if (exportBtn) {
            exportBtn.addEventListener('click', () => {
                window.location.href = 'Accountant.php?export=csv';
            });
        }

        // Apply filters automatically when switching to All Loans
        applyLoanFilters();
    });

