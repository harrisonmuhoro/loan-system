<?php
// js/loans.js.php
session_start();

// Check if user is authenticated
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    exit('Access denied');
}

// Set proper content type
header('Content-Type: application/javascript');

// Close PHP tag before outputting JavaScript
?>
class LoanAPI {
constructor(apiKey) {
this.apiKey = apiKey;
this.baseUrl = 'api/loans.php';
}

async fetchMyLoans() {
try {
const response = await fetch(this.baseUrl, {
method: 'GET',
headers: {
'Content-Type': 'application/json',
'X-API-Key': this.apiKey
},
credentials: 'include'
});

if (!response.ok) {
throw new Error(`HTTP ${response.status}`);
}

const data = await response.json();

if (data.success) {
console.log('My loans:', data.data);
console.log('Statistics:', data.stats); // For regular users
return data;
} else {
console.error('Error:', data.message);
return null;
}
} catch (error) {
console.error('Failed to fetch loans:', error);
return null;
}
}

async fetchSingleLoan(applicationId) {
try {
const response = await fetch(`${this.baseUrl}?id=${applicationId}`, {
method: 'GET',
headers: {
'Content-Type': 'application/json',
'X-API-Key': this.apiKey
},
credentials: 'include'
});

const data = await response.json();
return data;
} catch (error) {
console.error('Failed to fetch loan:', error);
return null;
}
}

async createLoan(loanData, csrfToken) {
try {
const response = await fetch(this.baseUrl, {
method: 'POST',
headers: {
'Content-Type': 'application/json',
'X-API-Key': this.apiKey
},
credentials: 'include',
body: JSON.stringify({
...loanData,
csrf_token: csrfToken
})
});

const data = await response.json();
return data;
} catch (error) {
console.error('Failed to create loan:', error);
return { success: false, message: error.message };
}
}

async updateLoanStatus(applicationId, status, feedback, csrfToken) {
try {
const response = await fetch(this.baseUrl, {
method: 'PUT',
headers: {
'Content-Type': 'application/json',
'X-API-Key': this.apiKey
},
credentials: 'include',
body: JSON.stringify({
application_id: applicationId,
status: status,
feedback: feedback,
csrf_token: csrfToken
})
});

const data = await response.json();
return data;
} catch (error) {
console.error('Failed to update loan:', error);
return { success: false, message: error.message };
}
}

async deleteLoan(applicationId, csrfToken) {
try {
const response = await fetch(this.baseUrl, {
method: 'DELETE',
headers: {
'Content-Type': 'application/json',
'X-API-Key': this.apiKey
},
credentials: 'include',
body: JSON.stringify({
application_id: applicationId,
csrf_token: csrfToken
})
});

const data = await response.json();
return data;
} catch (error) {
console.error('Failed to delete loan:', error);
return { success: false, message: error.message };
}
}
}

// Usage example
const loanAPI = new LoanAPI('your-api-key-here');

// Fetch current user's loans
async function displayMyLoans() {
const result = await loanAPI.fetchMyLoans();

if (result && result.success) {
const loans = result.data;
const stats = result.stats; // Available for regular users

console.log(`You have ${loans.length} loan applications`);

if (stats) {
console.log(`Pending: ${stats.pending}`);
console.log(`Approved: ${stats.approved}`);
console.log(`Total approved amount: ${stats.total_approved_amount}`);
}

// Display loans in your UI
displayLoansInTable(loans);
}
}