# Loan Management API Documentation

## Base URL
`http://localhost/loan_system/api`

## Authentication
All API endpoints (except login) require a JWT token in the Authorization header:
`Authorization: Bearer <token>`

## Endpoints

### Authentication
- POST `/auth.php` - Login or Register
- GET `/auth.php` - Get current user

### Loans
- GET `/loans.php` - Get loan applications
- POST `/loans.php` - Create loan application
- PUT `/loans.php` - Update loan status

### Users
- GET `/users.php` - Get users (admin only)
- POST `/users.php` - Create user (admin only)
- PUT `/users.php` - Update user
- DELETE `/users.php` - Delete user (admin only)

### Payments
- GET `/payments.php` - Get payments
- POST `/payments.php` - Record payment

### Dashboard
- GET `/dashboard.php` - Get dashboard statistics

### Funding Accounts
- GET `/funding.php` - Get funding accounts
- POST `/funding.php` - Create funding account
- PUT `/funding.php` - Update funding account
- DELETE `/funding.php` - Delete funding account

### Settings
- GET `/settings.php` - Get system settings
- PUT `/settings.php` - Update system settings