<?php

namespace App\Http\Controllers;

use App\Models\Table;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TableController extends Controller
{
    /**
     * GET /api/tables
     */
    public function index(Request $request): JsonResponse
    {
        try {

            $query = Table::with('store');

            if ($request->has('store_id')) {
                $query->where('store_id', $request->store_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $tables = $query->get();

            return response()->json([
                'success' => true,
                'data'    => $tables,
            ]);

        } catch (\Exception $e) {

            Log::error('Table index error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get table list.',
            ], 500);
        }
    }

    /**
     * POST /api/tables
     */
    public function store(Request $request): JsonResponse
    {
        try {

            $validated = $request->validate([
                'store_id' => 'required|integer|exists:stores,id',
                'name'     => 'required|string|max:255',
                'code'     => 'required|string|max:100|unique:tables,code',
                'capacity' => 'required|integer|min:1',
                'status'   => 'sometimes|integer',
                'note'     => 'nullable|string',
                'admin_id' => 'nullable|integer|exists:admins,id',
            ]);

            $table = Table::create($validated);

            $table->load('store');

            return response()->json([
                'success' => true,
                'message' => 'Create table successfully.',
                'data'    => $table,
            ], 201);

        } catch (ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Invalid data.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            Log::error('Table store error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create table.',
            ], 500);
        }
    }

    /**
     * GET /api/tables/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {

            $table = Table::with(['store', 'payments'])->find($id);

            if (!$table) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to find table.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => $table,
            ]);

        } catch (\Exception $e) {

            Log::error('Table show error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get table details.',
            ], 500);
        }
    }

    /**
     * PUT/PATCH /api/tables/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {

            $table = Table::find($id);

            if (!$table) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to find table.',
                ], 404);
            }

            $validated = $request->validate([
                'store_id' => 'sometimes|integer|exists:stores,id',
                'name'     => 'sometimes|string|max:255',
                'code'     => 'sometimes|string|max:100|unique:tables,code,' . $id,
                'capacity' => 'sometimes|integer|min:1',
                'status'   => 'sometimes|integer',
                'note'     => 'nullable|string',
                'admin_id' => 'nullable|integer|exists:admins,id',
            ]);

            $table->update($validated);

            $table->load('store');

            return response()->json([
                'success' => true,
                'message' => 'Update table successfully.',
                'data'    => $table,
            ]);

        } catch (ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Invalid data.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            Log::error('Table update error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update table.',
            ], 500);
        }
    }

    /**
     * DELETE /api/tables/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {

            $table = Table::find($id);

            if (!$table) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to find table.',
                ], 404);
            }

            $table->delete();

            return response()->json([
                'success' => true,
                'message' => 'Delete table successfully.',
            ]);

        } catch (\Exception $e) {

            Log::error('Table delete error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete table.',
            ], 500);
        }
    }
}