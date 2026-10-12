<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrokerVerification;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\PropertyReport;
use App\Models\PublisherApplication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => [
            'users' => User::count(),
            'listings' => Property::count(),
            'pending_listings' => Property::where('status', 'pending')->count(),
            'open_inquiries' => Inquiry::whereIn('status', ['new', 'in_progress'])->count(),
            'open_reports' => PropertyReport::where('status', 'open')->count(),
            'pending_verifications' => BrokerVerification::where('status', 'pending')->count()
                + PublisherApplication::where('status', 'pending')->count(),
        ]]);
    }

    public function listings(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => Property::with(['owner:id,name,email', 'broker:id,name,email', 'images'])
            ->latest()->paginate(50)->items()]);
    }

    public function decideListing(Request $request, Property $property): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        abort_unless($property->status === 'pending', 409, 'Only pending listings can be reviewed.');
        $data = $request->validate([
            'status' => ['required', 'in:published,rejected,draft'],
            'review_notes' => ['nullable', 'required_if:status,rejected', 'string', 'max:5000'],
        ]);
        $property->status = $data['status'];
        $property->published_at = $data['status'] === 'published' ? now() : null;
        $property->reviewed_by = $admin->id;
        $property->reviewed_at = now();
        $property->review_notes = $data['review_notes'] ?? null;
        $property->save();

        return response()->json(['data' => $property->fresh()->load(['owner:id,name,email', 'broker:id,name,email', 'images'])]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => User::query()->select('id', 'name', 'email', 'phone', 'role', 'account_status', 'created_at')
            ->latest()->paginate(50)->items()]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_if($user->role === 'admin', 403, 'Administrator accounts cannot be changed here.');
        $data = $request->validate(['account_status' => ['required', 'in:active,suspended']]);
        $user->update($data);

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'phone', 'role', 'account_status', 'created_at'])]);
    }

    public function verifications(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => BrokerVerification::with('broker:id,name,email,phone')
            ->latest()->paginate(50)->items()]);
    }

    public function decideVerification(Request $request, BrokerVerification $verification): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        $data = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
            'review_notes' => ['nullable', 'required_if:status,rejected', 'string', 'max:5000'],
        ]);
        $verification->update([
            ...$data,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['data' => $verification->fresh()->load('broker:id,name,email,phone')]);
    }

    public function publisherApplications(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'data' => PublisherApplication::with('applicant:id,name,email,phone,role')
                ->latest()->paginate(50)->items(),
        ]);
    }

    public function decidePublisherApplication(Request $request, PublisherApplication $application): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        abort_unless($application->status === 'pending', 409, 'Only pending applications can be reviewed.');

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_notes' => ['nullable', 'required_if:status,rejected', 'string', 'max:5000'],
        ]);

        $application->update([
            ...$data,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        if ($data['status'] === 'approved') {
            $application->applicant()->update(['role' => $application->requested_role]);

            if ($application->requested_role === 'broker') {
                BrokerVerification::updateOrCreate(
                    ['broker_id' => $application->user_id],
                    [
                        'license_number' => $application->license_number,
                        'agency_name' => $application->agency_name,
                        'status' => 'verified',
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => now(),
                        'review_notes' => $application->review_notes,
                    ],
                );
            }
        }

        return response()->json([
            'data' => $application->fresh()->load('applicant:id,name,email,phone,role'),
        ]);
    }

    public function inquiries(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => Inquiry::with(['property:id,title', 'buyer:id,name,email'])
            ->latest()->paginate(50)->items()]);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => PropertyReport::with(['property:id,title', 'reporter:id,name,email'])
            ->latest()->paginate(50)->items()]);
    }

    public function updateReport(Request $request, PropertyReport $report): JsonResponse
    {
        $admin = $this->authorizeAdmin($request);
        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved'],
            'resolution_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $report->update([...$data, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);

        return response()->json(['data' => $report->fresh()->load(['property:id,title', 'reporter:id,name,email'])]);
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'admin' && $user->account_status === 'active', 403);

        return $user;
    }
}
