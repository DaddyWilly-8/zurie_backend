<?php

namespace App\Modules\Settings\Services;

use App\Modules\Media\Services\MediaService;
use App\Modules\Settings\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SettingsService
{
    public function __construct(private readonly MediaService $mediaService) {}

    /**
     * A category with no row yet (nothing saved there so far) resolves to
     * an empty array rather than a 404 — same "missing resolves to a sane
     * default" convention Inventory uses for products that predate a
     * provisioning listener. `GET /admin/settings/{category}` never errors
     * just because nobody has saved that category yet.
     */
    private function get(string $category): array
    {
        return Setting::query()->where('category', $category)->first()?->value ?? [];
    }

    private function put(string $category, array $value): array
    {
        return Setting::query()
            ->updateOrCreate(['category' => $category], ['value' => $value])
            ->value;
    }

    public function getBrand(): array
    {
        return $this->get('brand');
    }

    /**
     * Merges into the existing stored value rather than replacing it
     * wholesale — logoUrl lives in this same category's blob but is only
     * ever written by uploadBrandLogo() below; a full replace here would
     * silently wipe out whatever logo was already set.
     *
     * @param  array<string, mixed>  $data  validated UpdateBrandSettingsRequest payload
     */
    public function updateBrand(array $data): array
    {
        $merged = array_merge($this->get('brand'), [
            'siteName' => $data['siteName'],
            'tagline' => $data['tagline'] ?? null,
        ]);

        return $this->put('brand', $merged);
    }

    /**
     * A logo changes rarely, but unlike Product/Category's multi-image
     * child tables, brand only ever has one — so a new upload always
     * replaces whatever's there, same "delete old file, store new one"
     * pattern as CategoryService::setImage().
     */
    public function uploadBrandLogo(UploadedFile $file, ?int $uploadedBy): array
    {
        return DB::transaction(function () use ($file, $uploadedBy) {
            $current = $this->get('brand');

            if (! empty($current['logoUrl'])) {
                $this->mediaService->deleteByUrl($current['logoUrl']);
            }

            $media = $this->mediaService->store($file, 'settings', $uploadedBy);
            $current['logoUrl'] = $media->url;

            return $this->put('brand', $current);
        });
    }

    public function getContact(): array
    {
        return $this->get('contact');
    }

    /**
     * Full replace, unlike updateBrand()/updateHomepage() — contact has no
     * separate image-upload write path to protect, so the whole payload can
     * safely become the whole stored value. `phones`/`emails`/`socialLinks`
     * each default to an empty array if omitted, so clearing all entries of
     * one type without touching the others is a valid request.
     *
     * @param  array<string, mixed>  $data  validated UpdateContactSettingsRequest payload
     */
    public function updateContact(array $data): array
    {
        return $this->put('contact', [
            'phones' => $data['phones'] ?? [],
            'emails' => $data['emails'] ?? [],
            'socialLinks' => $data['socialLinks'] ?? [],
        ]);
    }

    public function getHomepage(): array
    {
        return $this->get('homepage');
    }

    /**
     * Merges, same reasoning as updateBrand() — heroImageUrl is only ever
     * written by uploadHomepageHeroImage() below.
     *
     * @param  array<string, mixed>  $data  validated UpdateHomepageSettingsRequest payload
     */
    public function updateHomepage(array $data): array
    {
        $merged = array_merge($this->get('homepage'), [
            'heroHeading' => $data['heroHeading'],
            'heroSubheading' => $data['heroSubheading'] ?? null,
            'heroCtaText' => $data['heroCtaText'] ?? null,
            'heroCtaUrl' => $data['heroCtaUrl'] ?? null,
        ]);

        return $this->put('homepage', $merged);
    }

    public function uploadHomepageHeroImage(UploadedFile $file, ?int $uploadedBy): array
    {
        return DB::transaction(function () use ($file, $uploadedBy) {
            $current = $this->get('homepage');

            if (! empty($current['heroImageUrl'])) {
                $this->mediaService->deleteByUrl($current['heroImageUrl']);
            }

            $media = $this->mediaService->store($file, 'settings', $uploadedBy);
            $current['heroImageUrl'] = $media->url;

            return $this->put('homepage', $current);
        });
    }

    public function getPolicies(): array
    {
        return $this->get('policies');
    }

    /**
     * @param  array<string, mixed>  $data  validated UpdatePolicySettingsRequest payload
     */
    public function updatePolicies(array $data): array
    {
        return $this->put('policies', [
            'deliveryPolicy' => $data['deliveryPolicy'] ?? null,
            'returnPolicy' => $data['returnPolicy'] ?? null,
        ]);
    }

    /**
     * GET /settings (public) — the one call the storefront homepage
     * actually needs. Same "one aggregated endpoint, small per-domain
     * methods" shape as DashboardService::overview(); this orchestrator has
     * no query logic of its own beyond calling the 4 get*() methods above.
     *
     * @return array<string, array<string, mixed>>
     */
    public function overview(): array
    {
        return [
            'brand' => $this->getBrand(),
            'contact' => $this->getContact(),
            'homepage' => $this->getHomepage(),
            'policies' => $this->getPolicies(),
            // Read-only, from config/zurie.php — lets the storefront cart and
            // POS show the same VAT line checkout will actually charge.
            'tax' => [
                'vatPercentage' => (float) config('zurie.default_vat_percentage'),
                'pricesIncludeVat' => (bool) config('zurie.prices_include_vat'),
            ],
        ];
    }
}
