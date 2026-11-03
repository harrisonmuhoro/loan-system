<?php
// js/loans.js.php
session_start();

// Secure session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.use_strict_mode', 1);

// Regenerate session ID to prevent session fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id();
    $_SESSION['initiated'] = true;
}

// Check if user is authenticated
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    exit('Access denied');
}

// Set security headers
header('Content-Type: application/javascript');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Cache control to prevent storing sensitive content
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 1 Jan 1970 00:00:00 GMT');

// Close PHP tag before outputting JavaScript
?>


/**
* Universal Logout Handler
* Provides consistent logout functionality across all user portals
* (Admin, Cashier, Accountant, Loanee)
*/

(function (window) {
'use strict';

/**
* Logout Handler Class
*/
class LogoutHandler {
constructor(options = {}) {
this.options = {
apiEndpoint: options.apiEndpoint || this.getApiEndpoint(),
redirectPage: options.redirectPage || '/loan-system/index.php',
useAjax: options.useAjax !== false, // Default to true
confirmMessage: options.confirmMessage || 'Are you sure you want to logout?',
showConfirm: options.showConfirm !== false, // Default to true
onBeforeLogout: options.onBeforeLogout || null,
onAfterLogout: options.onAfterLogout || null,
onError: options.onError || null,
// Add timeout for requests
timeout: options.timeout || 10000
};

this.isLoggingOut = false;
}

/**
* Get API endpoint dynamically
*/
getApiEndpoint() {
const hostname = window.location.hostname;
const pathname = window.location.pathname;
const basePath = pathname.substring(0, pathname.lastIndexOf('/'));

// Use relative paths when possible to avoid mixed content issues
if (hostname === 'localhost' || hostname === '127.0.0.1') {
return '/loan-system/api/logout.php';
} else {
return `${basePath}/api/logout.php`;
}
}

/**
* Get CSRF token from data attribute or meta tag
*/
getCSRFToken() {
// Try to get from data attribute (admin.php style)
const csrfElement = document.getElementById('csrf-token-data');
if (csrfElement && csrfElement.dataset.csrfToken) {
return csrfElement.dataset.csrfToken;
}

// Try to get from meta tag
const metaTag = document.querySelector('meta[name="csrf-token"]');
if (metaTag) {
return metaTag.getAttribute('content');
}

// Try to get from global variable
if (window.csrfToken) {
return window.csrfToken;
}

return null;
}

/**
* Show loading overlay
*/
showLoading() {
const overlay = document.getElementById('loadingOverlay');
if (overlay) {
overlay.classList.remove('hidden');
}
}

/**
* Hide loading overlay
*/
hideLoading() {
const overlay = document.getElementById('loadingOverlay');
if (overlay) {
overlay.classList.add('hidden');
}
}

/**
* Show toast notification
*/
showToast(message, type = 'info') {
// Sanitize message to prevent XSS
const sanitizedMessage = this.sanitizeHtml(message);

// Check if toast function exists
if (typeof window.showToast === 'function') {
window.showToast(sanitizedMessage, type);
return;
}

// Check for global toastContainer
const toastContainer = document.getElementById('toastContainer');
if (toastContainer) {
const toast = document.createElement('div');
toast.className = `toast ${type}`;

const icon = {
'success': '✅',
'error': '❌',
'warning': '⚠️',
'info': 'ℹ️'
}[type] || 'ℹ️';

toast.innerHTML = `
<span>${icon}</span>
<span>${sanitizedMessage}</span>
`;

toastContainer.appendChild(toast);

setTimeout(() => {
toast.style.animation = 'slideInRight 0.3s reverse';
setTimeout(() => toast.remove(), 300);
}, 3000);

return;
}

// Fallback: console log
console.log(`[${type.toUpperCase()}] ${sanitizedMessage}`);
}

/**
* Sanitize HTML to prevent XSS
*/
sanitizeHtml(str) {
const div = document.createElement('div');
div.textContent = str;
return div.innerHTML;
}

/**
* Perform AJAX logout with timeout
*/
async ajaxLogout() {
try {
const csrfToken = this.getCSRFToken();

// Create AbortController for timeout
const controller = new AbortController();
const timeoutId = setTimeout(() => controller.abort(), this.options.timeout);

const response = await fetch(this.options.apiEndpoint, {
method: 'POST',
headers: {
'Content-Type': 'application/json',
'Accept': 'application/json',
'X-Requested-With': 'XMLHttpRequest'
},
credentials: 'include',
body: JSON.stringify({
action: 'logout',
csrf_token: csrfToken
}),
signal: controller.signal
});

// Clear timeout
clearTimeout(timeoutId);

// Check if response is JSON
const contentType = response.headers.get('content-type');
if (!contentType || !contentType.includes('application/json')) {
throw new Error('Invalid response format from server');
}

const data = await response.json();

if (!response.ok) {
throw new Error(data.message || `HTTP ${response.status}: ${response.statusText}`);
}

if (!data.success) {
throw new Error(data.message || 'Logout failed');
}

return data;

} catch (error) {
console.error('AJAX logout error:', error);
throw error;
}
}

/**
* Perform direct logout (redirect to logout.php)
*/
directLogout() {
window.location.href = '/loan-system/index.php?logout=direct';
}

/**
* Main logout method
*/
async logout() {
// Prevent multiple simultaneous logout attempts
if (this.isLoggingOut) {
console.warn('Logout already in progress');
return;
}

// Show confirmation dialog
if (this.options.showConfirm) {
if (!confirm(this.options.confirmMessage)) {
return;
}
}

this.isLoggingOut = true;

try {
// Call before logout callback
if (typeof this.options.onBeforeLogout === 'function') {
this.options.onBeforeLogout();
}

// Show loading indicator
this.showLoading();

if (this.options.useAjax) {
// Try AJAX logout first
try {
const result = await this.ajaxLogout();

// Call after logout callback
if (typeof this.options.onAfterLogout === 'function') {
this.options.onAfterLogout(result);
}

// Show success message
this.showToast('Logout successful', 'success');

// Wait a moment before redirect
setTimeout(() => {
const redirectUrl = '/loan-system/index.php?logout=success';
window.location.href = redirectUrl;
}, 1000);

} catch (ajaxError) {
console.error('AJAX logout failed, falling back to direct logout:', ajaxError);
this.showToast('Logout processing...', 'info');

// Fallback to direct logout if AJAX fails
setTimeout(() => {
this.directLogout();
}, 500);
}
} else {
// Direct logout (no AJAX)
this.directLogout();
}

} catch (error) {
console.error('Logout error:', error);

// Call error callback
if (typeof this.options.onError === 'function') {
this.options.onError(error);
}

this.showToast('Logging out...', 'info');
this.hideLoading();
this.isLoggingOut = false;

// Try direct logout as last resort
setTimeout(() => {
this.directLogout();
}, 1000);
}
}

/**
* Attach to logout button
*/
attachToButton(buttonId) {
const button = document.getElementById(buttonId);
if (!button) {
console.error(`Logout button not found: ${buttonId}`);
return;
}

// Remove any existing event listeners by cloning
const newButton = button.cloneNode(true);
button.parentNode.replaceChild(newButton, button);

// Add new event listener
newButton.addEventListener('click', (e) => {
e.preventDefault();
e.stopPropagation();
this.logout();
});

//console.log(`Logout handler attached to button: ${buttonId}`);
}

/**
* Attach to multiple buttons
*/
attachToButtons(buttonSelector) {
const buttons = document.querySelectorAll(buttonSelector);
if (buttons.length === 0) {
console.warn(`No logout buttons found with selector: ${buttonSelector}`);
return;
}

buttons.forEach(button => {
// Clone to remove existing listeners
const newButton = button.cloneNode(true);
button.parentNode.replaceChild(newButton, button);

newButton.addEventListener('click', (e) => {
e.preventDefault();
e.stopPropagation();
this.logout();
});
});

console.log(`Logout handler attached to ${buttons.length} button(s)`);
}
}

// Export to window
window.LogoutHandler = LogoutHandler;

/**
* Quick initialization function
*/
window.initLogout = function (buttonId, options = {}) {
const handler = new LogoutHandler(options);

if (buttonId) {
if (typeof buttonId === 'string') {
handler.attachToButton(buttonId);
} else if (Array.isArray(buttonId)) {
buttonId.forEach(id => handler.attachToButton(id));
}
}

return handler;
};

/**
* Simple logout function (for backward compatibility)
*/
window.handleLogout = function (confirmMessage = 'Are you sure you want to logout?') {
if (confirm(confirmMessage)) {
const handler = new LogoutHandler({
showConfirm: false
});
handler.logout();
}
};

})(window);