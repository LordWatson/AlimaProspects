<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class UserController extends Controller
{
    /**
     * The authenticated token holder. Sample tenant-aware endpoint for the mobile client.
     */
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
