<?php

namespace App\Http\Controllers\Api\V1\Chartering;

use App\DTO\Chartering\OfferTermsData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chartering\SaveRevisionRequest;
use App\Http\Resources\Chartering\FixtureResource;
use App\Http\Resources\Chartering\OfferResource;
use App\Http\Resources\Chartering\OfferRevisionResource;
use App\Models\Enquiry;
use App\Models\Fixture;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Repositories\OfferRepository;
use App\Services\Chartering\FixtureService;
use App\Services\Chartering\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfferController extends Controller
{
    use ActivityResponder;

    public function __construct(private readonly OfferService $offers, private readonly OfferRepository $repo) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Offer::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['open', 'accepted', 'declined', 'withdrawn'])],
            'enquiry_id' => ['nullable', 'integer'], 'vessel_id' => ['nullable', 'integer'], 'sort' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->ok(OfferResource::collection($this->repo->paginate($f, $this->perPage($request))));
    }

    public function show(Offer $offer): JsonResponse
    {
        $this->authorize('view', $offer);

        return $this->ok(new OfferResource($this->repo->detail($offer)));
    }

    public function store(SaveRevisionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $offer = $this->offers->create(Enquiry::query()->findOrFail($data['enquiry_id']), (int) $data['vessel_id'], $data['counterparty_company_id'] ?? null,
            OfferTermsData::fromArray($data), $request->user());

        return $this->created(new OfferResource($this->repo->detail($offer)), 'Offer created with draft revision 1.');
    }

    public function storeRevision(SaveRevisionRequest $request, Offer $offer): JsonResponse
    {
        $rev = $this->offers->addRevision($offer, OfferTermsData::fromArray($request->validated()), $request->user());

        return $this->created(new OfferRevisionResource($rev->load('scenario.estimation')), "Draft revision {$rev->revision_no} created.");
    }

    public function updateRevision(SaveRevisionRequest $request, Offer $offer, OfferRevision $revision): JsonResponse
    {
        $rev = $this->offers->updateDraft($revision->setRelation('offer', $offer), OfferTermsData::fromArray($request->validated()));

        return $this->ok(new OfferRevisionResource($rev->load('scenario.estimation')), 'Draft revision updated.');
    }

    public function send(Request $request, Offer $offer, OfferRevision $revision): JsonResponse
    {
        $this->authorize('send', $offer);

        return $this->ok(new OfferRevisionResource($this->offers->send($revision->setRelation('offer', $offer), $request->user())), "Revision {$revision->revision_no} sent.");
    }

    public function receive(Request $request, Offer $offer, OfferRevision $revision): JsonResponse
    {
        $this->authorize('update', $offer);

        return $this->ok(new OfferRevisionResource($this->offers->recordReceived($revision->setRelation('offer', $offer), $request->user())), "Revision {$revision->revision_no} recorded as received.");
    }

    public function accept(Request $request, Offer $offer, OfferRevision $revision): JsonResponse
    {
        $this->authorize('accept', $offer);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->ok(new OfferRevisionResource($this->offers->accept($revision->setRelation('offer', $offer), $request->user(), $data['note'] ?? null)), "Revision {$revision->revision_no} accepted.");
    }

    public function reject(Request $request, Offer $offer, OfferRevision $revision): JsonResponse
    {
        $this->authorize('reject', $offer);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->ok(new OfferRevisionResource($this->offers->reject($revision->setRelation('offer', $offer), $request->user(), $data['reason'])), "Revision {$revision->revision_no} rejected.");
    }

    public function withdraw(Request $request, Offer $offer): JsonResponse
    {
        $this->authorize('update', $offer);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->ok(new OfferResource($this->repo->detail($this->offers->withdraw($offer, $request->user(), $data['reason']))), 'Offer withdrawn.');
    }

    public function diff(Offer $offer, OfferRevision $revision, int $other): JsonResponse
    {
        $this->authorize('view', $offer);
        $b = $offer->revisions()->findOrFail($other);

        return $this->ok(['from' => $revision->revision_no, 'to' => $b->revision_no, 'changes' => $this->offers->diff($revision, $b)]);
    }

    public function createFixture(Request $request, Offer $offer, OfferRevision $revision, FixtureService $fixtures): JsonResponse
    {
        $this->authorize('create', Fixture::class);
        [$fixture, $created] = $fixtures->fromRevision($revision, $request->user());
        $resource = new FixtureResource($fixture->load(['vessel', 'charterer', 'enquiry']));

        return $created ? $this->created($resource, "Fixture {$fixture->fixture_number} created.") : $this->ok($resource, 'A fixture already exists for this revision.');
    }

    public function history(Offer $offer): JsonResponse
    {
        $this->authorize('view', $offer);

        return $this->activity(['offers' => [$offer->id], 'offer-revisions' => $offer->revisions()->pluck('id')->all()]);
    }
}
