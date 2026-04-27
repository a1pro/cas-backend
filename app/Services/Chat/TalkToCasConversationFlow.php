<?php

namespace App\Services\Chat;

use App\Models\CasMessageTemplate;
use App\Support\OfferRules;
use Illuminate\Support\Collection;

class TalkToCasConversationFlow
{
    public function welcome(): string
    {
        return "Hi, I’m CAS 👋\nI’ll help find the best offers.\n\n1. 🚗 Going Out\n2. 🍔 Ordering Food";
    }

    public function clarifyIntent(): string
    {
        return "Choose one so I can show the right offers:\n1. 🚗 Going Out\n2. 🍔 Ordering Food";
    }

    public function askLocation(bool $isBrowsing = false): string
    {
        if ($isBrowsing) {
            return "Send your location or type your postcode so I can check what suits your area tonight.\n\nIf you prefer not to share location, just reply with your postcode.";
        }

        return "Send your location or type your postcode so I can find the best offers near you.\n\nIf you prefer not to share location, just reply with your postcode.";
    }

    public function browseWarmReply(): string
    {
        return "No pressure — I can help you browse first and then narrow it down with the weather and what is live nearby.";
    }

    public function nightlifeResultsIntro(): string
    {
        return 'Here are the best nearby Going Out offers:';
    }

    public function foodResultsIntro(): string
    {
        return 'Here are the best nearby Ordering Food offers:';
    }

    public function browseOptionsSummary(?string $weatherNote, array $previews): string
    {
        $lines = ["I’ve checked what is nearby."];

        if ($weatherNote) {
            $lines[] = "";
            $lines[] = "CAS Weather:";
            $lines[] = $weatherNote;
        }

        if (! empty($previews)) {
            $lines[] = "";
            $lines[] = "Best next step:";
            foreach ($previews as $index => $preview) {
                $position = $index + 1;
                $count = (int) ($preview['count'] ?? 0);
                $topVenue = $preview['top_venue'] ?? null;
                $line = sprintf('%d. %s', $position, $preview['title'] ?? $preview['label'] ?? 'Option');

                if ($count > 0) {
                    $line .= sprintf(' — %d live option%s', $count, $count === 1 ? '' : 's');
                } else {
                    $line .= ' — nothing live right now';
                }

                if ($topVenue) {
                    $line .= sprintf(' · top pick %s', $topVenue);
                }

                $lines[] = $line;
            }
        }

        $lines[] = "";
        $lines[] = 'Reply with 1 for Going Out or 2 for Ordering Food.';

        return implode("\n", $lines);
    }

    public function askVenueChoice(): string
    {
        return 'Tap a venue card or reply with a venue number. I’ll confirm the venue, then ask for your email before sending the voucher. Type BACK to choose another venue, CHANGE POSTCODE to update your area, or END to close the chat.';
    }

    public function locationSavedAskJourney(?string $postcode = null): string
    {
        $where = $postcode ? " for {$postcode}" : '';

        return "Thanks — I’ve saved your location{$where}.\n\nChoose one so I can show the right live offers:\n1. 🚗 Going Out\n2. 🍔 Ordering Food";
    }

    public function askDualChoiceUsage(array $venue): string
    {
        $rideLabel = OfferRules::rideTripTypeLabel($venue['ride_trip_type'] ?? null);
        $offerValue = $this->fmt($venue['offer_value'] ?? 0);
        $minimumOrder = $this->fmt($venue['minimum_order'] ?? 25);

        $body = "{$venue['name']} gives you a flexible £{$offerValue} voucher.\n\nHow do you want to use it tonight?\n1. Ride";
        if ($rideLabel) {
            $body .= " ({$rideLabel})";
        }
        $body .= "\n2. Food (£{$offerValue} off orders over £{$minimumOrder})";
        $body .= "\n\nReply with 1 / RIDE or 2 / FOOD.";

        return $body;
    }

    public function dualChoiceSelectionConfirmed(string $usage, array $venue): string
    {
        $offerValue = $this->fmt($venue['offer_value'] ?? 0);

        if ($usage === 'food') {
            $minimumOrder = $this->fmt($venue['minimum_order'] ?? 25);
            return "Nice — I’ll set {$venue['name']} to Food for tonight.\n\nYou’re getting £{$offerValue} off orders over £{$minimumOrder}.";
        }

        $rideLabel = OfferRules::rideTripTypeLabel($venue['ride_trip_type'] ?? null);
        $body = "Nice — I’ll set {$venue['name']} to Ride for tonight.\n\nYou’re getting £{$offerValue} off your Uber ride";
        if ($rideLabel) {
            $body .= " ({$rideLabel})";
        }
        $body .= '.';

        return $body;
    }

    public function askEmail(): string
    {
        return 'Reply with your email address, type SKIP to continue without email, BACK to choose another venue, or CHANGE POSTCODE to update your area.';
    }

    public function venueSelected(string $venueName): string
    {
        return "You selected {$venueName}. If this is wrong, type BACK to choose another venue or CHANGE POSTCODE to update your area.";
    }

    public function askConsent(): string
    {
        return 'Reply YES to confirm and receive your voucher, BACK to choose another venue, or CHANGE POSTCODE to update your area.';
    }

    public function fallback(): string
    {
        return "I can help with:
1. 🚗 Going Out
2. 🍔 Ordering Food

Choose 1 or 2, then send your location or postcode.";
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

   public function budgetObjection(?string $journeyType, ?string $conditionKey = null): string
    {
        $journeyType = $journeyType === 'food' ? 'food' : 'nightlife';

        if ($journeyType === 'food') {
            return "I hear you — let's keep food spend low tonight.\n\nTry this:\n• pick the closest option\n• choose collection if you are nearby\n• split a bigger order with someone\n\nIf you want, I can help you stick to the cheapest food route.";
        }

        $body = "No stress — we can keep tonight budget-friendly.\n\nTry this:\n• choose the nearest venue\n• share the ride where it makes sense\n• lock the voucher before fares move";

        if ($conditionKey === 'rain') {
            $body .= "\n• rain can push ride prices up later, so earlier is usually cheaper";
        }

        return $body;
    }


    public function objectionPrompt(?string $journeyType, string $objectionType, array $options = []): string
    {
        $journeyType = $journeyType === 'food' ? 'food' : 'nightlife';

        $headline = match ($objectionType) {
            'distance' => 'Got it — let’s keep this closer to you.',
            'weather' => 'Makes sense — let’s adapt to the weather.',
            'unsure' => 'No problem — I can narrow this down fast.',
            default => $this->budgetObjection($journeyType, null),
        };

        $lines = [$headline];

        if ($objectionType !== 'budget') {
            $lines[] = '';
            $lines[] = 'Choose what you want me to do next:';
        }

        foreach ($options as $index => $option) {
            $lines[] = sprintf('%d. %s', $index + 1, $option['label'] ?? ('Option ' . ($index + 1)));
        }

        $lines[] = '';
        $lines[] = 'Reply with 1, 2, or 3.';

        return implode("\n", $lines);
    }

    public function invalidObjectionChoice(): string
    {
        return 'Reply with 1, 2, or 3 so I can steer this the right way.';
    }

    public function objectionChoiceAcknowledgement(?string $objectionType, ?string $strategy): string
    {
        if ($strategy === 'upgrade_voucher') {
            return 'Got it — I’ll show you the bigger voucher options.';
        }

        if ($strategy === 'see_both') {
            return 'Got it — I’ll show you the strongest options across both lanes.';
        }

        if ($strategy === 'switch_food') {
            return 'Got it — I’ll switch you to the food lane.';
        }

        if ($strategy === 'switch_nightlife') {
            return 'Got it — I’ll switch you to going out.';
        }

        return match ($objectionType) {
            'distance' => 'Got it — I’m pulling the closer picks first.',
            'weather' => 'Got it — I’m adjusting this for the weather.',
            'unsure' => 'Got it — I’ll tighten this up for you.',
            default => 'Got it — I’m reworking this around better value.',
        };
    }

    public function invalidOffer(): string
    {
        return 'Sorry — that offer is not available for your account right now. I can show you another nearby option.';
    }

    public function invalidVenueChoice(): string
    {
        return 'Please reply with a valid number from the list.';
    }

    public function invalidWeatherChoice(): string
    {
        return 'Reply with 1 for Going Out or 2 for Ordering Food.';
    }

    public function escalationHeadline(?array $escalation): ?string
    {
        if (! $escalation || empty($escalation['headline'])) {
            return null;
        }

        return (string) $escalation['headline'];
    }

    public function invalidDualChoiceUsage(): string
    {
        return 'Please reply with 1 / RIDE or 2 / FOOD so I can prepare the correct flexible voucher.';
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
        return 'No problem — please choose again from the latest venue list.';
    }

    public function changeVenuePrompt(): string
    {
        return 'No problem — choose another venue from the list. Reply with the venue number, or type END to close the chat.';
    }

    public function chatEnded(): string
    {
        return 'Thanks for chatting with CAS 👋 Your chat is now ended. Type START any time to begin again.';
    }

    public function changeNumberPrompt(): string
    {
        return 'No problem — this chat is now ended. Start a new chat with the correct WhatsApp number.';
    }

    public function voucherIssueFailed(): string
    {
        return 'Sorry — I could not send the voucher yet. Please choose another venue, change postcode, or try again.';
    }

    public function noVenueToGoBackTo(): string
    {
        return 'I do not have a venue list to go back to yet. Choose 1 for Going Out or 2 for Ordering Food, then share your location or postcode.';
    }

    public function voucherReady(array $data): string
    {
        $link = $data['voucher_link'] ?? null;
        $offerType = $data['offer_type'] ?? null;
        $rideTripType = $data['ride_trip_type'] ?? null;
        $selectedUsage = $data['selected_usage'] ?? null;
        $body = "Your voucher is ready 🎉\n\n";

        if ($offerType === 'dual_choice' && $selectedUsage === 'food') {
            $body .= "Restaurant: {$data['venue_name']}\nFlexible voucher chosen: Food\nOffer: £{$data['offer_value']} off orders over £{$data['minimum_order']}";
        } elseif ($offerType === 'dual_choice' && $selectedUsage === 'ride') {
            $body .= "Venue: {$data['venue_name']}\nFlexible voucher chosen: Ride\nOffer: £{$data['offer_value']} off your Uber ride";
            if ($rideTripType) {
                $body .= "\nRide setting: " . OfferRules::rideTripTypeLabel($rideTripType);
            }
            $body .= "\nValid tonight until 11:59pm";
        } elseif ($offerType === 'dual_choice') {
            $body .= "Venue: {$data['venue_name']}\nOffer: £{$data['offer_value']} flexible voucher for Ride or Food";
            if ($rideTripType) {
                $body .= "\nRide setting: " . OfferRules::rideTripTypeLabel($rideTripType);
            }
            $body .= "\nUse the option that suits you best tonight.";
        } elseif (($data['journey_type'] ?? null) === 'food') {
            $body .= "Restaurant: {$data['venue_name']}\nOffer: £{$data['offer_value']} off orders over £{$data['minimum_order']}";
        } else {
            $body .= "Venue: {$data['venue_name']}\nOffer: £{$data['offer_value']} off your Uber ride\nRide setting: " . OfferRules::rideTripTypeLabel($rideTripType) . "\nValid tonight until 11:59pm";
        }

        if ($link) {
            $body .= "\n\nUse this exact Uber / Uber Eats link:\n{$link}";
        }

        $weatherNote = $data['weather_note'] ?? null;
        if ($weatherNote) {
            $body .= "\n\n{$weatherNote}";
        }

        $tips = collect($data['budget_tips'] ?? [])->filter()->values();
        if ($tips->isNotEmpty()) {
            $body .= "\n\nBudget-friendly tips:\n" . $tips->map(fn (string $tip) => '• ' . $tip)->implode("\n");
        }

        $body .= "\n\nThank you for using CAS. Enjoy your offer!";
        $body .= "\n\nType END to close the chat, or START to search again.";

        return $body;
    }

    public function resultsSummary(string $journeyType, array $venues, ?array $weather = null): string
    {
        $intro = $journeyType === 'food' ? $this->foodResultsIntro() : $this->nightlifeResultsIntro();

        $lines = collect($venues)->take(5)->map(function (array $venue, int $index) use ($journeyType) {
            $position = $index + 1;
            $offerType = $venue['offer_type'] ?? null;

            if ($offerType === 'dual_choice' || $offerType === 'dual') {
                return sprintf('%d. %s — £%s flexible voucher (Ride or Food) — %s', $position, $venue['name'], $this->fmt($venue['offer_value']), $venue['distance_label']);
            }

            $promoSuffix = ! empty($venue['recommended_coupon']['code'])
                ? ' · promo ' . $venue['recommended_coupon']['code']
                : '';

            $urgencySuffix = $this->urgencySuffix($venue['urgency'] ?? null);

            if ($journeyType === 'food') {
                $minimumOrder = (float) ($venue['minimum_order'] ?? 25);
                return sprintf('%d. %s — £%s off orders over £%s%s%s', $position, $venue['name'], $this->fmt($venue['offer_value']), $this->fmt($minimumOrder), $promoSuffix, $urgencySuffix);
            }

            $rideLabel = OfferRules::rideTripTypeLabel($venue['ride_trip_type'] ?? null);
            return sprintf('%d. %s — £%s off Uber ride (%s) — %s%s%s', $position, $venue['name'], $this->fmt($venue['offer_value']), $rideLabel, $venue['distance_label'], $promoSuffix, $urgencySuffix);
        })->implode("\n");

        $body = $intro . "\n\n" . $lines;
        if (collect($venues)->take(5)->contains(fn (array $venue) => in_array(($venue['offer_type'] ?? null), ['dual_choice', 'dual'], true))) {
            $body .= "\n\nFlexible venues let you choose Ride or Food after selection.";
        }

        $weatherNote = $this->renderWeatherNote($journeyType, $weather);
        if ($weatherNote) {
            $body .= "\n\nCAS Weather:\n{$weatherNote}";
        }

        return $body;
    }

    public function combinedResultsSummary(array $nightlifeVenues, array $foodVenues, ?array $weather = null): string
    {
        $combined = collect($nightlifeVenues)
            ->take(3)
            ->map(fn (array $venue) => array_merge($venue, ['_journey' => 'nightlife']))
            ->merge(
                collect($foodVenues)
                    ->take(3)
                    ->map(fn (array $venue) => array_merge($venue, ['_journey' => 'food']))
            )
            ->sortByDesc('score')
            ->take(5)
            ->values();

        $lines = $combined->map(function (array $venue, int $index) {
            $position = $index + 1;
            $journeyLabel = $venue['_journey'] === 'food' ? 'Food' : 'Going Out';
            $offerType = $venue['offer_type'] ?? null;

            if (in_array($offerType, ['dual_choice', 'dual'], true)) {
                return sprintf('%d. [%s] %s — £%s flexible voucher — %s', $position, $journeyLabel, $venue['name'], $this->fmt($venue['offer_value']), $venue['distance_label']);
            }

            if ($venue['_journey'] === 'food') {
                return sprintf('%d. [%s] %s — £%s off orders over £%s — %s', $position, $journeyLabel, $venue['name'], $this->fmt($venue['offer_value']), $this->fmt($venue['minimum_order'] ?? 25), $venue['distance_label']);
            }

            return sprintf('%d. [%s] %s — £%s off Uber ride — %s', $position, $journeyLabel, $venue['name'], $this->fmt($venue['offer_value']), $venue['distance_label']);
        })->implode("\n");

        $body = "Here are the strongest nearby options across going out and food:\n\n{$lines}";

        if ($weatherNote = $this->renderWeatherNote('nightlife', $weather)) {
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

    private function urgencySuffix(?array $urgency): string
    {
        if (! $urgency || ! ($urgency['enabled'] ?? false)) {
            return '';
        }

        if (($urgency['is_low_inventory'] ?? false) && isset($urgency['remaining_count'])) {
            return ' · only ' . (int) $urgency['remaining_count'] . ' left';
        }

        return '';
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
