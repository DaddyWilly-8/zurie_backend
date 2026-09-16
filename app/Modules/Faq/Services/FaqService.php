<?php

namespace App\Modules\Faq\Services;

use App\Modules\Faq\Models\Faq;
use Illuminate\Database\Eloquent\Collection;

class FaqService
{
    /**
     * GET /faq — public storefront FAQ page. Visible-only, ordered for
     * display. Admin sees the full set (including hidden) via
     * adminList() instead — same public/admin visibility split as
     * Category's public vs admin listing.
     */
    public function publicList(): Collection
    {
        return Faq::query()->where('visible', true)->orderBy('sort_order')->get();
    }

    public function adminList(): Collection
    {
        return Faq::query()->orderBy('sort_order')->get();
    }

    /**
     * @param  array<string, mixed>  $data  question, answer, sortOrder?, visible?
     */
    public function create(array $data): Faq
    {
        return Faq::create([
            'question' => $data['question'],
            'answer' => $data['answer'],
            'sort_order' => $data['sortOrder'] ?? 0,
            'visible' => $data['visible'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  question?, answer?, sortOrder?, visible?
     */
    public function update(Faq $faq, array $data): Faq
    {
        $faq->fill([
            ...(array_key_exists('question', $data) ? ['question' => $data['question']] : []),
            ...(array_key_exists('answer', $data) ? ['answer' => $data['answer']] : []),
            ...(array_key_exists('sortOrder', $data) ? ['sort_order' => $data['sortOrder']] : []),
            ...(array_key_exists('visible', $data) ? ['visible' => $data['visible']] : []),
        ])->save();

        return $faq;
    }

    public function delete(Faq $faq): void
    {
        $faq->delete();
    }
}
