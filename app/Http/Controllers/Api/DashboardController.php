<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DashboardData;
use App\Services\DashboardInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardData $data, private DashboardInsights $insights) {}

    /**
     * The signed-in owner's or broker's dashboard figures.
     */
    public function publisher(Request $request): JsonResponse
    {
        $user = $this->publisherOrFail($request);

        return response()->json(['data' => $this->data->publisher($user, $this->days($request))]);
    }

    /**
     * Market-wide figures, for every workspace dashboard.
     */
    public function market(Request $request): JsonResponse
    {
        abort_unless($request->user()->account_status === 'active', 403);

        return response()->json(['data' => $this->data->market($this->days($request))]);
    }

    /**
     * AI-worded points about one of the publisher's dashboard cards.
     */
    public function publisherInsight(Request $request): JsonResponse
    {
        $user = $this->publisherOrFail($request);
        $data = $request->validate([
            'metric' => ['required', 'in:'.implode(',', DashboardInsights::PUBLISHER_METRICS)],
            'days' => ['sometimes', 'integer', 'in:'.implode(',', DashboardData::PERIODS)],
        ]);
        $days = (int) ($data['days'] ?? 30);

        return response()->json(['data' => $this->insights->for('publisher', $data['metric'], $this->data->publisher($user, $days), 'user:'.$user->id.':'.$days)]);
    }

    /**
     * Site-wide dashboard figures for administrators.
     */
    public function admin(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->data->admin($this->days($request))]);
    }

    /**
     * AI-worded points about one of the administrator's dashboard cards.
     */
    public function adminInsight(Request $request): JsonResponse
    {
        $data = $request->validate([
            'metric' => ['required', 'in:'.implode(',', DashboardInsights::ADMIN_METRICS)],
            'days' => ['sometimes', 'integer', 'in:'.implode(',', DashboardData::PERIODS)],
        ]);
        $days = (int) ($data['days'] ?? 30);

        return response()->json(['data' => $this->insights->for('admin', $data['metric'], $this->data->admin($days), 'admin:'.$days)]);
    }

    private function days(Request $request): int
    {
        $data = $request->validate(['days' => ['sometimes', 'integer', 'in:'.implode(',', DashboardData::PERIODS)]]);

        return (int) ($data['days'] ?? 30);
    }

    private function publisherOrFail(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->account_status === 'active' && $user->isPublisher(), 403, 'A publisher account is required.');

        return $user;
    }
}
