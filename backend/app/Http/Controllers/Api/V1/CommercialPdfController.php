<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Contract;
use App\Models\Estimation;
use App\Models\EstimationScenario;
use App\Models\Fixture;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Services\CommercialPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercialPdfController extends Controller
{
    public function __construct(private readonly CommercialPdfService $pdfs) {}

    public function estimation(Request $request, Estimation $estimation, EstimationScenario $scenario): JsonResponse
    {
        return $this->created(new DocumentResource($this->pdfs->estimation($estimation, $scenario, $request->user())), 'Estimation PDF generated.');
    }

    public function offer(Request $request, Offer $offer, OfferRevision $revision): JsonResponse
    {
        return $this->created(new DocumentResource($this->pdfs->offer($offer, $revision, $request->user())), 'Offer PDF generated.');
    }

    public function fixture(Request $request, Fixture $fixture): JsonResponse
    {
        return $this->created(new DocumentResource($this->pdfs->fixture($fixture, $request->user())), 'Fixture recap PDF generated.');
    }

    public function contract(Request $request, Contract $contract): JsonResponse
    {
        return $this->created(new DocumentResource($this->pdfs->contract($contract, $request->user())), 'Contract PDF generated.');
    }
}
