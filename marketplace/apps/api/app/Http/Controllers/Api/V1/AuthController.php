<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterOwnerRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Public customer registration. The frontend never sends a role, and
     * even if it did, this endpoint would ignore it — role is always
     * assigned explicitly here, never mass-assigned from the request.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => strtolower(trim($request->validated('email'))),
            'password' => $request->validated('password'),
        ]);
        $user->role = UserRole::Customer;
        $user->save();

        // Makes $request->user() resolve to this user for the rest of this
        // request — otherwise UserResource's self-or-admin email visibility
        // check sees no authenticated user yet and silently omits the
        // email from the very response that's supposed to return it.
        Auth::setUser($user);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registered successfully.',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ], 201);
    }

    /**
     * Public Owner registration. Creates the account as UserRole::Owner
     * (never trusted from the request) plus a starter Business profile.
     * There is deliberately no equivalent endpoint for Admin — Admin
     * accounts are never created through public signup.
     */
    public function registerOwner(RegisterOwnerRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => strtolower(trim($request->validated('email'))),
                'password' => $request->validated('password'),
                'phone' => $request->validated('phone'),
            ]);
            $user->role = UserRole::Owner;
            $user->save();

            $location = Location::firstOrCreate([
                'country' => $request->validated('country') ?? 'IN',
                'state' => $request->validated('state'),
                'city' => $request->validated('city'),
                'area' => null,
                'locality' => $request->validated('locality'),
            ], [
                'postal_code' => $request->validated('pincode'),
            ]);

            Business::create([
                'user_id' => $user->id,
                'location_id' => $location->id,
                'slug' => Str::slug($request->validated('business_name')).'-'.Str::lower(Str::random(6)),
                'name' => $request->validated('business_name'),
                'about' => $request->validated('business_description'),
                'phone' => $request->validated('phone'),
            ]);

            return $user;
        });

        // Same fix as register() — see the comment there.
        Auth::setUser($user);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registered successfully.',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $credentials['email'] = strtolower(trim($credentials['email']));

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully.',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
            'data' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Current user retrieved successfully.',
            'data' => new UserResource($request->user()),
        ]);
    }
}
