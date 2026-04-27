<?php

namespace App\Services\Chat;

use App\Models\CasMessageTemplate;
use Illuminate\Support\Collection;

class TalkToCasConversationFlow
{
    public function welcome(): string
    {
        return "Hi, I’m CAS 👋\nI’ll help you find the best nearby deal.\n\nChoose one:\n1. Going Out Tonight\n2. Ordering Food";
    }

    public function clarifyIntent(): string
    {
        return 'No problem — are you looking to go out tonight or order food?';
    }

    public function askLocation(): string
    {
        return "Send your location or type your postcode so I can find the best offers near you.\n\nIf you prefer not to share location, just reply with your postcode.";
    }

    public function nightlifeResultsIntro(): string
    {
        return 'Here are the best nearby nightlife offers for tonight:';
    }

    public function foodResultsIntro(): string
    {
        return 'Here are the best nearby food offers:';
    }

    public function askVenueChoice(): string
    {
        return 'Reply with the number to choose an option.';
    }

    public function askEmail(): string
    {
        return 'Reply with your email address, or type SKIP.';
    }

    public function askConsent(): string
    {
        return 'Reply YES to confirm and receive your voucher.';
    }

    public function fallback(): string
    {
        return "I can help with:\n1. Going Out Tonight\n2. Ordering Food\n\nOr send your postcode to begin.";
    }

    public function noResults(?string $tagLink = null): string
    {
        $body = "I couldn’t find a live offer there right now.";

        if ($tagLink) {
            $body .= "\n\nWant to invite your favourite venue to join TALK to CAS?\nTAG NOW: {$tagLink}";
        }

        return $body;
    }

    public function comingSoonArea(?string $postcode, ?string $tagLink = null): string
    {
        $body = 'COMING SOON TO YOUR AREA ✨';
        if ($postcode) {
            $body .= "\n\nWe’re not live in {$postcode} yet, but we’re building venue clusters there.";
        }
        $body .= "\n\nTag your favourite venue to speed it up.";

        if ($tagLink) {
            $body .= "\nTAG NOW: {$tagLink}";
        }

        return $body;
    }

    public function tagPrompt(): string
    {
        return 'Type the name of the venue you want to invite.';
    }

    public function tagCreated(string $venueName, string $tagLink): string
    {
        return "Nice — I’ve got it.\n\nShare this invite link with {$venueName}:\n{$tagLink}\n\nIf they join, you’ll get your reward.";
    }

    public function bnplOffer(string $link): string
    {
        return "Want a bigger voucher and spread the cost?\n\nAvailable upgrade options:\n- £30\n- £50\n- £75\n- £100\n\nTap here to continue:\n{$link}";
    }

    public function invalidOffer(): string
    {
        return 'Sorry — that offer is not available for your account right now. I can show you another nearby option.';
    }

    public function invalidVenueChoice(): string
    {
        return 'Please reply with a valid number from the list.';
    }

    public function invalidEmail(): string
    {
        return 'Please enter a valid email address, or type SKIP.';
    }

    public function invalidConsent(): string
    {
        return 'Please reply YES when you are ready to receive the voucher.';
    }

    public function chooseAgain(): string
    {
        return 'Your selected option could not be found. Please choose again from the latest list.';
    }

    public function voucherReady(array $data): string
    {
        $link = $data['voucher_link'] ?? null;
        $offerType = $data['offer_type'] ?? null;
        $body = "Your voucher is ready 🎉\n\n";

        if ($offerType === 'dual_choice') {
            $body .= "Venue: {$data['venue_name']}\nOffer: £{$data['offer_value']} flexible voucher for Ride or Food\nUse the option that suits you best tonight.";
        } elseif (($data['journey_type'] ?? null) === 'food') {
            $body .= "Restaurant: {$data['venue_name']}\nOffer: £{$data['offer_value']} off orders over £{$data['minimum_order']}";
        } else {
            $body .= "Venue: {$data['venue_name']}\nOffer: £{$data['offer_value']} off your Uber ride\nValid tonight until 11:59pm";
        }

        if ($link) {
            $body .= "\n\nUse this link:\n{$link}";
        }

        $weatherNote = $data['weather_note'] ?? null;
        if ($weatherNote) {
            $body .= "\n\n{$weatherNote}";
        }

        $tips = collect($data['budget_tips'] ?? [])->filter()->values();
        if ($tips->isNotEmpty()) {
            $body .= "\n\nBudget-friendly tips:\n" . $tips->map(fn (string $tip) => '• ' . $tip)->implode("\n");
        }

        return $body;
    }

    public function resultsSummary(string $journeyType, array $venues, ?array $weather = null): string
    {
        $intro = $journeyType === 'food' ? $this->foodResultsIntro() : $this->nightlifeResultsIntro();

        $lines = collect($venues)->take(3)->map(function (array $venue, int $index) use ($journeyType) {
            $position = $index + 1;
            $offerType = $venue['offer_type'] ?? null;

            if ($offerType === 'dual_choice') {
                return sprintf('%d. %s — £%s flexible voucher — %s away', $position, $venue['name'], $this->fmt($venue['offer_value']), $venue['distance_label']);
            }

            if ($journeyType === 'food') {
                $minimumOrder = (float) ($venue['minimum_order'] ?? 25);
                return sprintf('%d. %s — £%s off orders over £%s', $position, $venue['name'], $this->fmt($venue['offer_value']), $this->fmt($minimumOrder));
            }

            return sprintf('%d. %s — £%s off Uber ride — %s away', $position, $venue['name'], $this->fmt($venue['offer_value']), $venue['distance_label']);
        })->implode("\n");

        $body = $intro . "\n\n" . $lines;
        $weatherNote = $this->renderWeatherNote($journeyType, $weather);
        if ($weatherNote) {
            $body .= "\n\nCAS Weather:\n{$weatherNote}";
        }

        return $body;
    }

    public function renderWeatherNote(string $journeyType, ?array $weather): ?string
    {
        if (! data_get($weather, 'applied')) {
            return null;
        }

        $conditionKey = data_get($weather, 'condition_key', 'clear');
        $template = $this->template('weather', $journeyType, $conditionKey)
            ?? $this->fallbackWeatherTemplate($journeyType, $conditionKey);

        return strtr($template, [
            '{description}' => strtolower((string) data_get($weather, 'description', 'clear sky')),
            '{temp}' => (string) round((float) data_get($weather, 'temperature_c', 0)),
            '{lookahead}' => (string) data_get($weather, 'lookahead_hours', 4),
            '{precipitation}' => (string) round((float) data_get($weather, 'precipitation_probability', 0)),
        ]);
    }

    public function budgetTips(string $journeyType, ?string $conditionKey = null): array
    {
        $templates = CasMessageTemplate::query()
            ->where('key', 'budget_tip')
            ->where('is_active', true)
            ->where(function ($query) use ($journeyType) {
                $query->whereNull('journey_type')->orWhere('journey_type', $journeyType);
            })
            ->orderBy('sort_order')
            ->get(['body'])
            ->pluck('body')
            ->filter()
            ->values();

        if ($templates->isEmpty()) {
            return $this->fallbackBudgetTips($journeyType, $conditionKey);
        }

        return $templates->take(4)->all();
    }

    public function normalizeVenueName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function template(string $key, string $journeyType, string $conditionKey): ?string
    {
        $record = CasMessageTemplate::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->where(function ($query) use ($journeyType) {
                $query->whereNull('journey_type')->orWhere('journey_type', $journeyType);
            })
            ->where(function ($query) use ($conditionKey) {
                $query->whereNull('weather_condition')->orWhere('weather_condition', $conditionKey);
            })
            ->orderByDesc('weather_condition')
            ->orderBy('sort_order')
            ->first();

        return $record?->body;
    }

    private function fallbackWeatherTemplate(string $journeyType, string $conditionKey): string
    {
        $templates = [
            'nightlife' => [
                'rain' => '🌧 Rain expected in your area in the next {lookahead} hours. Uber fares can rise 5–8% on rainy days. Lock in your voucher ride before demand spikes.',
                'snow_ice' => '❄️ Snow or icy conditions can push fares sharply higher and reduce driver supply. Use your voucher early and keep plans close to your venue.',
                'cold' => '🧥 Next {lookahead} hours: {description} • {temp}°C. Cold weather detected — cosy indoor venues are favoured.',
                'clear' => '✨ Next {lookahead} hours: {description} • {temp}°C. Conditions look steady, so it’s a good time to lock in your plan.',
            ],
            'food' => [
                'rain' => '🌧 Rain expected in your area soon. Delivery demand can jump fast, so securing your offer now can help you beat the rush.',
                'snow_ice' => '❄️ Snow or ice can slow collection and delivery times. Order early and keep a nearby back-up option ready.',
                'cold' => '🥡 Next {lookahead} hours: {description} • {temp}°C. Colder weather can lift delivery demand, so booking early helps.',
                'clear' => '🍽️ Next {lookahead} hours: {description} • {temp}°C. Conditions look calm, so you have flexibility on your order timing.',
            ],
        ];

        return $templates[$journeyType][$conditionKey] ?? $templates[$journeyType]['clear'];
    }

    private function fallbackBudgetTips(string $journeyType, ?string $conditionKey = null): array
    {
        if ($journeyType === 'food') {
            return [
                '🥡 Pool a larger order with a friend to make the delivery fee work harder.',
                '🛍️ Collection can be cheaper than delivery if you are already nearby.',
                '📍 Choosing the closest venue can help keep the total spend lower.',
                '⏰ Order a little earlier when weather demand is rising to avoid delays.',
            ];
        }

        $tips = [
            '💸 Tag-along with a friend and split the fare where it makes sense.',
            '🚗 UberX Share can be a smart lower-cost option on busier nights.',
            '📍 A nearer venue can help keep ride costs down.',
            '🎟️ Lock in the voucher early before demand or weather pushes prices up.',
        ];

        if ($conditionKey === 'rain') {
            $tips[] = '☔ Rain can lift demand quickly, so booking sooner usually gives you more control.';
        }

        return array_slice($tips, 0, 4);
    }

    private function fmt(float|int|string|null $value): string
    {
        return number_format((float) $value, 0, '.', '');
    }
}
