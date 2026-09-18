<?php

namespace App\Modules\Currency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Requests\StoreCurrencyRequest;
use App\Modules\Currency\Requests\StoreExchangeRateRequest;
use App\Modules\Currency\Requests\UpdateCurrencyRequest;
use App\Modules\Currency\Resources\CurrencyExchangeRateResource;
use App\Modules\Currency\Resources\CurrencyResource;
use App\Modules\Currency\Services\CurrencyService;
use App\Support\Http\ApiResponse;

class CurrencyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CurrencyService $currencyService) {}

    public function index()
    {
        return $this->ok(CurrencyResource::collection($this->currencyService->all()));
    }

    public function show(Currency $currency)
    {
        return $this->ok(new CurrencyResource($currency));
    }

    public function store(StoreCurrencyRequest $request)
    {
        $data = $request->validated();
        $data['createdBy'] = $request->user()?->id;

        $currency = $this->currencyService->create($data);

        return $this->created(new CurrencyResource($currency));
    }

    /**
     * PATCH /admin/currencies/{currency} — field edits and the isActive
     * toggle ("delete" in the admin UI) in one call, same shape as every
     * other reference-data module this session.
     */
    public function update(UpdateCurrencyRequest $request, Currency $currency)
    {
        $data = $request->validated();

        $currency = $this->currencyService->update($currency, $data);

        if (array_key_exists('isActive', $data)) {
            $currency = $this->currencyService->setActive($currency, $data['isActive']);
        }

        return $this->ok(new CurrencyResource($currency));
    }

    public function designateBase(Currency $currency)
    {
        $currency = $this->currencyService->designateBase($currency);

        return $this->ok(new CurrencyResource($currency));
    }

    public function exchangeRates(Currency $currency)
    {
        return $this->ok(CurrencyExchangeRateResource::collection($this->currencyService->exchangeRatesFor($currency)));
    }

    public function storeExchangeRate(StoreExchangeRateRequest $request, Currency $currency)
    {
        $data = $request->validated();

        $rate = $this->currencyService->addExchangeRate(
            $currency,
            (float) $data['rateToBaseCurrency'],
            $data['rateDatetime'] ?? null,
        );

        return $this->created(new CurrencyExchangeRateResource($rate));
    }
}
