<?php

namespace App\Modules\Faq\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Faq\Models\Faq;
use App\Modules\Faq\Requests\StoreFaqRequest;
use App\Modules\Faq\Requests\UpdateFaqRequest;
use App\Modules\Faq\Resources\FaqResource;
use App\Modules\Faq\Services\FaqService;
use App\Support\Http\ApiResponse;

class FaqController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FaqService $faqService) {}

    /**
     * GET /faq — public, no auth.
     */
    public function index()
    {
        return $this->ok(FaqResource::collection($this->faqService->publicList()));
    }

    /**
     * GET /admin/faq — sees hidden entries too, unlike the public list.
     */
    public function adminIndex()
    {
        return $this->ok(FaqResource::collection($this->faqService->adminList()));
    }

    public function store(StoreFaqRequest $request)
    {
        $faq = $this->faqService->create($request->validated());

        return $this->created(new FaqResource($faq));
    }

    public function update(UpdateFaqRequest $request, Faq $faq)
    {
        $faq = $this->faqService->update($faq, $request->validated());

        return $this->ok(new FaqResource($faq));
    }

    public function destroy(Faq $faq)
    {
        $this->faqService->delete($faq);

        return $this->ok();
    }
}
