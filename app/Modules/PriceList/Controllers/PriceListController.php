<?php

namespace App\Modules\PriceList\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PriceList\Models\PriceList;
use App\Modules\PriceList\Requests\SetPriceListItemRequest;
use App\Modules\PriceList\Requests\StorePriceListRequest;
use App\Modules\PriceList\Requests\UpdatePriceListRequest;
use App\Modules\PriceList\Resources\PriceListResource;
use App\Modules\PriceList\Services\PriceListService;
use App\Support\Http\ApiResponse;

class PriceListController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PriceListService $priceListService) {}

    public function index()
    {
        return $this->ok(PriceListResource::collection($this->priceListService->all()));
    }

    public function show(PriceList $priceList)
    {
        return $this->ok(new PriceListResource($priceList->load('items')));
    }

    public function store(StorePriceListRequest $request)
    {
        $priceList = $this->priceListService->create($request->validated());

        return $this->created(new PriceListResource($priceList));
    }

    /**
     * PATCH /admin/price-lists/{priceList} — field edits and the isActive
     * toggle ("delete" in the admin UI) in one call, same shape as Cost
     * Centers/Outlets/Suppliers.
     */
    public function update(UpdatePriceListRequest $request, PriceList $priceList)
    {
        $priceList = $this->priceListService->update($priceList, $request->validated());

        return $this->ok(new PriceListResource($priceList->load('items')));
    }

    public function setItem(SetPriceListItemRequest $request, PriceList $priceList)
    {
        $data = $request->validated();

        $this->priceListService->setItem(
            $priceList,
            (int) $data['productId'],
            (float) $data['price'],
            isset($data['salePrice']) ? (float) $data['salePrice'] : null,
        );

        return $this->ok(new PriceListResource($priceList->load('items')));
    }

    public function removeItem(PriceList $priceList, int $productId)
    {
        $this->priceListService->removeItem($priceList, $productId);

        return $this->ok(new PriceListResource($priceList->load('items')));
    }
}
