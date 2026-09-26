<?php

namespace App\Modules\Support\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\User;
use App\Modules\Support\Requests\ReassignTicketRequest;
use App\Modules\Support\Requests\StoreSupportTicketRequest;
use App\Modules\Support\Resources\SupportTicketResource;
use App\Modules\Support\Resources\TicketReassignmentResource;
use App\Modules\Support\Services\SupportTicketService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SupportTicketService $tickets) {}

    /** POST /support/tickets — auth:customer. */
    public function store(StoreSupportTicketRequest $request)
    {
        $ticket = $this->tickets->create($request->user('customer'), $request->validated());

        return $this->created(new SupportTicketResource($ticket));
    }

    /** GET /support/tickets — auth:customer, own tickets only. */
    public function indexForCustomer(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $tickets = $this->tickets->paginateForCustomer(
            $request->user('customer'),
            $request->query('status'),
            $page,
            $this->pageSize($request),
        );

        return $this->paginated(SupportTicketResource::collection($tickets->items()), [
            'count' => $tickets->total(), 'page' => $tickets->currentPage(), 'pageSize' => $tickets->perPage(),
        ]);
    }

    /** GET /support/tickets/{id} — auth:customer, own ticket only. */
    public function showForCustomer(Request $request, int $id)
    {
        $ticket = $this->tickets->findForCustomer($request->user('customer'), $id);

        return $this->ok(new SupportTicketResource($ticket));
    }

    /** GET /admin/support/tickets — auth:sanctum + support_ticket_view. */
    public function indexForStaff(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $mineOnlyUserId = $request->boolean('mineOnly') ? $request->user()->id : null;

        $tickets = $this->tickets->paginateForStaff(
            $request->query('status'),
            $mineOnlyUserId,
            $page,
            $this->pageSize($request),
        );

        return $this->paginated(SupportTicketResource::collection($tickets->items()), [
            'count' => $tickets->total(), 'page' => $tickets->currentPage(), 'pageSize' => $tickets->perPage(),
        ]);
    }

    /** GET /admin/support/tickets/{id} — auth:sanctum + support_ticket_view. */
    public function showForStaff(int $id)
    {
        return $this->ok(new SupportTicketResource($this->tickets->findForStaff($id)));
    }

    /** POST /admin/support/tickets/{id}/activate — auth:sanctum + support_ticket_manage. */
    public function activate(Request $request, int $id)
    {
        $ticket = $this->tickets->activate($this->tickets->findForStaff($id), $request->user());

        return $this->ok(new SupportTicketResource($ticket));
    }

    /** POST /admin/support/tickets/{id}/reassign — auth:sanctum + support_ticket_manage. */
    public function reassign(ReassignTicketRequest $request, int $id)
    {
        $toUser = User::findOrFail($request->validated('toUserId'));
        $ticket = $this->tickets->reassign(
            $this->tickets->findForStaff($id),
            $toUser,
            $request->user(),
            $request->validated('reason'),
        );

        return $this->ok(new SupportTicketResource($ticket));
    }

    /** POST /admin/support/tickets/{id}/close — auth:sanctum + support_ticket_manage. */
    public function close(Request $request, int $id)
    {
        $ticket = $this->tickets->close($this->tickets->findForStaff($id), $request->user());

        return $this->ok(new SupportTicketResource($ticket));
    }

    /** GET /admin/support/tickets/{id}/reassignments — auth:sanctum + support_ticket_view. */
    public function reassignments(Request $request, int $id)
    {
        $page = max(1, (int) $request->query('page', 1));
        $history = $this->tickets->reassignmentHistory($this->tickets->findForStaff($id), $page, $this->pageSize($request));

        return $this->paginated(TicketReassignmentResource::collection($history->items()), [
            'count' => $history->total(), 'page' => $history->currentPage(), 'pageSize' => $history->perPage(),
        ]);
    }
}
