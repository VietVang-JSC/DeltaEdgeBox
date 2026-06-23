<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    private function storeId(Request $request): ?int
    {
        return $request->input('store_id')
            ?? $request->query('store_id')
            ?? config('edge_box.store_id')
            ?? Store::first()?->id;
    }

    public function index(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $bookings = Booking::where('store_id', $storeId)
                ->orderBy('time_arrival', 'desc')
                ->get();
            return response()->json(['status' => true, 'data' => $bookings], 200);
        } catch (\Throwable $th) {
            Log::error('Edge booking list failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => __('api.ISError')], 500);
        }
    }

    public function create(Request $request)
    {
        try {
            $storeId = $this->storeId($request);
            $validator = Validator::make($request->all(), [
                'customer_id' => ['required'],
                'time_arrival' => ['required'],
            ]);
            if ($validator->fails()) {
                return response()->json(['status' => false, 'status_code' => 400, 'message' => $validator->errors()], 400);
            }

            $bookingCode = 'BK-' . $storeId . '-' . time() . '-' . random_int(100, 999);
            $timeArrival = $request->input('time_arrival');
            // Convert DD/MM/YYYY HH:mm to YYYY-MM-DD HH:mm:ss
            if (preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $timeArrival)) {
                $timeArrival = \Carbon\Carbon::createFromFormat('d/m/Y H:i', $timeArrival)->format('Y-m-d H:i:s');
            }
            $data = [
                'store_id' => $storeId,
                'booking_code' => $bookingCode,
                'user_id' => $request->input('user_id', 0),
                'admin_id' => $request->input('admin_id', 0),
                'time_arrival' => $timeArrival,
                'status' => $request->input('status', 0),
                'customer_id' => $request->input('customer_id'),
                'table_id' => is_array($request->input('table_id')) ? json_encode($request->input('table_id')) : $request->input('table_id'),
                'note' => $request->input('note', ''),
                'total_customer' => $request->input('total_customer', 0),
                'use_time' => $request->input('use_time', 0),
                'item_list' => $request->input('item_list'),
            ];

            $booking = Booking::create($data);
            $booking->load('customer');
            $bookingData = $booking->toArray();
            $bookingData['statusname'] = ['Chờ', 'Xác nhận', 'Đã đến', 'Hoàn thành', 'Hủy'][$booking->status] ?? $booking->status;
            $bookingData['time_arrival'] = date("d/m/Y H:i", strtotime($booking->time_arrival));
            return response()->json([
                'status' => true,
                'message' => __('api.booking_created'),
                'bookingData' => $bookingData,
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Edge booking create failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => __('api.ISError')], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $id = $request->input('id', $request->input('booking_id'));
            if (!$id) {
                return response()->json(['status' => false, 'status_code' => 400, 'message' => 'booking_id is required'], 400);
            }

            $storeId = $this->storeId($request);
            $booking = Booking::where('id', $id)->where('store_id', $storeId)->first();
            if (!$booking) {
                return response()->json(['status' => false, 'status_code' => 404, 'message' => 'Booking not found'], 404);
            }

            $updateData = $request->only([
                'time_arrival', 'status', 'customer_id', 'table_id',
                'note', 'total_customer', 'use_time', 'item_list',
            ]);
            if (isset($updateData['time_arrival']) && preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $updateData['time_arrival'])) {
                $updateData['time_arrival'] = \Carbon\Carbon::createFromFormat('d/m/Y H:i', $updateData['time_arrival'])->format('Y-m-d H:i:s');
            }
            if (isset($updateData['table_id']) && is_array($updateData['table_id'])) {
                $updateData['table_id'] = json_encode($updateData['table_id']);
            }
            $booking->update($updateData);

            return response()->json([
                'status' => true,
                'message' => __('api.booking_updated'),
                'booking' => $booking->fresh(),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Edge booking update failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => __('api.ISError')], 500);
        }
    }
}
