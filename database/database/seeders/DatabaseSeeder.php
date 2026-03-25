<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Venue;
use App\Models\Voucher;
use App\Models\WalletTransaction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@talktocas.com'],
            ['name' => 'TALK TO CAS Admin', 'password' => 'password', 'is_active' => true]
        );
        UserRole::updateOrCreate(
            ['user_id' => $admin->id, 'role' => 'admin'],
            ['assigned_at' => now()]
        );

        $merchantUser = User::updateOrCreate(
            ['email' => 'merchant@talktocas.com'],
            ['name' => 'Halo Nights', 'password' => 'password', 'phone' => '+447700900111', 'is_active' => true]
        );
        UserRole::updateOrCreate(
            ['user_id' => $merchantUser->id, 'role' => 'merchant'],
            ['assigned_at' => now()]
        );

        $merchant = Merchant::updateOrCreate(
            ['user_id' => $merchantUser->id],
            [
                'business_name' => 'Halo Nights',
                'business_type' => 'club',
                'contact_email' => 'merchant@talktocas.com',
                'contact_phone' => '+447700900111',
                'whatsapp_number' => '+447700900111',
                'default_service_fee' => 2.50,
                'status' => 'active',
            ]
        );

        $wallet = MerchantWallet::updateOrCreate(
            ['merchant_id' => $merchant->id],
            [
                'balance' => 200,
                'currency' => 'GBP',
                'low_balance_threshold' => 50,
                'auto_top_up_enabled' => false,
                'auto_top_up_amount' => 100,
            ]
        );

        $venueOne = Venue::updateOrCreate(
            ['merchant_id' => $merchant->id, 'name' => 'Halo Nights Soho'],
            [
                'category' => 'club',
                'city' => 'London',
                'postcode' => 'W1D 3QF',
                'address' => '12 Soho Street, London',
                'description' => 'Late-night club partner for TALK TO CAS.',
                'is_active' => true,
            ]
        );

        $venueTwo = Venue::updateOrCreate(
            ['merchant_id' => $merchant->id, 'name' => 'Halo Rooftop'],
            [
                'category' => 'bar',
                'city' => 'London',
                'postcode' => 'EC2A 3DZ',
                'address' => '81 Curtain Road, London',
                'description' => 'Rooftop bar partner for evening users.',
                'is_active' => true,
            ]
        );

        $user = User::updateOrCreate(
            ['email' => 'user@talktocas.com'],
            ['name' => 'Demo User', 'password' => 'password', 'phone' => '+447700900222', 'is_active' => true]
        );
        UserRole::updateOrCreate(
            ['user_id' => $user->id, 'role' => 'user'],
            ['assigned_at' => now()]
        );

        Voucher::updateOrCreate(
            ['code' => 'TTC-DEMO01'],
            [
                'user_id' => $user->id,
                'merchant_id' => $merchant->id,
                'venue_id' => $venueOne->id,
                'destination_postcode' => $venueOne->postcode,
                'promo_message' => 'Your ride support is ready. Enjoy Halo Nights tonight.',
                'voucher_value' => 5.00,
                'service_fee' => 2.50,
                'total_charge' => 7.50,
                'status' => 'issued',
                'issued_at' => now()->subHour(),
            ]
        );

        $redeemedVoucher = Voucher::updateOrCreate(
            ['code' => 'TTC-DEMO02'],
            [
                'user_id' => $user->id,
                'merchant_id' => $merchant->id,
                'venue_id' => $venueTwo->id,
                'destination_postcode' => $venueTwo->postcode,
                'promo_message' => 'Thanks for choosing Halo Rooftop.',
                'voucher_value' => 5.00,
                'service_fee' => 2.50,
                'total_charge' => 7.50,
                'status' => 'redeemed',
                'issued_at' => now()->subDays(1),
                'redeemed_at' => now()->subDays(1)->addMinutes(45),
                'external_reference' => 'UBER-DEMO-0002',
            ]
        );

        WalletTransaction::updateOrCreate(
            ['reference' => 'SEED-TOPUP-001'],
            [
                'merchant_id' => $merchant->id,
                'merchant_wallet_id' => $wallet->id,
                'voucher_id' => null,
                'type' => 'deposit',
                'amount' => 200.00,
                'balance_before' => 0.00,
                'balance_after' => 200.00,
                'notes' => 'Initial merchant starter wallet funding',
            ]
        );

        WalletTransaction::updateOrCreate(
            ['reference' => 'SEED-DEBIT-001'],
            [
                'merchant_id' => $merchant->id,
                'merchant_wallet_id' => $wallet->id,
                'voucher_id' => $redeemedVoucher->id,
                'type' => 'debit',
                'amount' => 7.50,
                'balance_before' => 200.00,
                'balance_after' => 192.50,
                'notes' => 'Wallet charged after verified voucher redemption',
            ]
        );

        $wallet->update(['balance' => 192.50]);
    }
}
