<?php

namespace App\Modules\Enquiry\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Enquiry\Models\Enquiry;
use App\Modules\Enquiry\Requests\StoreEnquiryRequest;
use App\Modules\Enquiry\Requests\UpdateEnquiryStatusRequest;
use App\Modules\Enquiry\Resources\EnquiryResource;
use App\Modules\Enquiry\Services\EnquiryService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class EnquiryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EnquiryService $enquiryService) {}

    /**
     * POST /contact — public, no auth.
     */
    public function store(StoreEnquiryRequest $request)
    {
        $this->enquiryService->create($request->validated());

        return $this->ok();
    }

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));
        $filters = $request->only(['status', 'search']);

        $enquiries = $this->enquiryService->paginateAdmin($filters, $page, $pageSize);

        return $this->paginated(
            EnquiryResource::collection($enquiries->items()),
            ['count' => $enquiries->total(), 'page' => $enquiries->currentPage(), 'pageSize' => $enquiries->perPage()]
        );
    }

    public function update(UpdateEnquiryStatusRequest $request, Enquiry $enquiry)
    {
        $enquiry = $this->enquiryService->updateStatus($enquiry, $request->validated()['status']);

        return $this->ok(new EnquiryResource($enquiry));
    }
}
