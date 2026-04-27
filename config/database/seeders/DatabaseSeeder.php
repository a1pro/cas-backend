<?php

namespace Database\Seeders;

use App\Models\CasMessageTemplate;
use App\Models\LiveAreaPostcode;
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
            ['name' => 'TALK TO CAS Admin', 'password' => 'password', 'phone' => '+447700900000', 'email_verified_at' => now(), 'is_active' => true, 'role' => 'admin']
        );
        UserRole::updateOrCreate(['user_id' => $admin->id, 'role' => 'admin'], ['assigned_at' => now()]);

        $merchantUser = User::updateOrCreate(
            ['email' => 'merchant@talktocas.com'],
            ['name' => 'Manchester Demo Group', 'password' => 'password', 'phone' => '+447700900111', 'email_verified_at' => now(), 'phone_verified_at' => now(), 'is_active' => true, 'role' => 'merchant']
        );
        UserRole::updateOrCreate(['user_id' => $merchantUser->id, 'role' => 'merchant'], ['assigned_at' => now()]);

        $merchant = Merchant::updateOrCreate(
            ['user_id' => $merchantUser->id],
            [
                'business_name' => 'Manchester Demo Group',
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
            ['balance' => 400, 'currency' => 'GBP', 'low_balance_threshold' => 50, 'auto_top_up_enabled' => false, 'auto_top_up_amount' => 100]
        );

        $venues = [
            [
                'name' => 'Qubana Club',
                'category' => 'club',
                'city' => 'Manchester',
                'postcode' => 'M4 2BS',
                'address' => '12 Newton Street, Manchester',
                'latitude' => 53.483959,
                'longitude' => -2.236280,
                'description' => 'Indoor nightlife venue in the Northern Quarter.',
                'offer_value' => 10,
                'offer_type' => 'ride',
                'ride_trip_type' => 'to_venue',
                'minimum_order' => null,
                'fulfilment_type' => 'venue',
            ],
            [
                'name' => 'Tiger Tiger Manchester',
                'category' => 'bar',
                'city' => 'Manchester',
                'postcode' => 'M4 3AQ',
                'address' => '15 Shudehill, Manchester',
                'latitude' => 53.485250,
                'longitude' => -2.238500,
                'description' => 'Busy bar lounge with indoor seating.',
                'offer_value' => 7,
                'offer_type' => 'ride',
                'ride_trip_type' => 'to_venue',
                'minimum_order' => null,
                'fulfilment_type' => 'venue',
            ],
            [
                'name' => 'Halo Rooftop',
                'category' => 'bar',
                'city' => 'Manchester',
                'postcode' => 'M3 4EN',
                'address' => '1 Hardman Street, Manchester',
                'latitude' => 53.479900,
                'longitude' => -2.251500,
                'description' => 'Rooftop venue with indoor lounge areas.',
                'offer_value' => 5,
                'offer_type' => 'dual_choice',
                'ride_trip_type' => 'to_and_from',
                'minimum_order' => 25,
                'fulfilment_type' => 'both',
            ],
            [
                'name' => 'Pizza Express Northern Quarter',
                'category' => 'restaurant',
                'city' => 'Manchester',
                'postcode' => 'M1 1AE',
                'address' => '105 High Street, Manchester',
                'latitude' => 53.483100,
                'longitude' => -2.234200,
                'description' => 'Delivery-friendly restaurant for city-centre orders.',
                'offer_value' => 7,
                'offer_type' => 'food',
                'ride_trip_type' => null,
                'minimum_order' => 25,
                'fulfilment_type' => 'delivery',
            ],
            [
                'name' => 'Burger House Ancoats',
                'category' => 'restaurant',
                'city' => 'Manchester',
                'postcode' => 'M4 6DE',
                'address' => '18 Great Ancoats Street, Manchester',
                'latitude' => 53.485900,
                'longitude' => -2.228900,
                'description' => 'Great for shared food orders and collection.',
                'offer_value' => 6,
                'offer_type' => 'food',
                'ride_trip_type' => null,
                'minimum_order' => 20,
                'fulfilment_type' => 'both',
            ],
        ];

        foreach ($venues as $index => $data) {
            Venue::updateOrCreate(
                ['merchant_id' => $merchant->id, 'name' => $data['name']],
                array_merge($data, [
                    'is_active' => true,
                    'offer_enabled' => true,
                    'offer_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                    'offer_start_time' => '12:00:00',
                    'offer_end_time' => '23:59:00',
                    'offer_review_status' => 'live',
                    'promo_message' => 'Unlock your TALK TO CAS offer today.',
                ])
            );
        }

        $user = User::updateOrCreate(
            ['email' => 'user@talktocas.com'],
            ['name' => 'Demo User', 'password' => 'password', 'phone' => '+447700900222', 'postcode' => 'M4 2BS', 'email_verified_at' => now(), 'phone_verified_at' => now(), 'is_active' => true, 'role' => 'user']
        );
        UserRole::updateOrCreate(['user_id' => $user->id, 'role' => 'user'], ['assigned_at' => now()]);

        $firstVenue = Venue::where('merchant_id', $merchant->id)->where('name', 'Qubana Club')->first();
        $dualVenue = Venue::where('merchant_id', $merchant->id)->where('name', 'Halo Rooftop')->first();

        Voucher::updateOrCreate(
            ['code' => 'TTC-DEMO01'],
            [
                'user_id' => $user->id,
                'merchant_id' => $merchant->id,
                'venue_id' => $firstVenue?->id,
                'destination_postcode' => $firstVenue?->postcode,
                'promo_message' => 'Your ride support is ready. Enjoy Qubana tonight.',
                'voucher_value' => 5.00,
                'service_fee' => 2.50,
                'total_charge' => 7.50,
                'status' => 'issued',
                'issued_at' => now()->subHour(),
            ]
        );

        WalletTransaction::updateOrCreate(
            ['reference' => 'SEED-TOPUP-001'],
            [
                'merchant_id' => $merchant->id,
                'merchant_wallet_id' => $wallet->id,
                'voucher_id' => null,
                'type' => 'deposit',
                'amount' => 400.00,
                'balance_before' => 0.00,
                'balance_after' => 400.00,
                'notes' => 'Initial merchant starter wallet funding',
            ]
        );

        $wallet->update(['balance' => 400.00]);

        foreach ([
            ['postcode_prefix' => 'M1', 'label' => 'Manchester Central'],
            ['postcode_prefix' => 'M3', 'label' => 'Manchester Spinningfields'],
            ['postcode_prefix' => 'M4', 'label' => 'Manchester Northern Quarter'],
        ] as $index => $area) {
            LiveAreaPostcode::updateOrCreate(
                ['postcode_prefix' => $area['postcode_prefix']],
                ['label' => $area['label'], 'is_active' => true, 'sort_order' => $index + 1]
            );
        }

        $templates = [
            ['key' => 'weather', 'journey_type' => 'nightlife', 'weather_condition' => 'rain', 'emoji' => '🌧', 'body' => '🌧 Rain expected in your area in the next {lookahead} hours. Uber fares can rise 5–8% on rainy days. Lock in your voucher ride before demand spikes.', 'sort_order' => 1],
            ['key' => 'weather', 'journey_type' => 'nightlife', 'weather_condition' => 'snow_ice', 'emoji' => '❄️', 'body' => '❄️ Snow or icy conditions can drive fares sharply higher and reduce driver supply. Use your voucher early and keep the trip close.', 'sort_order' => 2],
            ['key' => 'weather', 'journey_type' => 'nightlife', 'weather_condition' => 'cold', 'emoji' => '🧥', 'body' => '🧥 Next {lookahead} hours: {description} • {temp}°C. Cold weather detected — cosy indoor venues are favoured.', 'sort_order' => 3],
            ['key' => 'weather', 'journey_type' => 'nightlife', 'weather_condition' => 'clear', 'emoji' => '✨', 'body' => '✨ Next {lookahead} hours: {description} • {temp}°C. Conditions look steady, so it’s a good time to lock in your plan.', 'sort_order' => 4],
            ['key' => 'weather', 'journey_type' => 'food', 'weather_condition' => 'rain', 'emoji' => '🥡', 'body' => '🥡 Rain expected soon. Delivery demand can jump quickly, so securing your food offer now can help you beat the rush.', 'sort_order' => 5],
            ['key' => 'weather', 'journey_type' => 'food', 'weather_condition' => 'clear', 'emoji' => '🍽️', 'body' => '🍽️ Next {lookahead} hours: {description} • {temp}°C. Conditions look calm, so you have flexibility on your order timing.', 'sort_order' => 6],
            ['key' => 'budget_tip', 'journey_type' => 'nightlife', 'weather_condition' => null, 'emoji' => '💸', 'body' => '💸 Tag-along with a friend and split the fare where it makes sense.', 'sort_order' => 10],
            ['key' => 'budget_tip', 'journey_type' => 'nightlife', 'weather_condition' => null, 'emoji' => '🚗', 'body' => '🚗 UberX Share can be a smart lower-cost option on busier nights.', 'sort_order' => 11],
            ['key' => 'budget_tip', 'journey_type' => 'nightlife', 'weather_condition' => null, 'emoji' => '📍', 'body' => '📍 Choosing a nearer venue can help keep total ride costs lower.', 'sort_order' => 12],
            ['key' => 'budget_tip', 'journey_type' => 'nightlife', 'weather_condition' => null, 'emoji' => '🎟️', 'body' => '🎟️ Lock in the voucher early before weather or demand pushes prices up.', 'sort_order' => 13],
            ['key' => 'budget_tip', 'journey_type' => 'food', 'weather_condition' => null, 'emoji' => '🥡', 'body' => '🥡 Pool a larger order with a friend to make the delivery fee work harder.', 'sort_order' => 20],
            ['key' => 'budget_tip', 'journey_type' => 'food', 'weather_condition' => null, 'emoji' => '🛍️', 'body' => '🛍️ Collection can be cheaper than delivery if you are already nearby.', 'sort_order' => 21],
        ];

        foreach ($templates as $template) {
            CasMessageTemplate::updateOrCreate(
                [
                    'key' => $template['key'],
                    'journey_type' => $template['journey_type'],
                    'weather_condition' => $template['weather_condition'],
                ],
                [
                    'channel' => 'chat_widget',
                    'emoji' => $template['emoji'],
                    'body' => $template['body'],
                    'is_active' => true,
                    'sort_order' => $template['sort_order'],
                ]
            );
        }
    }
}
