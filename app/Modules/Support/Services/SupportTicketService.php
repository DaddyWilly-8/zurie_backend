<?php

namespace App\Modules\Support\Services;

use App\Modules\Auth\Models\CustomerAccount;
use App\Modules\Auth\Models\User;
use App\Modules\Support\Exceptions\InvalidTicketTransitionException;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\TicketReassignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupportTicketService
{
    /**
     * POST /support/tickets — always the current customer's own ticket;
     * customer_account_id is never a client-supplied field, same
     * "authoritative pricing/ownership only" rule Order applies to its
     * own customer fields.
     *
     * @param  array<string, mixed>  $data  subject, organizationName?, notes?
     */
    public function create(CustomerAccount $customer, array $data): SupportTicket
    {
        $ticket = SupportTicket::create([
            'customer_account_id' => $customer->id,
            'subject' => $data['subject'],
            'organization_name' => $data['organizationName'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'new',
        ]);

        activity('support_ticket')
            ->performedOn($ticket)
            ->event('created')
            ->log("Ticket #{$ticket->id} opened: {$ticket->subject}");

        return $ticket;
    }

    /** GET /support/tickets — a customer's own tickets only. */
    public function paginateForCustomer(CustomerAccount $customer, ?string $status, int $page, int $pageSize): LengthAwarePaginator
    {
        return SupportTicket::query()
            ->where('customer_account_id', $customer->id)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /** GET /admin/support/tickets — every ticket, staff-only. */
    public function paginateForStaff(?string $status, ?int $mineOnlyUserId, int $page, int $pageSize): LengthAwarePaginator
    {
        return SupportTicket::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($mineOnlyUserId !== null, fn ($q) => $q->where('attended_by_user_id', $mineOnlyUserId))
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * @throws AuthorizationException if the requester is neither the
     *   owning customer nor staff — any staff may view any thread (a
     *   deliberate choice for supervisor oversight; see the module's
     *   implementation notes for the alternative).
     */
    public function findForCustomer(CustomerAccount $customer, int $id): SupportTicket
    {
        $ticket = SupportTicket::findOrFail($id);

        if ($ticket->customer_account_id !== $customer->id) {
            throw new AuthorizationException('This ticket does not belong to you.');
        }

        return $ticket;
    }

    public function findForStaff(int $id): SupportTicket
    {
        return SupportTicket::findOrFail($id);
    }

    /**
     * Conditional update (`WHERE status = 'new'`), not read-then-write —
     * the concurrency guard against two staff activating the same ticket
     * at once. Whichever request's UPDATE actually matches a row wins;
     * the other's affected-row count is 0 and it throws, without either
     * needing a row lock held across a slower read-check-write sequence.
     *
     * @throws InvalidTicketTransitionException if the ticket isn't `new`
     */
    public function activate(SupportTicket $ticket, User $staff): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $staff) {
            $updated = SupportTicket::where('id', $ticket->id)
                ->where('status', 'new')
                ->update(['status' => 'active', 'attended_by_user_id' => $staff->id]);

            if ($updated === 0) {
                throw new InvalidTicketTransitionException(
                    "Ticket #{$ticket->id} has already been activated (or isn't new)."
                );
            }

            TicketReassignment::create([
                'ticket_id' => $ticket->id,
                'from_user_id' => null,
                'to_user_id' => $staff->id,
                'reassigned_by' => $staff->id,
            ]);

            $ticket->refresh();

            activity('support_ticket')
                ->performedOn($ticket)
                ->event('updated')
                ->causedBy($staff)
                ->log("Ticket #{$ticket->id} activated by {$staff->name}");

            return $ticket;
        });
    }

    /**
     * @throws InvalidTicketTransitionException if the ticket isn't `active`
     */
    public function reassign(SupportTicket $ticket, User $toUser, User $reassignedBy, ?string $reason): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $toUser, $reassignedBy, $reason) {
            $ticket = SupportTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            if ($ticket->status !== 'active') {
                throw new InvalidTicketTransitionException(
                    "Ticket #{$ticket->id} can't be reassigned — it isn't active."
                );
            }

            $fromUserId = $ticket->attended_by_user_id;
            $ticket->update(['attended_by_user_id' => $toUser->id]);

            TicketReassignment::create([
                'ticket_id' => $ticket->id,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUser->id,
                'reassigned_by' => $reassignedBy->id,
                'reason' => $reason,
            ]);

            activity('support_ticket')
                ->performedOn($ticket)
                ->event('updated')
                ->causedBy($reassignedBy)
                ->log("Ticket #{$ticket->id} reassigned to {$toUser->name} by {$reassignedBy->name}");

            return $ticket;
        });
    }

    /**
     * @throws InvalidTicketTransitionException if the ticket isn't
     *   `active`, or the closer isn't its current handler
     */
    public function close(SupportTicket $ticket, User $closedBy): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $closedBy) {
            $ticket = SupportTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            if ($ticket->status !== 'active') {
                throw new InvalidTicketTransitionException(
                    "Ticket #{$ticket->id} can't be closed — it isn't active."
                );
            }

            if ((int) $ticket->attended_by_user_id !== $closedBy->id) {
                throw new InvalidTicketTransitionException(
                    "Only the agent currently handling ticket #{$ticket->id} can close it."
                );
            }

            $ticket->update(['status' => 'closed', 'closed_at' => now()]);

            activity('support_ticket')
                ->performedOn($ticket)
                ->event('updated')
                ->causedBy($closedBy)
                ->log("Ticket #{$ticket->id} closed by {$closedBy->name}");

            return $ticket;
        });
    }

    public function reassignmentHistory(SupportTicket $ticket, int $page, int $pageSize): LengthAwarePaginator
    {
        return TicketReassignment::query()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * The recipient of a message/notification for this ticket right now —
     * the customer if the message came from staff, staff (the current
     * handler) if it came from the customer. Derived fresh every time,
     * never stored, so a reassignment mid-thread can't strand it (see
     * SupportMessage's docblock).
     *
     * @return array{type: class-string, id: int}|null null if the ticket
     *   has no handler yet (can't happen once active, but guards a `new`
     *   ticket defensively)
     */
    public function recipientFor(SupportTicket $ticket, string $senderType): ?array
    {
        if ($senderType === CustomerAccount::class) {
            return $ticket->attended_by_user_id !== null
                ? ['type' => User::class, 'id' => $ticket->attended_by_user_id]
                : null;
        }

        return ['type' => CustomerAccount::class, 'id' => $ticket->customer_account_id];
    }
}
