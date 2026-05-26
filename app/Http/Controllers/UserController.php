<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
   public function loginWeb(Request $request)
    {
        // Validate 
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'password' => 'required',
        ],);

        // Validation failed
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        // Credentials
        $credentials = [
            'phone' => $request->phone,
            'password' => $request->password,
        ];

        try {

            // Check credentials
            if (!$token = JWTAuth::attempt($credentials)) {

                return response()->json([
                    'success' => false,
                    'message' => 'Phone or password is incorrect'
                ], 401);
            }

            // User info
            $user = JWTAuth::user();

            // Success
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'access_token' => $token,
                'user' => $user
            ], 200);

        } catch (\Exception $e) {

            Log::error($e);

            return response()->json([
                'success' => false,
                'message' => 'System error' . $e->getMessage()
            ], 500);
        }
    }
}