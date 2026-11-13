<?php

namespace App\Services;

use App\Models\Property;
use App\Models\SavedSearch;
use App\Notifications\SavedSearchMatched;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SavedSearchAlerts
{
    /**
     * Email everyone whose saved search matches a newly published listing.
     * Each person gets at most one email per listing, however many of their
     * searches match. Returns the number of people notified.
     */
    public function notifyFor(Property $property): int
    {
        $notified = [];

        SavedSearch::query()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->where('account_status', 'active'))
            ->where('user_id', '!=', $property->owner_id)
            ->orderBy('id')
            ->chunk(200, function ($searches) use ($property, &$notified): void {
                foreach ($searches as $search) {
                    if (isset($notified[$search->user_id]) || ! $search->matches($property)) {
                        continue;
                    }

                    try {
                        Notification::send($search->user, new SavedSearchMatched($search, $property));
                        $search->forceFill(['last_alerted_at' => now()])->save();
                        $notified[$search->user_id] = true;
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return count($notified);
    }
}
