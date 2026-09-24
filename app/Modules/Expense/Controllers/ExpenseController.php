<?php

namespace App\Modules\Expense\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expense\Requests\StoreExpenseRequest;
use App\Modules\Expense\Resources\ExpenseResource;
use App\Modules\Expense\Services\ExpenseService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ExpenseService $expenseService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);

        $expenses = $this->expenseService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            ExpenseResource::collection($expenses->items()),
            ['count' => $expenses->total(), 'page' => $expenses->currentPage(), 'pageSize' => $expenses->perPage()]
        );
    }

    public function store(StoreExpenseRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        $expense = $this->expenseService->create($data);

        return $this->created(new ExpenseResource($expense));
    }
}
