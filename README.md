# TALK TO CAS Backend

Clean Laravel 12 backend converted from the old coach/client project into a role-based TALK TO CAS backend.

## Focus
- User dashboard
- Merchant dashboard
- Admin dashboard
- Merchant wallet charging only after verified voucher redemption

## Core tables
- users
- user_roles
- merchants
- merchant_wallets
- venues
- vouchers
- wallet_transactions

## Demo accounts
After seeding, you can log in with:
- Admin: `admin@talktocas.com` / `password`
- Merchant: `merchant@talktocas.com` / `password`
- User: `user@talktocas.com` / `password`

## Setup
1. `cp .env.example .env`
2. Set MySQL database name to `talktocas`
3. `composer install`
4. `php artisan key:generate`
5. `php artisan migrate --seed`
6. `php artisan serve`
