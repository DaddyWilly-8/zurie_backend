<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Requests\UpdateBrandSettingsRequest;
use App\Modules\Settings\Requests\UpdateContactSettingsRequest;
use App\Modules\Settings\Requests\UpdateHomepageSettingsRequest;
use App\Modules\Settings\Requests\UpdatePolicySettingsRequest;
use App\Modules\Settings\Requests\UpdateTaxSettingsRequest;
use App\Modules\Settings\Requests\UploadBrandLogoRequest;
use App\Modules\Settings\Requests\UploadHomepageHeroImageRequest;
use App\Modules\Settings\Services\SettingsService;
use App\Support\Http\ApiResponse;

class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsService $settingsService) {}

    /**
     * GET /settings — public, unauthenticated. The one call the storefront
     * homepage needs; every admin endpoint below is for editing one
     * category at a time instead.
     */
    public function overview()
    {
        return $this->ok($this->settingsService->overview());
    }

    public function showBrand()
    {
        return $this->ok($this->settingsService->getBrand());
    }

    public function updateBrand(UpdateBrandSettingsRequest $request)
    {
        return $this->ok($this->settingsService->updateBrand($request->validated()));
    }

    public function uploadBrandLogo(UploadBrandLogoRequest $request)
    {
        return $this->ok($this->settingsService->uploadBrandLogo($request->file('image'), $request->user()?->id));
    }

    public function showContact()
    {
        return $this->ok($this->settingsService->getContact());
    }

    public function updateContact(UpdateContactSettingsRequest $request)
    {
        return $this->ok($this->settingsService->updateContact($request->validated()));
    }

    public function showHomepage()
    {
        return $this->ok($this->settingsService->getHomepage());
    }

    public function updateHomepage(UpdateHomepageSettingsRequest $request)
    {
        return $this->ok($this->settingsService->updateHomepage($request->validated()));
    }

    public function uploadHomepageHeroImage(UploadHomepageHeroImageRequest $request)
    {
        return $this->ok($this->settingsService->uploadHomepageHeroImage($request->file('image'), $request->user()?->id));
    }

    public function showTax()
    {
        return $this->ok($this->settingsService->getTax());
    }

    public function updateTax(UpdateTaxSettingsRequest $request)
    {
        return $this->ok($this->settingsService->updateTax($request->validated()));
    }

    public function showPolicies()
    {
        return $this->ok($this->settingsService->getPolicies());
    }

    public function updatePolicies(UpdatePolicySettingsRequest $request)
    {
        return $this->ok($this->settingsService->updatePolicies($request->validated()));
    }
}
