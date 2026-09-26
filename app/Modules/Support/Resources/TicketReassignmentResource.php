<?php

namespace App\Modules\Support\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketReassignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing('fromUser', 'toUser', 'reassignedByUser');

        $userShape = fn ($user) => $user !== null ? ['id' => $user->id, 'name' => $user->name] : null;

        return [
            'id' => $this->id,
            'fromUser' => $userShape($this->fromUser),
            'toUser' => $userShape($this->toUser),
            'reassignedBy' => $userShape($this->reassignedByUser),
            'reason' => $this->reason,
            'createdAt' => $this->created_at,
        ];
    }
}
