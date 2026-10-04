<?php

namespace App\Http\Controllers\Api\V1\Chartering;

use App\DTO\Chartering\EnquiryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chartering\SaveEnquiryRequest;
use App\Http\Resources\Chartering\EnquiryResource;
use App\Models\Enquiry;
use App\Repositories\EnquiryRepository;
use App\Services\Chartering\EnquiryService;
use App\Services\Chartering\EnquiryWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnquiryController extends Controller
{
    use ActivityResponder;

    public function __construct(private readonly EnquiryService $enquiries, private readonly EnquiryRepository $repo, private readonly EnquiryWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Enquiry::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(Enquiry::STATUSES)],
            'business_type' => ['nullable', Rule::in(Enquiry::BUSINESS_TYPES)], 'charterer_company_id' => ['nullable', 'integer'],
            'broker_company_id' => ['nullable', 'integer'], 'assigned_to' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->ok(EnquiryResource::collection($this->repo->paginate($f, $this->perPage($request))));
    }

    public function show(Enquiry $enquiry): JsonResponse
    {
        $this->authorize('view', $enquiry);

        return $this->ok(new EnquiryResource($this->repo->detail($enquiry)));
    }

    public function store(SaveEnquiryRequest $request): JsonResponse
    {
        $enquiry = $this->enquiries->create(EnquiryData::fromArray($request->validated()), $request->user());

        return $this->created(new EnquiryResource($this->repo->detail($enquiry)), 'Enquiry created.');
    }

    public function update(SaveEnquiryRequest $request, Enquiry $enquiry): JsonResponse
    {
        $enquiry = $this->enquiries->update($enquiry, EnquiryData::fromArray($request->validated()), $request->user());

        return $this->ok(new EnquiryResource($this->repo->detail($enquiry)), 'Enquiry updated.');
    }

    public function destroy(Enquiry $enquiry): JsonResponse
    {
        $this->authorize('delete', $enquiry);
        $this->enquiries->delete($enquiry);

        return $this->deleted('Enquiry deleted.');
    }

    public function status(Request $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorize('update', $enquiry);
        $data = $request->validate(['status' => ['required', Rule::in(Enquiry::STATUSES)], 'reason' => ['nullable', 'string', 'max:255']]);
        $this->workflow->transition($enquiry, $data['status'], $data['reason'] ?? null, $request->user());

        return $this->ok(new EnquiryResource($this->repo->detail($enquiry->refresh())), 'Enquiry status updated.');
    }

    public function shortlist(Request $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorize('update', $enquiry);
        $data = $request->validate([
            'vessel_id' => ['required', 'integer', Rule::exists('vessels', 'id')->whereNull('deleted_at')],
            'shortlist_status' => ['nullable', Rule::in(['candidate', 'selected', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->enquiries->shortlist($enquiry, (int) $data['vessel_id'], $data['shortlist_status'] ?? null, $data['notes'] ?? null);

        return $this->ok(new EnquiryResource($this->repo->detail($enquiry)), 'Shortlist updated.');
    }

    public function unshortlist(Enquiry $enquiry, int $vesselId): JsonResponse
    {
        $this->authorize('update', $enquiry);
        $this->enquiries->removeFromShortlist($enquiry, $vesselId);

        return $this->ok(new EnquiryResource($this->repo->detail($enquiry)), 'Vessel removed from shortlist.');
    }

    public function history(Enquiry $enquiry): JsonResponse
    {
        $this->authorize('view', $enquiry);

        return $this->activity([
            'enquiries' => [$enquiry->id],
            'estimations' => $enquiry->estimations()->pluck('id')->all(),
            'offers' => $enquiry->offers()->pluck('id')->all(),
        ]);
    }
}
