<?php

namespace App\Modules\Enquiry\Services;

use App\Modules\Enquiry\Models\Enquiry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EnquiryService
{
    /**
     * POST /contact — public, no auth. Always created as `status: new`
     * (the model's own column default) — there is no reply/thread
     * mechanism in this system; an admin responds outside it using the
     * captured contact info, then marks the status accordingly via
     * updateStatus() below.
     *
     * @param  array<string, mixed>  $data  name, email, message, phone?, subject?
     */
    public function create(array $data): Enquiry
    {
        return Enquiry::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'message' => $data['message'],
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateAdmin(array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        $query = Enquiry::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        return $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function updateStatus(Enquiry $enquiry, string $status): Enquiry
    {
        $enquiry->status = $status;
        $enquiry->save();

        return $enquiry;
    }
}
