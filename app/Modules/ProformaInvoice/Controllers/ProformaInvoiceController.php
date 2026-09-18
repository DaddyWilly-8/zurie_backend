<?php

namespace App\Modules\ProformaInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProformaInvoice\Requests\StoreProformaInvoiceRequest;
use App\Modules\ProformaInvoice\Requests\UpdateProformaInvoiceRequest;
use App\Modules\ProformaInvoice\Resources\ProformaInvoiceResource;
use App\Modules\ProformaInvoice\Services\ProformaInvoiceService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class ProformaInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProformaInvoiceService $proformaInvoiceService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $proformas = $this->proformaInvoiceService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            ProformaInvoiceResource::collection($proformas->items()),
            ['count' => $proformas->total(), 'page' => $proformas->currentPage(), 'pageSize' => $proformas->perPage()]
        );
    }

    public function store(StoreProformaInvoiceRequest $request)
    {
        $proforma = $this->proformaInvoiceService->create($request->validated());

        return $this->created(new ProformaInvoiceResource($proforma));
    }

    public function show(int $id)
    {
        return $this->ok(new ProformaInvoiceResource($this->proformaInvoiceService->findOrFail($id)));
    }

    public function update(UpdateProformaInvoiceRequest $request, int $id)
    {
        $proforma = $this->proformaInvoiceService->update($this->proformaInvoiceService->findOrFail($id), $request->validated());

        return $this->ok(new ProformaInvoiceResource($proforma));
    }

    public function setActive(Request $request, int $id)
    {
        $request->validate(['isActive' => ['required', 'boolean']]);

        $proforma = $this->proformaInvoiceService->setActive($this->proformaInvoiceService->findOrFail($id), (bool) $request->boolean('isActive'));

        return $this->ok(new ProformaInvoiceResource($proforma));
    }
}
