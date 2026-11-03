// ==========================================
// API Configuration
// ==========================================
function getApiBaseUrl() {
    const hostname = window.location.hostname;
    const pathname = window.location.pathname;
    const basePath = pathname.substring(0, pathname.lastIndexOf('/'));

    if (hostname === 'localhost' || hostname === '127.0.0.1') {
        // Try multiple possible localhost paths
        const possiblePaths = [
            'http://localhost/loan-system/api',
            'http://localhost/loan/api',
            'http://localhost/api',
            'http://localhost/loans/api'
        ];

        // Return the first one that matches the current path
        for (const path of possiblePaths) {
            if (pathname.includes(path.split('/api')[0])) {
                //console.log('Using API path:', path);
                return path;
            }
        }

        // Default fallback
        //console.log('Using default API path:', possiblePaths[0]);
        return possiblePaths[0];
    } else {
        const protocol = window.location.protocol;
        return `${protocol}//${hostname}/loan-system/api`;
    }
}

const API_BASE_URL = getApiBaseUrl();
//console.log('API Base URL:', API_BASE_URL);

// ==========================================
// DOM Elements
// ==========================================
// Sections
const dashboardSection = document.getElementById('dashboardSection');
const calculatorSection = document.getElementById('calculatorSection');
const historySection = document.getElementById('historySection');
const paymentsSection = document.getElementById('paymentsSection');
const paymentScheduleSection = document.getElementById('paymentScheduleSection');
const newAppSection = document.getElementById('newAppSection');
const profileSection = document.getElementById('profileSection');

// Dashboard Elements
const userNameElement = document.getElementById('userName');
const userPfElement = document.getElementById('userPf');
const latestLoan = document.getElementById('latestLoan');
const noLoan = document.getElementById('noLoan');
const fixedRateDisplay = document.getElementById('fixedRateDisplay');

// Calculator Elements 
const calcAmount = document.getElementById('calcAmount');
const calcTerm = document.getElementById('calcTerm');
const calcBtn = document.getElementById('calcBtn');
const calcSummary = document.getElementById('calcSummary');
const sumAmount = document.getElementById('sumAmount');
const sumRate = document.getElementById('sumRate');
const sumTerm = document.getElementById('sumTerm');
const sumMonthly = document.getElementById('sumMonthly');
const sumInterest = document.getElementById('sumInterest');
const sumTotal = document.getElementById('sumTotal');
const amortContainer = document.getElementById('amortContainer');
const amortBody = document.getElementById('amortBody');

// Eligibility Check Elements
const eligibilityCheck = document.getElementById('eligibilityCheck');
const checkLoanAmount = document.getElementById('checkLoanAmount');
const checkLoanTerm = document.getElementById('checkLoanTerm');
const checkBasicSalary = document.getElementById('checkBasicSalary');
const checkCurrentNetSalary = document.getElementById('checkCurrentNetSalary');
const checkEligibilityBtn = document.getElementById('checkEligibilityBtn');
const eligibilityResult = document.getElementById('eligibilityResult');
const breakdownBox = document.getElementById('breakdownBox');
const breakdownSalary = document.getElementById('breakdownSalary');
const breakdownMaxAllowed = document.getElementById('breakdownMaxAllowed');
const breakdownCurrentNet = document.getElementById('breakdownCurrentNet');
const breakdownNewLoan = document.getElementById('breakdownNewLoan');
const breakdownNewNet = document.getElementById('breakdownNewNet');
const breakdownRemaining = document.getElementById('breakdownRemaining');

// Loan Application Elements
const precheckLoanType = document.getElementById('precheckLoanType');
const precheckPropertyValue = document.getElementById('precheckPropertyValue');
const precheckCarValue = document.getElementById('precheckCarValue');
const checkPropertyValue = document.getElementById('checkPropertyValue');
const checkCarValue = document.getElementById('checkCarValue');
const precheckRetirementDetails = document.getElementById('precheckRetirementDetails');
const precheckRetirementDate = document.getElementById('precheckRetirementDate');
const precheckEmploymentType = document.getElementById('precheckEmploymentType');
const precheckContractDetails = document.getElementById('precheckContractDetails');
const precheckContractEnd = document.getElementById('precheckContractEnd');

// Loan Application Form Elements
const historyContainer = document.getElementById('loanHistoryGrid');
const loanType = document.getElementById('loanType');
const mortgageFields = document.getElementById('mortgageFields');
const carFields = document.getElementById('carFields');
const expectedLoan = document.getElementById('expectedLoan');
const paymentYears = document.getElementById('paymentYears');
const basicSalary = document.getElementById('basicSalary');
const employmentType = document.getElementById('employmentType');
const contractDetails = document.getElementById('contractDetails');
const bankName = document.getElementById('bankName');
const bankBranch = document.getElementById('bankBranch');
const bankAccount = document.getElementById('bankAccount');
const loanForm = document.getElementById('loanForm');
const loanSuccess = document.getElementById('loanSuccess');
const cancelLoan = document.getElementById('cancelLoan');

// Profile & Security Elements
const passwordForm = document.getElementById('passwordForm');
const newPassword = document.getElementById('new_password');
const passwordStrength = document.getElementById('passwordStrength');

// Payment History Elements
const loanFilter = document.getElementById('loanFilter');
const yearFilter = document.getElementById('yearFilter');
const sourceFilter = document.getElementById('sourceFilter');
const paymentsTableBody = document.getElementById('paymentsTableBody');
const totalPayments = document.getElementById('totalPayments');
const totalPrincipalPaid = document.getElementById('totalPrincipalPaid');
const totalInterestPaid = document.getElementById('totalInterestPaid');

// Payment Schedule Elements
const paymentScheduleContainer = document.getElementById('scheduleTableContainer');

// Hamburger and sidebar elements
const hamburger = document.getElementById('hamburgerMenu');
const sidebar = document.getElementById('sidebar');
const mainContent = document.getElementById('mainContent');
const profileBadge = document.querySelector('.profile-badge');
const profileAvatar = document.querySelector('.profile-avatar');

// ==========================================
// Utility Functions
// ==========================================
const fmtCurrency = v => new Intl.NumberFormat('en-KE', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
}).format(Math.ceil(v));

const fmtDate = d => new Date(d).toLocaleDateString('en-GB', {
    year: 'numeric',
    month: 'short',
    day: '2-digit'
});

function isValidAccountNumber(ac) {
    return /^[0-9]{8,16}$/.test(ac);
}

function checkPasswordStrength(password) {
    if (!password) return '';

    let strength = 0;
    const checks = {
        length: password.length >= 8,
        longLength: password.length >= 12,
        lowercase: /[a-z]/.test(password),
        uppercase: /[A-Z]/.test(password),
        number: /[0-9]/.test(password),
        special: /[^a-zA-Z0-9]/.test(password),
        noSpaces: !/\s/.test(password)
    };

    // Calculate strength
    if (checks.length) strength += 1;
    if (checks.longLength) strength += 1;
    if (checks.lowercase) strength += 1;
    if (checks.uppercase) strength += 1;
    if (checks.number) strength += 1;
    if (checks.special) strength += 1;

    // Determine level
    if (strength < 3) return 'weak';
    if (strength < 5) return 'medium';
    return 'strong';
}

function updatePasswordStrengthIndicator(password) {
    const strength = checkPasswordStrength(password);
    passwordStrength.className = 'password-strength';
    if (strength === 'weak') {
        passwordStrength.classList.add('password-strength-weak');
    } else if (strength === 'medium') {
        passwordStrength.classList.add('password-strength-medium');
    } else if (strength === 'strong') {
        passwordStrength.classList.add('password-strength-strong');
    }
}

// ==========================================
// API Helper Functions
// ==========================================
async function apiRequest(endpoint, method = 'GET', data = null) {
    // Remove any leading slashes from endpoint
    const cleanEndpoint = endpoint.replace(/^\/+/, '');

    // Construct full URL - ensure single slash between base and endpoint
    let url = `${API_BASE_URL}/${cleanEndpoint}`.replace(/([^:]\/)\/+/g, "$1");

    // Add viewing_as_loanee flag for GET requests
    if (method === 'GET' && currentUser && currentUser.viewing_as_loanee) {
        url += (url.includes('?') ? '&' : '?') + 'viewing_as_loanee=true';
    }

    //console.log('🌐 API Request:', method, url);

    const headers = {
        'Content-Type': 'application/json'
    };

    const options = {
        method,
        headers,
        credentials: 'include'
    };

    if (data && method !== 'GET') {
        // Make sure we're sending properly formatted JSON
        const requestData = {
            ...data,
            csrf_token: data.csrf_token || csrfToken
        };

        options.body = JSON.stringify(requestData);
    }

    try {
        const response = await fetch(url, options);

        //console.log('📡 Response Status:', response.status, response.statusText);

        // Get response text first
        const responseText = await response.text();
        //console.log('<i data-lucide="file-text" class="icon-sm"></i> Raw Response:', responseText.substring(0, 200));

        if (!response.ok) {
            //console.error('<i data-lucide="x-circle" class="icon-sm"></i> API Error Response:', responseText);
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            //console.error('<i data-lucide="x-circle" class="icon-sm"></i> Non-JSON response received');
            //console.error('Content-Type:', contentType);
            //console.error('Response text:', responseText);
            throw new Error('Server returned non-JSON response');
        }

        // Parse JSON
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            //console.error('<i data-lucide="x-circle" class="icon-sm"></i> JSON Parse Error:', parseError);
            //console.error('Response text:', responseText);
            throw new Error('Failed to parse JSON response');
        }

        //console.log('<i data-lucide="check-circle" class="icon-sm"></i> API Response:', result);

        // Check for success property and handle accordingly
        if (!result.success) {
            throw new Error(result.message || 'Request failed');
        }

        return result;
    } catch (error) {
        //console.error('<i data-lucide="x-circle" class="icon-sm"></i> API Error:', error);
        throw error;
    }
}

// ==========================================
// Authentication Functions
// ==========================================
async function logout() {
    try {
        await apiRequest('auth.php', 'POST', {
            action: 'logout',
            csrf_token: csrfToken
        });
        return true;
    } catch (error) {
        //console.error('Logout error:', error);
        // Still return true to proceed with redirect
        return true;
    }
}

// ==========================================
/**
 * Validates whether retirement date allows full loan repayment.
 * Car loans: must be repaid within 5 years after disbursement.
 * Mortgage loans: must be repaid within 10 years after disbursement.
 * @param {string} retirementDate - ISO date string e.g. '2043-11-11'
 * @param {number} loanTerm - loan term in years
 * @param {string} loanType - 'car' or 'mortgage'
 * @returns {{isValid: boolean, message: string}}
 */
function validateRetirementForLoan(retirementDate, loanTerm, loanType) {
    const today = new Date();
    const retirement = new Date(retirementDate);
    const maxTerm = loanType === 'car' ? 5 : 10;

    // Months from today to retirement
    const monthsToRetirement = (retirement.getFullYear() - today.getFullYear()) * 12
        + (retirement.getMonth() - today.getMonth());

    const loanTermMonths = Math.round(loanTerm * 12);

    if (retirement <= today) {
        return {
            isValid: false,
            message: `<i data-lucide="x-circle" class="icon-sm" style="color:#dc3545;"></i> <strong>NOT ELIGIBLE - Already Retired</strong><br><br>Your expected retirement date is in the past. You must be an active employee to qualify for a loan.`
        };
    }

    if (loanTermMonths > monthsToRetirement) {
        const yearsToRetirement = (monthsToRetirement / 12).toFixed(1);
        return {
            isValid: false,
            message: `<i data-lucide="x-circle" class="icon-sm" style="color:#dc3545;"></i> <strong>NOT ELIGIBLE - Loan Extends Beyond Retirement</strong><br><br>Your requested loan term of <strong>${loanTerm} year(s)</strong> extends beyond your retirement date.<br>You have approximately <strong>${yearsToRetirement} years</strong> until retirement.<br><br><strong>Suggestion:</strong> Reduce your loan term to ${Math.floor(monthsToRetirement / 12)} year(s) or less.`
        };
    }

    return { isValid: true, message: '' };
}

/**
 * Validates whether contract end date allows full loan repayment.
 * @param {string} contractEnd - ISO date string e.g. '2030-06-30'
 * @param {number} loanTerm - loan term in years
 * @returns {{isValid: boolean, message: string}}
 */
function validateContractForLoan(contractEnd, loanTerm) {
    const today = new Date();
    const contractEndDate = new Date(contractEnd);

    const monthsToContractEnd = (contractEndDate.getFullYear() - today.getFullYear()) * 12
        + (contractEndDate.getMonth() - today.getMonth());

    const loanTermMonths = Math.round(loanTerm * 12);

    if (contractEndDate <= today) {
        return {
            isValid: false,
            message: `<i data-lucide="x-circle" class="icon-sm" style="color:#dc3545;"></i> <strong>NOT ELIGIBLE - Contract Has Expired</strong><br><br>Your contract end date is in the past. You must be under an active contract to qualify for a loan.`
        };
    }

    if (loanTermMonths > monthsToContractEnd) {
        const yearsToEnd = (monthsToContractEnd / 12).toFixed(1);
        return {
            isValid: false,
            message: `<i data-lucide="x-circle" class="icon-sm" style="color:#dc3545;"></i> <strong>NOT ELIGIBLE - Loan Extends Beyond Contract End</strong><br><br>Your requested loan term of <strong>${loanTerm} year(s)</strong> extends beyond your contract end date.<br>Your contract has approximately <strong>${yearsToEnd} years</strong> remaining.<br><br><strong>Suggestion:</strong> Reduce your loan term to ${Math.floor(monthsToContractEnd / 12)} year(s) or less.`
        };
    }

    return { isValid: true, message: '' };
}

// ==========================================
// Enhanced Eligibility Check
// ==========================================
function checkLoanEligibility() {
    const loanAmount = parseFloat(checkLoanAmount.value) || 0;
    const loanTerm = parseFloat(checkLoanTerm.value) || 0;
    const salary = parseFloat(checkBasicSalary.value) || 0;
    const currentNetSalary = parseFloat(checkCurrentNetSalary.value) || 0;
    const empType = precheckEmploymentType.value;
    const selectedLoanType = precheckLoanType.value;

    // Validate basic required fields
    if (loanAmount <= 0 || loanTerm <= 0 || salary <= 0 || currentNetSalary <= 0 || !empType || !
        selectedLoanType) {
        eligibilityResult.innerHTML =
            '<span style="color: #856404;"><i data-lucide="alert-triangle" class="icon-sm" style="color: #856404;"></i> Please fill in all required fields with valid values.</span>';
        eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
        breakdownBox.classList.add('hidden');
        return;
    }

    // Validate loan term based on loan type
    if (selectedLoanType === 'car' && loanTerm > 5) {
        eligibilityResult.innerHTML = `
<i data-lucide="x-circle" class="icon-sm" style="color: #dc3545;"></i> <strong>NOT ELIGIBLE - Car Loan Term Exceeds Limit</strong><br><br>
Car loans must be paid within a period of 5 years or less.<br>
Your requested term is ${loanTerm} years.<br><br>
<strong>Suggestion:</strong> Please reduce your loan term to 5 years or less.
`;
        eligibilityResult.classList.remove('hidden', 'eligible');
        eligibilityResult.classList.add('not-eligible');
        breakdownBox.classList.add('hidden');
        return;
    }

    if (selectedLoanType === 'mortgage' && loanTerm > 10) {
        eligibilityResult.innerHTML = `
<i data-lucide="x-circle" class="icon-sm" style="color: #dc3545;"></i> <strong>NOT ELIGIBLE - Mortgage Loan Term Exceeds Limit</strong><br><br>
Mortgage loans must be paid within a period of 10 years or less.<br>
Your requested term is ${loanTerm} years.<br><br>
<strong>Suggestion:</strong> Please reduce your loan term to 10 years or less.
`;
        eligibilityResult.classList.remove('hidden', 'eligible');
        eligibilityResult.classList.add('not-eligible');
        breakdownBox.classList.add('hidden');
        return;
    }

    // Validate loan amount against property/car value
    if (selectedLoanType === 'mortgage') {
        const propertyValue = parseFloat(checkPropertyValue.value) || 0;
        if (propertyValue <= 0) {
            eligibilityResult.innerHTML =
                '<span style="color: #856404;"><i data-lucide="alert-triangle" class="icon-sm" style="color: #856404;"></i> Please enter property value.</span>';
            eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }
        if (loanAmount > propertyValue) {
            eligibilityResult.innerHTML = `
<i data-lucide="x-circle" class="icon-sm" style="color: #dc3545;"></i> <strong>NOT ELIGIBLE - Loan Amount Exceeds Property Value</strong><br><br>
You are requesting a loan of <strong>KES ${fmtCurrency(loanAmount)}</strong>, 
but the property value is only <strong>KES ${fmtCurrency(propertyValue)}</strong>.<br><br>
<strong>Maximum Loan Amount:</strong> KES ${fmtCurrency(propertyValue)}<br><br>
Please reduce your loan amount to match or be less than the property value.
`;
            eligibilityResult.classList.remove('hidden', 'eligible');
            eligibilityResult.classList.add('not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }
    } else if (selectedLoanType === 'car') {
        const carValue = parseFloat(checkCarValue.value) || 0;
        if (carValue <= 0) {
            eligibilityResult.innerHTML =
                '<span style="color: #856404;"><i data-lucide="alert-triangle" class="icon-sm" style="color: #856404;"></i> Please enter car value.</span>';
            eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }
        if (loanAmount > carValue) {
            eligibilityResult.innerHTML = `
<i data-lucide="x-circle" class="icon-sm" style="color: #dc3545;"></i> <strong>NOT ELIGIBLE - Loan Amount Exceeds Car Value</strong><br><br>
You are requesting a loan of <strong>KES ${fmtCurrency(loanAmount)}</strong>, 
but the car value is only <strong>KES ${fmtCurrency(carValue)}</strong>.<br><br>
<strong>Maximum Loan Amount:</strong> KES ${fmtCurrency(carValue)}<br><br>
Please reduce your loan amount to match or be less than the car value.
`;
            eligibilityResult.classList.remove('hidden', 'eligible');
            eligibilityResult.classList.add('not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }
    }

    // Validate for permanent employees (retirement date)
    if (empType === 'permanent') {
        const retirementDate = precheckRetirementDate.value;
        if (!retirementDate) {
            eligibilityResult.innerHTML =
                '<span style="color: #856404;"><i data-lucide="alert-triangle" class="icon-sm" style="color: #856404;"></i> Please enter your expected retirement date.</span>';
            eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }

        const retirementValidation = validateRetirementForLoan(retirementDate, loanTerm, selectedLoanType);
        if (!retirementValidation.isValid) {
            eligibilityResult.innerHTML = retirementValidation.message;
            eligibilityResult.classList.remove('hidden', 'eligible');
            eligibilityResult.classList.add('not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }
    }

    // Validate for contracted employees (contract end date)
    if (empType === 'contracted') {
        const contractEnd = precheckContractEnd.value;
        if (!contractEnd) {
            eligibilityResult.innerHTML =
                '<span style="color: #856404;"><i data-lucide="alert-triangle" class="icon-sm" style="color: #856404;"></i> Please enter your contract end date.</span>';
            eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }

        const contractValidation = validateContractForLoan(contractEnd, loanTerm);
        if (!contractValidation.isValid) {
            eligibilityResult.innerHTML = contractValidation.message;
            eligibilityResult.classList.remove('hidden', 'eligible');
            eligibilityResult.classList.add('not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }
    }

    // Calculate monthly payment
    const monthlyRate = (currentInterestRate / 100) / 12;
    const totalMonths = Math.round(loanTerm * 12);

    let monthlyPayment;
    if (monthlyRate === 0) {
        monthlyPayment = Math.ceil(loanAmount / totalMonths);
    } else {
        monthlyPayment = Math.ceil(loanAmount * (monthlyRate * Math.pow(1 + monthlyRate, totalMonths)) /
            (Math.pow(1 + monthlyRate, totalMonths) - 1));
    }

    // Calculate new net salary after loan payment
    const newNetSalary = currentNetSalary - monthlyPayment;
    const oneThirdSalary = Math.ceil(salary / 3);

    // Display breakdown
    breakdownSalary.textContent = fmtCurrency(salary);
    breakdownMaxAllowed.textContent = fmtCurrency(oneThirdSalary);
    breakdownCurrentNet.textContent = fmtCurrency(currentNetSalary);
    breakdownNewLoan.textContent = fmtCurrency(monthlyPayment);
    breakdownNewNet.textContent = fmtCurrency(newNetSalary);
    breakdownRemaining.textContent = fmtCurrency(newNetSalary);
    breakdownBox.classList.remove('hidden');

    eligibilityResult.classList.remove('hidden');

    // Check if eligible based on new net salary
    if (newNetSalary >= oneThirdSalary) {
        eligibilityResult.className = 'eligibility-result eligible';
        eligibilityResult.innerHTML = `
<i data-lucide="check-circle" class="icon-sm" style="color: #28a745;"></i> <strong>You are ELIGIBLE for this loan!</strong><br>
Your new net salary of KES ${fmtCurrency(newNetSalary)} is greater than one-third of your basic salary.<br>
<button id="proceedToApplication" style="margin-top:10px">Proceed to Full Application</button>
`;

        // Add event listener for proceed button
        const proceedBtn = document.getElementById('proceedToApplication');
        if (proceedBtn) {
            proceedBtn.addEventListener('click', function() {
                // Pre-fill and make fields read-only
                expectedLoan.value = loanAmount;
                expectedLoan.readOnly = true;
                expectedLoan.style.backgroundColor = '#f0f0f0';
                expectedLoan.style.cursor = 'not-allowed';

                paymentYears.value = loanTerm;
                paymentYears.readOnly = true;
                paymentYears.style.backgroundColor = '#f0f0f0';
                paymentYears.style.cursor = 'not-allowed';

                basicSalary.value = salary;
                basicSalary.readOnly = true;
                basicSalary.style.backgroundColor = '#f0f0f0';
                basicSalary.style.cursor = 'not-allowed';

                // Set loan type
                loanType.value = selectedLoanType;
                loanType.dispatchEvent(new Event('change'));

                // Set property/car values
                if (selectedLoanType === 'mortgage') {
                    const propertyValueInput = document.getElementById('propertyValue');
                    if (propertyValueInput) {
                        propertyValueInput.value = checkPropertyValue.value;
                    }
                } else if (selectedLoanType === 'car') {
                    const carValueInput = document.getElementById('carValue');
                    if (carValueInput) {
                        carValueInput.value = checkCarValue.value;
                    }
                }

                employmentType.value = empType;
                employmentType.dispatchEvent(new Event('change'));

                if (empType === 'contracted') {
                    contractDetails.classList.remove('hidden');
                    document.getElementById('contractEnd').value = precheckContractEnd.value;
                }

                eligibilityCheck.classList.add('hidden');
                loanForm.classList.remove('hidden');
            });
        }
    } else {
        const shortfall = oneThirdSalary - newNetSalary;
        const maxLoanPayment = Math.max(0, currentNetSalary - oneThirdSalary);

        eligibilityResult.className = 'eligibility-result not-eligible';
        eligibilityResult.innerHTML = `
<i data-lucide="x-circle" class="icon-sm" style="color: #dc3545;"></i> <strong>NOT ELIGIBLE - New Net Salary Below Minimum</strong><br><br>
Your new net salary would be KES ${fmtCurrency(newNetSalary)}, which is less than the minimum required 
of KES ${fmtCurrency(oneThirdSalary)} by KES ${fmtCurrency(shortfall)}.<br>
<strong>Suggestions:</strong><br>
• Reduce your loan amount to lower monthly payments<br>
• Extend your loan term for lower monthly payments<br>
• Increase your net salary through additional income<br>
• Consider a smaller loan amount<br><br>
Based on your current net salary, the maximum monthly loan payment you can afford is approximately 
<strong>KES ${fmtCurrency(maxLoanPayment)}</strong>.
`;
    }
}

// ==========================================
// Navigation Functions
// ==========================================
function showSection(sectionName) {
    //console.log('showSection called:', sectionName);

    // Hide all sections
    document.querySelectorAll('section').forEach(sec => {
        sec.classList.add('hidden');
    });

    // Map data-section values to actual section IDs
    const sectionMap = {
        'dashboard': 'dashboardSection',
        'calculator': 'calculatorSection',
        'history': 'historySection',
        'payments': 'paymentsSection',
        'payment-schedule': 'paymentScheduleSection',
        'newapp': 'newAppSection',
        'profile': 'profileSection'
    };

    const targetId = sectionMap[sectionName];
    const target = document.getElementById(targetId);

    if (target) {
        target.classList.remove('hidden');
        //console.log('Showing section:', targetId);
    } else {
        //console.error('Section not found:', targetId);
    }

    // Update active button
    document.querySelectorAll('#sidebar button').forEach(btn => {
        btn.classList.remove('active');
    });
    const activeBtn = document.querySelector(`#sidebar button[data-section="${sectionName}"]`);
    if (activeBtn) activeBtn.classList.add('active');

    // Load data for the section
    switch (sectionName) {
        case 'dashboard':
            if (typeof loadDashboard === 'function') loadDashboard();
            break;
        case 'history':
            renderLoanHistory();
            break;
        case 'payments':
            renderPaymentHistory();
            break;
        case 'payment-schedule':
            // Let the schedule loader fetch and filter the current user's loans.
            // This avoids a second request and keeps the loading/error handling
            // in one place.
            loadPaymentSchedule();
            break;
        case 'newapp':
            // Reset eligibility check
            if (document.getElementById('eligibilityCheck')) {
                document.getElementById('eligibilityCheck').classList.remove('hidden');
            }
            if (document.getElementById('loanForm')) {
                document.getElementById('loanForm').classList.add('hidden');
            }
            break;
    }

    // Close mobile menu
    if (window.innerWidth <= 760) {
        document.getElementById('hamburgerMenu')?.classList.remove('active');
        document.getElementById('sidebar')?.classList.remove('open');
    }
}

// ==========================================
// Amortization Calculator
// ==========================================
function generateAmortizationSchedule(principal, annualRatePercent, years, startDate) {
    const annualRate = Number(annualRatePercent) / 100;
    const monthlyRate = annualRate / 12;
    const totalMonths = Math.max(1, Math.round(Number(years) * 12));

    let monthlyPayment;
    if (monthlyRate === 0) {
        monthlyPayment = principal / totalMonths;
    } else {
        monthlyPayment = principal * (monthlyRate * Math.pow(1 + monthlyRate, totalMonths)) / (Math.pow(1 +
            monthlyRate, totalMonths) - 1);
    }

    let balance = principal;
    const schedule = [];
    const baseDate = startDate ? new Date(startDate) : new Date();

    for (let i = 1; i <= totalMonths; i++) {
        const interestPayment = balance * monthlyRate;
        let principalPayment = monthlyPayment - interestPayment;

        if (i === totalMonths) {
            principalPayment = balance;
            monthlyPayment = principalPayment + interestPayment;
            balance = 0;
        } else {
            balance = Math.max(0, balance - principalPayment);
        }

        const paymentDate = new Date(baseDate.getFullYear(), baseDate.getMonth() + i, baseDate.getDate());

        schedule.push({
            paymentNumber: i,
            paymentDate: fmtDate(paymentDate),
            monthlyPayment: Math.round(monthlyPayment),
            principal: Math.round(principalPayment),
            interest: Math.round(interestPayment),
            balance: Math.round(balance)
        });
    }

    const totalPaid = schedule.reduce((sum, p) => sum + p.monthlyPayment, 0);
    const totalInterest = schedule.reduce((sum, p) => sum + p.interest, 0);

    return {
        schedule,
        monthlyPayment: Math.round(monthlyPayment),
        totalInterest: Math.round(totalInterest),
        totalPaid: Math.round(totalPaid)
    };
}

// ==========================================
// Dashboard Loading
// ==========================================
async function loadDashboard() {
    try {
        //console.log('=== 🚀 loadDashboard started ===');
        //console.log('Current user:', currentUser);

        // Safety check
        if (!currentUser || !currentUser.pf_number) {
            //console.error('currentUser is null or missing pf_number');
            showNotification('Session error. Please refresh page.', 'error');
            return;
        }

        // Update user information display
        if (userNameElement) userNameElement.textContent = currentUser.full_name || 'Loanee';
        if (userPfElement) userPfElement.textContent = currentUser.pf_number || 'N/A';
        if (fixedRateDisplay) fixedRateDisplay.textContent = parseFloat(currentInterestRate).toFixed(1);

        // Clear previous content
        if (latestLoan) latestLoan.innerHTML = '';
        if (noLoan) noLoan.classList.add('hidden');

        // Show loading state
        if (latestLoan) {
            latestLoan.innerHTML = '<div class="loading-content">Loading loan data...</div>';
            latestLoan.classList.remove('hidden');
        }

        // Fetch loan applications
        //console.log('📞 Calling API: loans.php');
        const result = await apiRequest('loans.php', 'GET');

        //console.log('<i data-lucide="check-circle" class="icon-sm"></i> API Response:', result);

        if (!result || !result.data) {
            throw new Error('Invalid response from API - missing data property');
        }

        const loans = result.data;
        //console.log(`<i data-lucide="bar-chart-3" class="icon-sm"></i> Total loans received: ${loans.length}`);

        // Match the same identity fields used by Loan History.
        // Some older loan records have a NULL PF number but still have the
        // correct user_id or ID number.
        const userLoans = loans.filter(loan => {
            const loanUserId = parseInt(loan.user_id) || 0;
            const currentUserId = parseInt(currentUser.id) || 0;
            const loanPf = String(loan.pf_number || '').trim();
            const currentPf = String(currentUser.pf_number || '').trim();
            const loanIdNumber = String(loan.id_number || '').trim();
            const currentIdNumber = String(currentUser.id_number || '').trim();

            const matches =
                (loanUserId > 0 && currentUserId > 0 && loanUserId === currentUserId) ||
                (loanPf && currentPf && loanPf === currentPf) ||
                (loanIdNumber && currentIdNumber && loanIdNumber === currentIdNumber);

            if (matches) {
                //console.log(`<i data-lucide="check-circle" class="icon-sm"></i> Loan ${loan.application_id} matches PF ${currentPf}`);
            }

            return matches;
        });

        /*console.log(
        `<i data-lucide="user" class="icon-sm"></i> User loans filtered: ${userLoans.length}
         (showing ONLY for PF: ${currentUser.pf_number})`);*/

        // Handle case with no loans
        if (userLoans.length === 0) {
            //console.log('ℹ️ No loans found for user');
            if (latestLoan) latestLoan.classList.add('hidden');
            if (noLoan) noLoan.classList.remove('hidden');
            return;
        }

        // Sort loans by date (newest first)
        userLoans.sort((a, b) => {
            const dateA = new Date(a.application_date || 0).getTime();
            const dateB = new Date(b.application_date || 0).getTime();
            return dateB - dateA;
        });

        // Get most recent loan
        const latest = userLoans[0];
        //console.log('📌 Latest loan:', latest.application_id);

        // Show loan details
        if (latestLoan && noLoan) {
            latestLoan.classList.remove('hidden');
            noLoan.classList.add('hidden');
            displayLoanDetails(latest);
        }
    } catch (error) {
        //console.error('<i data-lucide="x-circle" class="icon-sm"></i> Error loading dashboard:', error);
        showNotification('Error loading dashboard: ' + error.message, 'error');

        if (latestLoan) {
            latestLoan.innerHTML = `
            <div class="error">
                <strong>Failed to load loan data</strong><br>
                Error: ${error.message}<br>
                <button onclick="location.reload()" style="margin-top: 10px;">Reload Page</button>
            </div>
        `;
            latestLoan.classList.remove('hidden');
        }
        if (noLoan) noLoan.classList.add('hidden');
    }
}

// Helper function to display loan details
function displayLoanDetails(latest) {
    try {
        // Determine status and badge color
        const status = String(latest.status || 'pending').toLowerCase();
        let badgeColor = '#fff3cd';
        if (status === 'closed' || status === 'dispatched') badgeColor = '#d4edda';
        else if (status === 'rejected') badgeColor = '#f8d7da';
        else if (status === 'returned') badgeColor = '#ffeaa7';

        // Create loan type specific details
        let loanTypeDetails = '';
        if (latest.loan_type === 'mortgage') {
            loanTypeDetails = `
                <div><strong>Property Location:</strong> ${latest.property_location || 'N/A'}</div>
                <div><strong>Property Value:</strong> KES ${fmtCurrency(latest.property_value || 0)}</div>
            `;
        } else if (latest.loan_type === 'car') {
            loanTypeDetails = `
                <div><strong>Car Type:</strong> ${latest.car_type || 'N/A'}</div>
                <div><strong>Car Value:</strong> KES ${fmtCurrency(latest.car_value || 0)}</div>
            `;
        }

        // Create employment details
        let employmentDetails = '';
        if (latest.employment_type === 'contracted') {
            employmentDetails = `
                <div><strong>Contract Period:</strong> ${latest.contract_period || 'N/A'} months</div>
                <div><strong>Contract Start:</strong> ${latest.contract_start_date ? fmtDate(latest.contract_start_date) : 'N/A'}</div>
                <div><strong>Contract End:</strong> ${latest.contract_end_date ? fmtDate(latest.contract_end_date) : 'N/A'}</div>
            `;
        }

        // Create processing dates
        let processingDates = '';
        if (latest.cashier_processed_date) {
            processingDates +=
                `<div><strong>Cashier Processed:</strong> ${fmtDate(latest.cashier_processed_date)}</div>`;
        }
        if (latest.accountant_processed_date) {
            processingDates +=
                `<div><strong>Accountant Processed:</strong> ${fmtDate(latest.accountant_processed_date)}</div>`;
        }
        if (latest.dispatch_date) {
            processingDates += `<div><strong>Dispatch Date:</strong> ${fmtDate(latest.dispatch_date)}</div>`;
        }
        if (latest.closed_date) {
            processingDates += `<div><strong>Closed Date:</strong> ${fmtDate(latest.closed_date)}</div>`;
        }

        // Create a button to view payment schedule if loan is dispatched
        let paymentScheduleButton = '';
        if (status === 'dispatched' && latest.dispatch_date) {
            paymentScheduleButton = `
                <div style="margin-top:15px; text-align:center;">
                    <button id="viewPaymentSchedule" class="primary-button" style="background: linear-gradient(135deg, #4a6b4a 0%, #5a8a5a 100%);">
                        View Payment Schedule
                    </button>
                </div>
            `;
        }

        // Create a button to apply for another loan
        let applyAnotherButton = '';
        if (status === 'dispatched' || status === 'active' || status === 'closed') {
            applyAnotherButton = `
                <div style="margin-top:15px; text-align:center;">
                    <button id="applyAnotherLoan" class="secondary-button" style="background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);">
                        Apply for Another Loan
                    </button>
                </div>
            `;
        }

        // Build complete HTML for latest loan
        latestLoan.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px">
                <strong>Latest Application</strong>
                <span style="padding:6px 12px;border-radius:12px;background:${badgeColor};color:#333">
                    ${latest.status || 'pending'}
                </span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px">
                <div>
                    <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Application Details</h4>
                    <div><strong>Application Date:</strong> ${latest.application_date ? fmtDate(latest.application_date) : 'N/A'}</div>
                    <div><strong>Full Name:</strong> ${latest.full_name || 'N/A'}</div>
                    <div><strong>ID Number:</strong> ${latest.id_number || 'N/A'}</div>
                    <div><strong>PF Number:</strong> ${latest.pf_number || 'N/A'}</div>
                    <div><strong>Email:</strong> ${latest.email || 'N/A'}</div>
                </div>
                
                <div>
                    <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Loan Details</h4>
                    <div><strong>Application ID:</strong> ${latest.application_id || 'N/A'}</div>
                    <div><strong>Loan Type:</strong> ${latest.loan_type ? String(latest.loan_type).charAt(0).toUpperCase() + String(latest.loan_type).slice(1) : 'N/A'}</div>
                    <div><strong>Loan Amount:</strong> KES ${fmtCurrency(latest.loan_amount || 0)}</div>
                    <div><strong>Payment Duration:</strong> ${latest.payment_duration || 'N/A'} years</div>
                    <div><strong>Payment Frequency:</strong> ${latest.payment_frequency || 'monthly'}</div>
                    <div><strong>Basic Salary:</strong> KES ${fmtCurrency(latest.basic_salary || 0)}</div>
                    <div><strong>Employment Type:</strong> ${latest.employment_type || 'N/A'}</div>
                </div>
            </div>

            ${loanTypeDetails ? `
            <div style="margin-top:15px">
                <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Loan Type Details</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px">
                    ${loanTypeDetails}
                </div>
            </div>
            ` : ''}

            ${employmentDetails ? `
            <div style="margin-top:15px">
                <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Employment Details</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px">
                    ${employmentDetails}
                </div>
            </div>
            ` : ''}

            <div style="margin-top:15px">
                <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Banking Details</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px">
                    <div><strong>Bank Name:</strong> ${latest.bank_name || 'N/A'}</div>
                    <div><strong>Bank Branch:</strong> ${latest.bank_branch || 'N/A'}</div>
                    <div><strong>Account Number:</strong> ${latest.bank_account_number || 'N/A'}</div>
                </div>
            </div>

            <div style="margin-top:15px">
                <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Processing Timeline</h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px">
                    ${processingDates}
                </div>
            </div>

            ${latest.feedback ? `
            <div style="margin-top:15px">
                <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Feedback</h4>
                <div style="background:#f8f9fa;padding:10px;border-radius:6px;border-left:4px solid #4a6b4a">
                    ${latest.feedback}
                </div>
            </div>
            ` : ''}

            ${paymentScheduleButton}
            ${applyAnotherButton}
        `;

        // Add event listeners for buttons
        const viewScheduleBtn = document.getElementById('viewPaymentSchedule');
        if (viewScheduleBtn) {
            viewScheduleBtn.addEventListener('click', () => {
                showSection('payment-schedule');
            });
        }

        const applyAnotherBtn = document.getElementById('applyAnotherLoan');
        if (applyAnotherBtn) {
            applyAnotherBtn.addEventListener('click', () => {
                showSection('newapp');
            });
        }
    } catch (error) {
        //console.error('Error displaying loan details:', error);
        if (latestLoan) {
            latestLoan.innerHTML = `
                <div class="error">
                    <strong>Error displaying loan details</strong><br>
                    ${error.message}
                </div>
            `;
        }
    }
}

// ==========================================
// Payment History Rendering
// ==========================================
async function renderPaymentHistory() {
    const container = document.getElementById('paymentsTableBody');
    if (!paymentsTableBody) {
        //console.error('paymentsTableBody not found!');
        return;
    }

    // Show loading state
    paymentsTableBody.innerHTML = `
    <tr>
        <td colspan="9" class="no-payments">
            <div style="padding: 20px;">
                <div class="loading-indicator"></div>
                Loading payment history...
            </div>
        </td>
    </tr>
`;

    try {
        // Safety check for current user
        if (!currentUser || !currentUser.pf_number) {
            //console.error('Current user or PF number is missing');
            paymentsTableBody.innerHTML = `
            <tr>
                <td colspan="9" class="no-payments">
                    <div style="padding: 20px; text-align: center;">
                        <p>Session error. Please refresh the page.</p>
                    </div>
                </td>
            </tr>
        `;
            return;
        }

        // Get current user's loans first
        const loans = await getLoanApplications();

        // CRITICAL FIX: Filter loans STRICTLY by current user's PF number ONLY
        // This ensures staff members can ONLY see their own payments when viewing as loanee
        const userLoans = loans.filter(loan => {
            const loanPf = String(loan.pf_number || '').trim();
            const currentPf = String(currentUser.pf_number || '').trim();

            // STRICT PF number matching only
            const matches = loanPf === currentPf;

            if (matches) {
                //console.log(`<i data-lucide="check-circle" class="icon-sm"></i> Payment history: Loan ${loan.application_id} matches PF ${currentPf}`);
            }

            return matches;
        });

        //console.log(`Payment History: Found ${userLoans.length} loans for PF ${currentUser.pf_number}`);

        // Get payments ONLY for user's loans
        const allPayments = [];

        for (const loan of userLoans) {
            try {
                const loanId = loan.application_id || loan.id;
                //console.log(`Fetching payments for loan: ${loanId} (PF: ${loan.pf_number})`);

                const result = await apiRequest(`payments.php?application_id=${loanId}`);

                if (result.data && result.data.length > 0) {
                    // Add loan information to each payment (with fallback)
                    const paymentsWithLoanInfo = result.data.map(payment => ({
                        ...payment,
                        loan_id: loanId,
                        application_id: loanId,
                        // Use loan type from payment if available, otherwise from loan object
                        loan_type: payment.loan_type || loan.loan_type || 'unknown',
                        loan_amount: payment.loan_amount || loan.loan_amount,
                        // Store PF number for verification
                        pf_number: loan.pf_number
                    }));

                    allPayments.push(...paymentsWithLoanInfo);
                }
            } catch (error) {
                //console.error(`Error fetching payments for loan ${loan.application_id}:`, error);
            }
        }

        //console.log(`Total payments loaded: ${allPayments.length} (all for PF: ${currentUser.pf_number})`);

        // If no payments found, show a clear message
        if (allPayments.length === 0) {
            paymentsTableBody.innerHTML = `
            <tr>
                <td colspan="9" class="no-payments">
                    <div style="padding: 20px; text-align: center;">
                        <p>No payment records found for your loans.</p>
                        <p>Payment history will appear here once payments are made.</p>
                    </div>
                </td>
            </tr>
        `;

            // Still update summary with zeros
            if (totalPayments) totalPayments.textContent = 'KES 0';
            if (totalPrincipal) totalPrincipal.textContent = 'KES 0';
            if (totalInterest) totalInterest.textContent = 'KES 0';
            return;
        }

        // Populate loan ID filter dropdown
        populateLoanFilter(userLoans);

        // Populate year filter dropdown
        populateYearFilter(allPayments);

        // Calculate totals
        let totalAmount = 0;
        let totalPrincipalAmt = 0;
        let totalInterestAmt = 0;

        allPayments.forEach(payment => {
            totalAmount += parseFloat(payment.amount) || 0;
            totalPrincipalAmt += parseFloat(payment.principal_amount) || 0;
            totalInterestAmt += parseFloat(payment.interest_amount) || 0;
        });

        // Update summary cards
        if (totalPayments) totalPayments.textContent = `KES ${fmtCurrency(totalAmount)}`;
        if (totalPrincipal) totalPrincipal.textContent = `KES ${fmtCurrency(totalPrincipalAmt)}`;
        if (totalInterest) totalInterest.textContent = `KES ${fmtCurrency(totalInterestAmt)}`;

        // Store payments globally for filtering
        window.allUserPayments = allPayments;

        // Display payments
        displayFilteredPayments(allPayments);

    } catch (error) {
        //console.error('Error rendering payment history:', error);
        paymentsTableBody.innerHTML = `
        <tr>
            <td colspan="9" class="no-payments">Error loading payment history. Please try again later.</td>
        </tr>
    `;
    }
}


// ==========================================
// Filter Population Functions
// ==========================================
function populateLoanFilter(payments) {
    const loanFilter = document.getElementById('loanFilter');
    if (!loanFilter) return;

    loanFilter.innerHTML = '<option value="">All Loan IDs</option>';

    // Extract unique loan IDs and their types
    const loanMap = new Map();
    payments.forEach(p => {
        if (p.application_id && !loanMap.has(p.application_id)) {
            loanMap.set(p.application_id, p.loan_type || 'Unknown');
        }
    });

    // Sort loan IDs descending (assuming string comparison works for date-like IDs)
    const sortedLoanIds = Array.from(loanMap.keys()).sort((a, b) => b.localeCompare(a));

    sortedLoanIds.forEach(id => {
        const type = loanMap.get(id).charAt(0).toUpperCase() + loanMap.get(id).slice(1);
        const option = document.createElement('option');
        option.value = id;
        option.textContent = `${id} - ${type}`;
        loanFilter.appendChild(option);
    });
}

function populateYearFilter(payments) {
    const yearFilter = document.getElementById('yearFilter');
    if (!yearFilter) return;

    yearFilter.innerHTML = '<option value="">All Years</option>';

    // Extract unique years
    const years = new Set();
    payments.forEach(p => {
        if (p.payment_date) {
            const year = new Date(p.payment_date).getFullYear();
            if (year) years.add(year);
        }
    });

    // Sort descending (most recent first)
    const sortedYears = Array.from(years).sort((a, b) => b - a);

    sortedYears.forEach(year => {
        const option = document.createElement('option');
        option.value = year;
        option.textContent = year;
        yearFilter.appendChild(option);
    });
}

// ==========================================
// Filter Population Functions
// ==========================================
function populateLoanFilter(loans) {
    if (!loanFilter) return;

    loanFilter.innerHTML = '<option value="">All Loan IDs</option>';

    // Sort loans by application_id from latest to oldest (descending)
    loans.sort((a, b) => {
        const idA = a.application_id || '';
        const idB = b.application_id || '';
        return idB.localeCompare(idA);
    });

    loans.forEach(loan => {
        const option = document.createElement('option');
        option.value = loan.application_id;
        const loanType = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(
                1) :
            '';
        option.textContent =
            `${loan.application_id} - ${loanType} (KES ${fmtCurrency(loan.loan_amount || 0)})`;
        loanFilter.appendChild(option);
    });
}


// ==========================================
// Display Filtered Payments
// ==========================================
// Around line 1150-1200, update the displayFilteredPayments function:

function displayFilteredPayments(payments) {
    const container = document.getElementById('paymentsTableBody');
    if (!paymentsTableBody) return;

    // Get filter values
    const loanId = document.getElementById('loanFilter')?.value || '';
    const loanType = document.getElementById('typeFilter')?.value.toLowerCase() || '';
    const year = document.getElementById('yearFilter')?.value || '';
    const source = document.getElementById('sourceFilter')?.value.toLowerCase() || '';

    let filtered = payments.filter(p => {
        const matchesLoanId = !loanId || p.application_id === loanId;
        const matchesType = !loanType || (p.loan_type || '').toLowerCase() === loanType;
        const paymentYear = p.payment_date ? new Date(p.payment_date).getFullYear() : null;
        const matchesYear = !year || paymentYear === parseInt(year);
        const matchesSource = !source || (p.payment_source || '').toLowerCase() === source;

        return matchesLoanId && matchesType && matchesYear && matchesSource;
    });

    container.innerHTML = '';
    if (filtered.length === 0) {
        container.innerHTML =
            '<tr><td colspan="9" class="no-payments">No payments match the filters.</td></tr>';
    } else {
        filtered.forEach(p => {
            const row = document.createElement('tr');

            // Handle loan type display - with fallback
            let displayType = 'N/A';
            if (p.loan_type) {
                displayType = p.loan_type.charAt(0).toUpperCase() + p.loan_type.slice(1);
            }

            // Handle payment source display
            const displaySource = (p.payment_source || '').replace(/_/g, ' ').split(' ')
                .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                .join(' ') || 'N/A';

            row.innerHTML = `
            <td>${fmtDate(p.payment_date)}</td>
            <td>${p.application_id || 'N/A'}</td>
            <td><span class="loan-badge ${p.loan_type || ''}">${displayType}</span></td>
            <td><strong>KES ${fmtCurrency(p.amount)}</strong></td>
            <td>KES ${fmtCurrency(p.principal_amount)}</td>
            <td>KES ${fmtCurrency(p.interest_amount)}</td>
            <td>KES ${fmtCurrency(p.balance_after)}</td>
            <td><span class="payment-source-badge ${(p.payment_source || '').split('_')[0]}">${displaySource}</span></td>
            <td>${p.transaction_reference || '-'}</td>
        `;
            container.appendChild(row);
        });
    }

    // Update totals based on filtered data
    updatePaymentTotals(filtered);
}


// ==========================================
// API Functions
// ==========================================
async function getLoanApplications() {
    try {
        const result = await apiRequest('loans.php', 'GET');
        return result.data || [];
    } catch (error) {
        //console.error('Error getting loan applications:', error);
        showNotification('Unable to load loan applications', 'error');
        return [];
    }
}

// ==========================================
// Loan Application Functions
// ==========================================
async function createLoanApplication(loanData) {
    try {
        const result = await apiRequest('loans.php', 'POST', {
            ...loanData,
            pf_number: currentUser.pf_number,
            id_number: currentUser.id_number,
            email: currentUser.email
        });
        return result.success;
    } catch (error) {
        //console.error('Loan submission error:', error);
        showNotification(error.message || 'Submission failed', 'error');
        return false;
    }
}

// ==========================================
// Generate Unique Loan ID Function
// ==========================================
function generateUniqueLoanId() {
    const timestamp = Date.now().toString();
    const random = Math.floor(1000 + Math.random() * 9000).toString();
    const micro = timestamp.slice(-4);
    const now = new Date();
    const year = now.getFullYear().toString();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hour = String(now.getHours()).padStart(2, '0');
    const minute = String(now.getMinutes()).padStart(2, '0');
    const second = String(now.getSeconds()).padStart(2, '0');
    return year + month + day + hour + minute + second + micro + random;
}

// ==========================================
// Loan Application Form
// ==========================================
if (loanForm) {
    loanForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (loanSuccess) loanSuccess.textContent = '';

        if (!currentUser || !currentUser.pf_number) {
            showNotification("Session expired. Please log in again.", 'error');
            if (loanSuccess) {
                loanSuccess.textContent = 'Session expired. Please log in again.';
                loanSuccess.style.color = 'red';
            }
            return;
        }

        const loanId = generateUniqueLoanId();
        const loanData = {
            application_id: loanId,
            loan_type: loanType.value,
            loan_amount: Number(expectedLoan.value),
            payment_duration: Number(paymentYears.value),
            payment_frequency: 'monthly',
            basic_salary: Number(basicSalary.value),
            employment_type: employmentType.value,
            bank_name: bankName.value,
            bank_branch: bankBranch.value.trim(),
            bank_account_number: bankAccount.value.trim(),
            full_name: currentUser.full_name,
            id_number: currentUser.id_number,
            pf_number: currentUser.pf_number,
            email: currentUser.email
        };

        // Add loan type specific data
        if (employmentType.value === 'contracted') {
            const contractPeriodInput = document.getElementById('contractPeriod');
            const contractStartInput = document.getElementById('contractStart');
            const contractEndInput = document.getElementById('contractEnd');
            if (contractPeriodInput && contractPeriodInput.value) loanData.contract_period = Number(
                contractPeriodInput.value);
            if (contractStartInput && contractStartInput.value) loanData.contract_start_date =
                contractStartInput.value;
            if (contractEndInput && contractEndInput.value) loanData.contract_end_date =
                contractEndInput.value;
        }

        if (loanType.value === 'mortgage') {
            const propertyLocationInput = document.getElementById('propertyLocation');
            const propertyValueInput = document.getElementById('propertyValue');
            if (propertyLocationInput && propertyLocationInput.value) loanData.property_location =
                propertyLocationInput.value.trim();
            if (propertyValueInput && propertyValueInput.value) loanData.property_value = Number(
                propertyValueInput.value);
        } else if (loanType.value === 'car') {
            const carTypeInput = document.getElementById('carType');
            const carValueInput = document.getElementById('carValue');
            if (carTypeInput && carTypeInput.value) loanData.car_type = carTypeInput.value.trim();
            if (carValueInput && carValueInput.value) loanData.car_value = Number(carValueInput
                .value);
        }

        const submitBtn = document.getElementById('submitLoan');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
        }

        try {
            const success = await createLoanApplication(loanData);
            if (success) {
                if (loanSuccess) {
                    loanSuccess.textContent = 'Loan application submitted successfully!';
                    loanSuccess.style.color = 'green';
                }
                showNotification('Loan application submitted successfully', 'success');
                await loadDashboard();
                setTimeout(() => {
                    if (loanSuccess) loanSuccess.textContent = '';
                    if (loanForm) loanForm.reset();
                    if (mortgageFields) mortgageFields.classList.add('hidden');
                    if (carFields) carFields.classList.add('hidden');
                    if (contractDetails) contractDetails.classList.add('hidden');
                    if (bankName) bankName.value = "Family Bank";
                    // Show eligibility check again for another application
                    if (eligibilityCheck) eligibilityCheck.classList.remove('hidden');
                    if (loanForm) loanForm.classList.add('hidden');
                    showSection('dashboard');
                }, 1500);
            } else {
                if (loanSuccess) {
                    loanSuccess.textContent =
                        'Failed to submit loan application. Please try again.';
                    loanSuccess.style.color = 'red';
                }
                showNotification('Submission failed. Please try again.', 'error');
            }
        } catch (error) {
            if (loanSuccess) {
                loanSuccess.textContent = 'Error: ' + (error.message || 'Please try again.');
                loanSuccess.style.color = 'red';
            }
            showNotification('Submission error: ' + error.message, 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Loan Application';
            }
        }
    });
}

// ==========================================
// Event Bindings
// ==========================================
document.querySelectorAll('#sidebar button[data-section]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('#sidebar button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const section = btn.dataset.section;
        showSection(section);
        if (window.innerWidth <= 760 && hamburger) {
            hamburger.classList.remove('active');
            sidebar.classList.remove('open');
        }
    });
});

if (loanType) {
    loanType.addEventListener('change', () => {
        const val = loanType.value.toLowerCase();
        if (mortgageFields) {
            if (val === 'mortgage') {
                mortgageFields.classList.remove('hidden');
                document.getElementById('propertyLocation')?.setAttribute('required', 'required');
                document.getElementById('propertyValue')?.setAttribute('required', 'required');
            } else {
                mortgageFields.classList.add('hidden');
                document.getElementById('propertyLocation')?.removeAttribute('required');
                document.getElementById('propertyValue')?.removeAttribute('required');
            }
        }
        if (carFields) {
            if (val === 'car') {
                carFields.classList.remove('hidden');
                document.getElementById('carType')?.setAttribute('required', 'required');
                document.getElementById('carValue')?.setAttribute('required', 'required');
            } else {
                carFields.classList.add('hidden');
                document.getElementById('carType')?.removeAttribute('required');
                document.getElementById('carValue')?.removeAttribute('required');
            }
        }
    });
}

if (employmentType) {
    employmentType.addEventListener('change', () => {
        const val = employmentType.value.toLowerCase();
        if (contractDetails) {
            if (val === 'contracted') {
                contractDetails.classList.remove('hidden');
                document.getElementById('contractPeriod')?.setAttribute('required', 'required');
                document.getElementById('contractStart')?.setAttribute('required', 'required');
                document.getElementById('contractEnd')?.setAttribute('required', 'required');
            } else {
                contractDetails.classList.add('hidden');
                document.getElementById('contractPeriod')?.removeAttribute('required');
                document.getElementById('contractStart')?.removeAttribute('required');
                document.getElementById('contractEnd')?.removeAttribute('required');
            }
        }
    });
}

if (precheckEmploymentType) {
    precheckEmploymentType.addEventListener('change', () => {
        const val = precheckEmploymentType.value.toLowerCase();
        if (val === 'contracted' && precheckContractDetails) {
            precheckContractDetails.classList.remove('hidden');
        } else if (precheckContractDetails) {
            precheckContractDetails.classList.add('hidden');
        }
    });
}

if (precheckLoanType) {
    precheckLoanType.addEventListener('change', () => {
        const val = precheckLoanType.value.toLowerCase();
        if (val === 'mortgage' && precheckPropertyValue) {
            precheckPropertyValue.classList.remove('hidden');
            precheckCarValue.classList.add('hidden');
            checkPropertyValue?.setAttribute('required', 'required');
            checkCarValue?.removeAttribute('required');
        } else if (val === 'car' && precheckCarValue) {
            precheckCarValue.classList.remove('hidden');
            precheckPropertyValue.classList.add('hidden');
            checkCarValue?.setAttribute('required', 'required');
            checkPropertyValue?.removeAttribute('required');
        } else {
            if (precheckPropertyValue) precheckPropertyValue.classList.add('hidden');
            if (precheckCarValue) precheckCarValue.classList.add('hidden');
            checkPropertyValue?.removeAttribute('required');
            checkCarValue?.removeAttribute('required');
        }
    });
}

if (precheckEmploymentType) {
    precheckEmploymentType.addEventListener('change', () => {
        const val = precheckEmploymentType.value.toLowerCase();
        if (val === 'permanent') {
            if (precheckRetirementDetails) precheckRetirementDetails.classList.remove('hidden');
            if (precheckContractDetails) precheckContractDetails.classList.add('hidden');
            precheckRetirementDate?.setAttribute('required', 'required');
            precheckContractEnd?.removeAttribute('required');
        } else if (val === 'contracted') {
            if (precheckContractDetails) precheckContractDetails.classList.remove('hidden');
            if (precheckRetirementDetails) precheckRetirementDetails.classList.add('hidden');
            precheckContractEnd?.setAttribute('required', 'required');
            precheckRetirementDate?.removeAttribute('required');
        } else {
            if (precheckRetirementDetails) precheckRetirementDetails.classList.add('hidden');
            if (precheckContractDetails) precheckContractDetails.classList.add('hidden');
            precheckRetirementDate?.removeAttribute('required');
            precheckContractEnd?.removeAttribute('required');
        }
    });
}

if (checkEligibilityBtn) {
    checkEligibilityBtn.addEventListener('click', checkLoanEligibility);
}

if (cancelLoan) {
    cancelLoan.addEventListener('click', () => {
        if (loanForm) loanForm.reset();
        if (bankName) bankName.value = "Family Bank";
        if (loanForm) loanForm.classList.add('hidden');
        if (eligibilityCheck) eligibilityCheck.classList.remove('hidden');
        if (eligibilityResult) eligibilityResult.classList.add('hidden');
        if (breakdownBox) breakdownBox.classList.add('hidden');

        // Reset readonly fields
        if (expectedLoan) {
            expectedLoan.readOnly = false;
            expectedLoan.style.backgroundColor = '#fff';
            expectedLoan.style.cursor = 'text';
        }
        if (paymentYears) {
            paymentYears.readOnly = false;
            paymentYears.style.backgroundColor = '#fff';
            paymentYears.style.cursor = 'text';
        }
        if (basicSalary) {
            basicSalary.readOnly = false;
            basicSalary.style.backgroundColor = '#fff';
            basicSalary.style.cursor = 'text';
        }
    });
}

if (newPassword) {
    newPassword.addEventListener('input', () => {
        updatePasswordStrengthIndicator(newPassword.value);
    });
}

if (calcBtn) {
    calcBtn.addEventListener('click', () => {
        const amount = parseFloat(calcAmount.value) || 0;
        const term = parseFloat(calcTerm.value) || 0;
        if (amount <= 0 || term <= 0) {
            if (calcSummary) calcSummary.classList.add('hidden');
            if (amortContainer) amortContainer.classList.add('hidden');
            showNotification('Please enter valid loan amount and term', 'error');
            return;
        }
        const result = generateAmortizationSchedule(amount, currentInterestRate, term, new Date());
        if (sumAmount) sumAmount.textContent = fmtCurrency(amount);
        if (sumRate) sumRate.textContent = currentInterestRate;
        if (sumTerm) sumTerm.textContent = term;
        if (sumMonthly) sumMonthly.textContent = fmtCurrency(result.monthlyPayment);
        if (sumInterest) sumInterest.textContent = fmtCurrency(result.totalInterest);
        if (sumTotal) sumTotal.textContent = fmtCurrency(result.totalPaid);
        if (amortBody) {
            amortBody.innerHTML = '';
            result.schedule.forEach(row => {
                amortBody.innerHTML += `
                    <tr>
                        <td style="text-align:left;">${row.paymentNumber}</td>
                        <td style="text-align:left;">${row.paymentDate}</td>
                        <td>${fmtCurrency(row.monthlyPayment)}</td>
                        <td>${fmtCurrency(row.principal)}</td>
                        <td>${fmtCurrency(row.interest)}</td>
                        <td>${fmtCurrency(row.balance)}</td>
                    </tr>
                `;
            });
        }
        if (calcSummary) calcSummary.classList.remove('hidden');
        if (amortContainer) amortContainer.classList.remove('hidden');
    });
}

// Replace with a single event listener for the Apply Filter button
const applyFilterBtn = document.getElementById('applyFilterBtn');
if (applyFilterBtn) {
    applyFilterBtn.addEventListener('click', () => {
        if (window.allUserPayments) displayFilteredPayments(window.allUserPayments);
    });
}

// Also add a reset filter button functionality
const resetFilterBtn = document.createElement('button');
resetFilterBtn.textContent = 'Reset Filters';
resetFilterBtn.style.marginTop = '8px';
resetFilterBtn.style.marginLeft = '10px';
resetFilterBtn.className = 'secondary';
resetFilterBtn.addEventListener('click', () => {
    if (loanFilter) loanFilter.value = '';
    if (yearFilter) yearFilter.value = '';
    if (sourceFilter) sourceFilter.value = '';
    if (window.allUserPayments) displayFilteredPayments(window.allUserPayments);
});

// Add the reset button next to the apply button
if (applyFilterBtn && applyFilterBtn.parentNode) {
    applyFilterBtn.parentNode.appendChild(resetFilterBtn);
}

if (hamburger) {
    hamburger.addEventListener('click', () => {
        hamburger.classList.toggle('active');
        sidebar.classList.toggle('open');
        if (mainContent) {
            mainContent.classList.toggle('sidebar-open');
        }
        if (window.innerWidth > 760) {
            sidebar.classList.toggle('collapsed');
            if (mainContent) {
                mainContent.classList.toggle('sidebar-collapsed');
            }
        }
    });
}

if (profileBadge) {
    profileBadge.addEventListener('click', () => {
        showSection('profile');
        if (window.innerWidth <= 760 && hamburger) {
            hamburger.classList.remove('active');
            sidebar.classList.remove('open');
        }
    });
}

const logoutBtn = document.getElementById('logoutBtn');
if (logoutBtn) {
    logoutBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!confirm('Are you sure you want to logout?')) return;
        logoutBtn.disabled = true;
        const originalHTML = logoutBtn.innerHTML;
        logoutBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Logging out...';
        
        try {
            const response = await fetch('../api/logout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ action: 'logout' })
            });
            const data = await response.json();
            if (data.success) {
                window.location.href = '/loan-system/index.php';
            } else {
                alert(data.message || 'Logout failed');
                logoutBtn.disabled = false;
                logoutBtn.innerHTML = originalHTML;
            }
        } catch (error) {
            console.error('Logout error:', error);
            // Redirect anyway on error
            window.location.href = '/loan-system/index.php';
        }
    });
}

// ==========================================
// Loan History Rendering
// ==========================================
async function renderLoanHistory() {
    const container = document.getElementById('loanHistoryGrid');
    if (!container) return;

    container.innerHTML = '<div class="loading">Loading loan history...</div>';

    try {
        const loans = await getLoanApplications();
        
        // Filter for current user
        const userLoans = loans.filter(loan => {
            const loanUserId = parseInt(loan.user_id) || 0;
            const currentUserId = parseInt(currentUser.id) || 0;
            const loanPf = String(loan.pf_number || '').trim();
            const currentPf = String(currentUser.pf_number || '').trim();
            const loanIdNum = String(loan.id_number || '').trim();
            const currentIdNum = String(currentUser.id_number || '').trim();

            if (loanUserId > 0 && currentUserId > 0 && loanUserId === currentUserId) return true;
            if (loanPf && currentPf && loanPf === currentPf) return true;
            if (loanIdNum && currentIdNum && loanIdNum === currentIdNum) return true;
            return false;
        });

        if (!userLoans || userLoans.length === 0) {
            container.innerHTML = `
            <div class="empty-state" style="text-align:center; padding:40px; background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.05);">
                <div style="width:80px; height:80px; background:#f8f9fa; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">
                    <span class="button-icon"><i data-lucide="clipboard-list"></i></span>
                </div>   
                <h3 style="color:#6c757d; margin-bottom:10px;">No Loan Applications Yet</h3>
                <p style="color:#999;">Your loan application history will appear here once you apply.</p>
                <button onclick="showSection('newapp')" style="margin-top:20px; background: linear-gradient(135deg, #4a6b4a 0%, #5a8a5a 100%); border: none; color: white; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
                    Apply for Your First Loan
                </button>
            </div>
        `;
            return;
        }

        // Sort by application date (newest first)
        userLoans.sort((a, b) => new Date(b.application_date) - new Date(a.application_date));

        let html = '<div class="loan-history-grid">';

        userLoans.forEach(loan => {
            const status = String(loan.status || 'pending').toLowerCase();
            let statusBadgeColor = '#fff3cd';
            let statusTextColor = '#856404';

            if (status === 'closed' || status === 'disbursed') {
                statusBadgeColor = '#d4edda';
                statusTextColor = '#155724';
            } else if (status === 'rejected') {
                statusBadgeColor = '#f8d7da';
                statusTextColor = '#721c24';
            } else if (status === 'returned') {
                statusBadgeColor = '#ffeaa7';
                statusTextColor = '#856404';
            }

            const statusText = loan.status ? loan.status.charAt(0).toUpperCase() + loan.status.slice(
                1) : 'Pending';
            const loanType = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type
                .slice(1) : 'N/A';
            const loanAmount = parseFloat(loan.loan_amount || 0);

            // Create loan type specific details
            let loanTypeDetails = '';
            if (loan.loan_type === 'mortgage') {
                loanTypeDetails = `
                <div class="detail-row">
                    <span class="detail-label">Property Location:</span>
                    <span class="detail-value">${loan.property_location || 'N/A'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Property Value:</span>
                    <span class="detail-value">KES ${fmtCurrency(loan.property_value || 0)}</span>
                </div>
            `;
            } else if (loan.loan_type === 'car') {
                loanTypeDetails = `
                <div class="detail-row">
                    <span class="detail-label">Car Type:</span>
                    <span class="detail-value">${loan.car_type || 'N/A'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Car Value:</span>
                    <span class="detail-value">KES ${fmtCurrency(loan.car_value || 0)}</span>
                </div>
            `;
            }

            // Create employment details
            let employmentDetails = '';
            if (loan.employment_type === 'contracted') {
                employmentDetails = `
                <div class="detail-section">
                    <h4 class="section-title">Employment Details</h4>
                    <div class="detail-row">
                        <span class="detail-label">Contract Period:</span>
                        <span class="detail-value">${loan.contract_period || 'N/A'} months</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Contract Start:</span>
                        <span class="detail-value">${loan.contract_start_date ? fmtDate(loan.contract_start_date) : 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Contract End:</span>
                        <span class="detail-value">${loan.contract_end_date ? fmtDate(loan.contract_end_date) : 'N/A'}</span>
                    </div>
                </div>
            `;
            }

            // Create processing timeline
            let processingTimeline = '';
            const timelineItems = [];

            if (loan.application_date) {
                timelineItems.push({
                    date: loan.application_date,
                    label: 'Applied'
                });
            }
            if (loan.cashier_processed_date) {
                timelineItems.push({
                    date: loan.cashier_processed_date,
                    label: 'Cashier Processed'
                });
            }
            if (loan.accountant_processed_date) {
                timelineItems.push({
                    date: loan.accountant_processed_date,
                    label: 'Accountant Processed'
                });
            }
            if (loan.dispatch_date) {
                timelineItems.push({
                    date: loan.dispatch_date,
                    label: 'Disbursed'
                });
            }
            if (loan.closed_date) {
                timelineItems.push({
                    date: loan.closed_date,
                    label: 'Closed'
                });
            }

            if (timelineItems.length > 0) {
                processingTimeline = `
                <div class="detail-section">
                    <h4 class="section-title">Processing Timeline</h4>
                    <div class="timeline">
                        ${timelineItems.map(item => `
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-content">
                                    <span class="timeline-label">${item.label}</span>
                                    <span class="timeline-date">${fmtDate(item.date)}</span>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
            }

            html += `
            <div class="loan-history-card">
                <div class="loan-card-header">
                    <div class="loan-header-left">
                        <h3 class="loan-title">${loanType} Loan</h3>
                        <p class="loan-id">ID: ${loan.application_id || 'N/A'}</p>
                    </div>
                    <div class="loan-header-right">
                        <span class="status-badge" style="background:${statusBadgeColor}; color:${statusTextColor};">
                            ${statusText}
                        </span>
                    </div>
                </div>

                <div class="loan-card-body">
                    <div class="detail-section">
                        <h4 class="section-title">Loan Information</h4>
                        <div class="detail-row">
                            <span class="detail-label">Application Date:</span>
                            <span class="detail-value">${loan.application_date ? fmtDate(loan.application_date) : 'N/A'}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Loan Amount:</span>
                            <span class="detail-value amount-highlight">KES ${fmtCurrency(loanAmount)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Payment Duration:</span>
                            <span class="detail-value">${loan.payment_duration || 'N/A'} years</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Payment Frequency:</span>
                            <span class="detail-value">${loan.payment_frequency || 'monthly'}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Basic Salary:</span>
                            <span class="detail-value">KES ${fmtCurrency(loan.basic_salary || 0)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Employment Type:</span>
                            <span class="detail-value">${loan.employment_type || 'N/A'}</span>
                        </div>
                        ${loanTypeDetails}
                    </div>

                    ${employmentDetails}

                    <div class="detail-section">
                        <h4 class="section-title">Banking Details</h4>
                        <div class="detail-row">
                            <span class="detail-label">Bank Name:</span>
                            <span class="detail-value">${loan.bank_name || 'N/A'}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Bank Branch:</span>
                            <span class="detail-value">${loan.bank_branch || 'N/A'}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Account Number:</span>
                            <span class="detail-value">${loan.bank_account_number || 'N/A'}</span>
                        </div>
                    </div>

                    ${processingTimeline}

                    ${loan.feedback ? `
                        <div class="detail-section">
                            <h4 class="section-title">Feedback</h4>
                            <div class="feedback-box">
                                ${loan.feedback}
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
        });

        html += '</div>';
        container.innerHTML = html;

    } catch (err) {
        console.error('Loan history error:', err);
        container.innerHTML = '<p class="error">Failed to load loan history. Please try again later.</p>';
    }
}

// ==========================================
// Payment History Rendering
// ==========================================
function renderPaymentHistory() {
    const container = document.getElementById('paymentsTableBody');
    if (!container) {
        //console.error('paymentsTableBody not found!');
        return;
    }

    container.innerHTML = '<tr><td colspan="9" class="no-payments">Loading payment history...</td></tr>';

    fetch('../api/payments.php')
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data && res.data.length > 0) {
                window.allUserPayments = res.data;

                // Populate filters
                populateLoanFilter(res.data);
                populateYearFilter(res.data);

                // Display all payments initially
                displayFilteredPayments(res.data);

                // Add filter button listeners (only once)
                const applyBtn = document.getElementById('applyFilterBtn');
                const resetBtn = document.getElementById('resetFilterBtn');
                if (applyBtn && !applyBtn.hasListener) {
                    applyBtn.addEventListener('click', () => displayFilteredPayments(window
                        .allUserPayments));
                    applyBtn.hasListener = true;
                }
                if (resetBtn && !resetBtn.hasListener) {
                    resetBtn.addEventListener('click', () => {
                        document.getElementById('loanFilter').value = '';
                        document.getElementById('typeFilter').value = '';
                        document.getElementById('yearFilter').value = '';
                        document.getElementById('sourceFilter').value = '';
                        displayFilteredPayments(window.allUserPayments);
                    });
                    resetBtn.hasListener = true;
                }

            } else {
                container.innerHTML =
                    '<tr><td colspan="9" class="no-payments">No payments recorded yet.</td></tr>';
                updatePaymentTotals([]);
            }
        })
        .catch(err => {
            container.innerHTML =
                '<tr><td colspan="9" class="no-payments error">Failed to load payments.</td></tr>';
            updatePaymentTotals([]);
            //console.error(err);
        });
}

// ==========================================
// UPDATE TOTALS FUNCTION
// ==========================================
function updatePaymentTotals(payments) {
    if (!payments || payments.length === 0) {
        document.getElementById('totalPayments').textContent = 'KES 0';
        document.getElementById('totalPrincipal').textContent = 'KES 0';
        document.getElementById('totalInterest').textContent = 'KES 0';
        return;
    }

    const totalPaid = payments.reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
    const totalPrincipal = payments.reduce((sum, p) => sum + parseFloat(p.principal_amount || 0), 0);
    const totalInterest = payments.reduce((sum, p) => sum + parseFloat(p.interest_amount || 0), 0);

    // Format with commas and KES
    const fmt = (num) => 'KES ' + Math.round(num).toLocaleString();

    document.getElementById('totalPayments').textContent = fmt(totalPaid);
    document.getElementById('totalPrincipal').textContent = fmt(totalPrincipal);
    document.getElementById('totalInterest').textContent = fmt(totalInterest);
}

// Global variable to store all schedule data
window.allScheduleData = [];

async function loadPaymentSchedule(applicationId) {
    const container = document.getElementById('scheduleTableContainer');
    if (!container) return;

    container.innerHTML = '<div class="loading">Loading payment schedule...</div>';

    try {
        // Get all user loans first to populate filters
        const loans = await getLoanApplications();

        // Filter loans for current user
        const userLoans = loans.filter(loan => {
            const loanUserId = parseInt(loan.user_id) || 0;
            const currentUserId = parseInt(currentUser.id) || 0;
            const loanPf = String(loan.pf_number || '').trim();
            const currentPf = String(currentUser.pf_number || '').trim();
            const loanIdNum = String(loan.id_number || '').trim();
            const currentIdNum = String(currentUser.id_number || '').trim();

            if (loanUserId > 0 && currentUserId > 0 && loanUserId === currentUserId)
                return true;
            if (loanPf && currentPf && loanPf === currentPf) return true;
            if (loanIdNum && currentIdNum && loanIdNum === currentIdNum) return true;
            return false;
        });

        // Populate filter dropdowns
        populateScheduleFilters(userLoans);

        // Get schedule data for all loans
        const allScheduleData = [];

        for (const loan of userLoans) {
            try {
                const loanId = loan.application_id || loan.id;
                const result = await apiRequest(`payment_schedule.php?application_id=${loanId}`);

                if (result.success && result.data && result.data.length > 0) {
                    // Add loan information to each schedule item
                    const scheduleWithLoanInfo = result.data.map(item => ({
                        ...item,
                        loan_id: loanId,
                        loan_type: loan.loan_type,
                        loan_amount: loan.loan_amount
                    }));

                    allScheduleData.push(...scheduleWithLoanInfo);
                }
            } catch (error) {
                //console.error(`Error fetching schedule for loan ${loan.application_id}:`, error);
            }
        }

        // Store all data globally for filtering
        window.allScheduleData = allScheduleData;

        // If no schedule data found
        if (allScheduleData.length === 0) {
            container.innerHTML = '<p class="no-data">No payment schedules available.</p>';
            return;
        }

        // Display all schedules initially
        displayFilteredSchedule(allScheduleData);

        // Add event listeners for filter buttons
        const applyFilterBtn = document.getElementById('applyScheduleFilterBtn');
        const resetFilterBtn = document.getElementById('resetScheduleFilterBtn');

        if (applyFilterBtn && !applyFilterBtn.hasListener) {
            applyFilterBtn.addEventListener('click', () => {
                displayFilteredSchedule(window.allScheduleData);
            });
            applyFilterBtn.hasListener = true;
        }

        if (resetFilterBtn && !resetFilterBtn.hasListener) {
            resetFilterBtn.addEventListener('click', () => {
                document.getElementById('scheduleLoanFilter').value = '';
                document.getElementById('scheduleTypeFilter').value = '';
                document.getElementById('scheduleYearFilter').value = '';
                displayFilteredSchedule(window.allScheduleData);
            });
            resetFilterBtn.hasListener = true;
        }

    } catch (error) {
        container.innerHTML = '<p class="error">Error loading payment schedule.</p>';
        //console.error('Schedule loading error:', error);
    }
}

function populateScheduleFilters(loans) {
    // Populate loan ID filter
    const loanFilter = document.getElementById('scheduleLoanFilter');
    if (loanFilter) {
        loanFilter.innerHTML = '<option value="">All Loan IDs</option>';

        // Sort loans by application_id from latest to oldest
        loans.sort((a, b) => {
            const idA = a.application_id || '';
            const idB = b.application_id || '';
            return idB.localeCompare(idA);
        });

        loans.forEach(loan => {
            const option = document.createElement('option');
            option.value = loan.application_id;
            const loanType = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan
                .loan_type.slice(
                    1) : '';
            option.textContent = `${loan.application_id} - ${loanType}`;
            loanFilter.appendChild(option);
        });
    }

    // Populate year filter
    const yearFilter = document.getElementById('scheduleYearFilter');
    if (yearFilter) {
        yearFilter.innerHTML = '<option value="">All Years</option>';

        // Extract unique years from all payment dates
        const years = new Set();
        window.allScheduleData.forEach(item => {
            if (item.due_date) {
                const year = new Date(item.due_date).getFullYear();
                if (year) years.add(year);
            }
        });

        // Sort years descending
        const sortedYears = Array.from(years).sort((a, b) => b - a);

        sortedYears.forEach(year => {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            yearFilter.appendChild(option);
        });
    }
}

function displayFilteredSchedule(data) {
    const container = document.getElementById('scheduleTableContainer');
    if (!container) return;

    // Get filter values
    const loanId = document.getElementById('scheduleLoanFilter')?.value.trim() || '';
    const loanType = document.getElementById('scheduleTypeFilter')?.value.toLowerCase().trim() || '';
    const year = document.getElementById('scheduleYearFilter')?.value || '';

    // Filter the data
    let filtered = data.filter(item => {
        const matchesLoanId = !loanId || String(item.loan_id) === String(loanId);
        const matchesType = !loanType || (item.loan_type || '').toLowerCase() === loanType;
        const itemYear = item.due_date ? new Date(item.due_date).getFullYear() : null;
        const matchesYear = !year || itemYear === parseInt(year);

        return matchesLoanId && matchesType && matchesYear;
    });

    // CRITICAL: Sort by loan_id first, then by payment_number (1,2,3... for each loan)
    filtered.sort((a, b) => {
        if (String(a.loan_id) !== String(b.loan_id)) {
            return String(a.loan_id).localeCompare(String(b.loan_id));
        }
        return a.payment_number - b.payment_number;
    });

    // Group by loan
    const groupedByLoan = {};
    filtered.forEach(item => {
        if (!groupedByLoan[item.loan_id]) {
            groupedByLoan[item.loan_id] = {
                loan_type: item.loan_type || 'Unknown',
                loan_amount: item.loan_amount || 0,
                items: []
            };
        }
        groupedByLoan[item.loan_id].items.push(item);
    });

    let html = '';

    if (Object.keys(groupedByLoan).length === 0) {
        html = '<p class="no-data">No payment schedules match the selected filters.</p>';
    } else {
        Object.keys(groupedByLoan).sort().forEach(loanId => {
            const loan = groupedByLoan[loanId];
            const loanTypeDisplay = loan.loan_type ?
                loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) :
                'Loan';

            html += `
        <div class="schedule-loan-section" style="margin-bottom: 40px;">
            <h3 style="color:#4a6b4a; border-bottom:2px solid #4a6b4a; padding-bottom:8px;">
                Payment Schedule — ${loanId} <small>(${loanTypeDisplay})</small>
            </h3>
            <p><strong>Loan Amount:</strong> KES ${parseFloat(loan.loan_amount).toLocaleString()}</p>
            <p><strong>Total Installments:</strong> ${loan.items.length} months</p>

            <div class="table-container">
                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Due Date</th>
                            <th>Amount (KES)</th>
                            <th>Principal (KES)</th>
                            <th>Interest (KES)</th>
                            <th>Balance (KES)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>`;

            loan.items.forEach(row => {
                const dueDate = new Date(row.due_date);
                const isOverdue = dueDate < new Date() && row.status !== 'paid';
                const statusText = row.status === 'paid' ? 'Paid' :
                    isOverdue ? 'Overdue' : 'Pending';
                const statusClass = row.status === 'paid' ? 'paid' :
                    isOverdue ? 'overdue' : 'pending';

                html += `
            <tr>
                <td><strong>${row.payment_number}</strong></td>
                <td>${dueDate.toLocaleDateString('en-GB')}</td>
                <td>${parseFloat(row.expected_amount).toLocaleString()}</td>
                <td>${parseFloat(row.expected_principal).toLocaleString()}</td>
                <td>${parseFloat(row.expected_interest).toLocaleString()}</td>
                <td>${parseFloat(row.expected_balance).toLocaleString()}</td>
                <td><span class="status ${statusClass}">${statusText}</span></td>
            </tr>`;
            });

            html += `
                    </tbody>
                </table>
            </div>
        </div>`;
        });
    }

    container.innerHTML = html;
}

// ==========================================
// Initialization
// ==========================================
function initializeApp() {
    //console.log('Initializing app...');
    //console.log('Current user:', currentUser);

    // Check if sidebar exists before adding event listeners
    const hamburger = document.getElementById('hamburgerMenu');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    if (hamburger && sidebar) {
        hamburger.addEventListener('click', () => {
            hamburger.classList.toggle('active');
            sidebar.classList.toggle('open');
            if (mainContent) {
                mainContent.classList.toggle('sidebar-open');
            }
            if (window.innerWidth > 760) {
                sidebar.classList.toggle('collapsed');
                if (mainContent) {
                    mainContent.classList.toggle('sidebar-collapsed');
                }
            }
        });
    }

    // Initialize other components
    if (typeof loadDashboard === 'function') {
        loadDashboard();
    }

    // Show dashboard section by default
    showSection('dashboard');
}

/**
 * Fixed User Preference Functions for Theme Management
 * Properly handles GET and POST requests to match API expectations
 */

function getUserPreference(key, callback) {
    // Construct URL for the API
    const url = `/loan-system/api/preferences.php?action=get&key=${encodeURIComponent(key)}`;

    fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include' // Include session cookie
        })
        .then(response => {
            // Check for HTTP errors (404, 500, etc)
            if (!response.ok) {
                throw new Error('API request failed');
            }
            return response.json();
        })
        .then(data => {
            // Check for application-level success from PHP
            if (data.success) {
                // Pass the value from database (or null if not found in DB)
                callback(data.value);
            } else {
                // Database logic error (e.g., invalid key format)
                //console.error('Database Error:', data.message);
                // Pass null so UI uses default
                callback(null);
            }
        })
        .catch(error => {
            // Network error or Server Error
            //console.error('Error getting preference:', error);
            // Pass null so UI uses default
            callback(null);
        });
}

function setUserPreference(key, value) {
    // Send JSON body with action 'set'
    fetch('../api/preferences.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include', // Include session cookie
            body: JSON.stringify({
                action: 'set',
                key: key,
                value: value
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                //console.log('Preference saved to database');
            } else {
                console.error('Error saving preference:', data.message);
            }
        })
        .catch(error => {
            //console.error('Network error saving preference:', error);
            
            // If it didn't save to DB, the user will just use the default.
        });
}

/**
 * Load theme from storage (API fallback)
 */
function loadThemeFromStorage() {
    getUserPreference('theme', function(theme) {
        // Default to light theme if no preference is set
        const savedTheme = theme || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);

        // Update active button
        document.querySelectorAll('.theme-option').forEach(btn => {
            if (btn.getAttribute('data-theme') === savedTheme) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    });
}

/**
 * Apply theme and save preference
 */
function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    setUserPreference('theme', theme);

    // Also save to localStorage as backup
    localStorage.setItem('theme', theme);
}

/**
 * Initialize theme selector with proper event listeners
 */
function initializeThemeSelector() {
    const themeButtons = document.querySelectorAll('.theme-option');

    // Load saved theme from storage
    loadThemeFromStorage();

    themeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const theme = this.getAttribute('data-theme');

            // Remove active class from all buttons
            themeButtons.forEach(btn => btn.classList.remove('active'));

            // Add active class to clicked button
            this.classList.add('active');

            // Apply theme
            applyTheme(theme);

            // Show notification if function exists
            if (typeof showNotification === 'function') {
                showNotification(`Theme changed to ${theme}`, 'success');
            }
        });
    });
}

// Initialize theme when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeThemeSelector();
});


// Initialize the app when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Profile dropdown functionality
    const profileButton = document.getElementById('profileButton');
    const profileDropdown = document.getElementById('profileDropdown');
    const profileMenuItem = document.getElementById('profileMenuItem');
    const securityMenuItem = document.getElementById('securityMenuItem');
    const profileLogoutBtn = document.getElementById('profileLogoutBtn');

    // Toggle dropdown
    if (profileButton) {
        profileButton.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
            profileButton.classList.toggle('active');
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function() {
        if (profileDropdown) {
            profileDropdown.classList.remove('show');
        }
        if (profileButton) {
            profileButton.classList.remove('active');
        }
    });

    // Prevent dropdown from closing when clicking inside
    if (profileDropdown) {
        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    // Profile menu item click
    if (profileMenuItem) {
        profileMenuItem.addEventListener('click', function(e) {
            e.preventDefault();
            showSection('profile');
            profileDropdown.classList.remove('show');
            profileButton.classList.remove('active');
        });
    }

    // Security menu item click
    if (securityMenuItem) {
        securityMenuItem.addEventListener('click', function(e) {
            e.preventDefault();
            showSection('profile');
            profileDropdown.classList.remove('show');
            profileButton.classList.remove('active');

            // Scroll to password section
            setTimeout(() => {
                const passwordSection = document.getElementById('passwordForm');
                if (passwordSection) {
                    passwordSection.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            }, 100);
        });
    }

    // Profile logout button click
    if (profileLogoutBtn) {
        profileLogoutBtn.addEventListener('click', async function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to logout?')) return;

            profileLogoutBtn.innerHTML =
                '<span class="dropdown-icon"><i data-lucide="log-out"></i></span>Logging out...';

            try {
                await logout();
                showNotification('Logout successful! Redirecting...', 'success');
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 1200);
            } catch (error) {
                showNotification('Logout successful! Redirecting...', 'success');
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 800);
            }

        });
    }
    //console.log('DOM loaded - initializing loanee portal');
    initializeApp();

    // Default to dashboard
    setTimeout(() => showSection('dashboard'), 100);
});
