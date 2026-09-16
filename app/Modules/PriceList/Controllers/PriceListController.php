<?php

namespace App\Modules\PriceList\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PriceList\Models\PriceList;
use App\Modules\PriceList\Requests\SetPriceListItemRequest;
use App\Modules\PriceList\Requests\StorePriceListRequest;
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

    public function store(StorePriceListRequest $request)
    {
        $priceList = $this->priceListService->create($request->validated());

        return $this->created(new PriceListResource($priceList));
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
}
