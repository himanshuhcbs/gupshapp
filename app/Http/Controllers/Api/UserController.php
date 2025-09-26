<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        if (auth()->user()) {
            return User::with(['payments', 'paymentMethods', 'subscriptions'])->where('id', '=', auth()->user()->id)->first();
        }
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    public function show($id)
    {
        $user = User::findOrFail($id);
        // Allow users to view their own profile or admins to view any
        if (auth()->user()->id === $user->id) {
            return $user;
        }
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        if (auth()->user()->id === $user->id) {
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
            ]);
            $user->update($request->only('name', 'email'));
            return $user;
        }
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    public function destroy($id)
    {
        if (auth()->user()) {
            $user = User::findOrFail($id);
            $user->delete();
            return response()->json(['message' => 'User deleted']);
        }
        return response()->json(['message' => 'Unauthorized'], 403);
    }
}
