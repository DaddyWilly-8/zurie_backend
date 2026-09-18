<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Requests\AssignUserRoleRequest;
use App\Modules\Auth\Requests\StoreUserRequest;
use App\Modules\Auth\Requests\UpdateUserRolesRequest;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\UserService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserService $userService) {}

    public function store(StoreUserRequest $request)
    {
        $user = $this->userService->create($request->validated());

        return $this->created(new UserResource($user));
    }

    public function assignRole(AssignUserRoleRequest $request, User $user)
    {
        $user = $this->userService->assignRole(
            $user,
            (int) $request->validated()['roleId'],
            $request->user(),
        );

        return $this->ok(new UserResource($user));
    }

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $users = $this->userService->paginate($page, $pageSize);

        return $this->paginated(
            UserResource::collection($users->items()),
            [
                'count' => $users->total(),
                'page' => $users->currentPage(),
                'pageSize' => $users->perPage(),
            ]
        );
    }

    public function update(UpdateUserRolesRequest $request, User $user)
    {
        $user = $this->userService->syncRoles(
            $user,
            $request->validated()['roleIds'],
            $request->user(),
        );

        return $this->ok(new UserResource($user));
    }
}
