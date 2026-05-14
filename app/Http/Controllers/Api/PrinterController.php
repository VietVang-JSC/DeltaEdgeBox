<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrintQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PrinterController extends Controller
{
    /**
     * List all printers for the store
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $storeId = env('STORE_ID');

            if (!$storeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'STORE_ID not configured',
                ], 500);
            }

            $query = Printer::where('store_id', $storeId);

            // Filter by type
            if ($request->has('type')) {
                $query->where('printer_type', $request->input('type'));
            }

            // Filter by connection type
            if ($request->has('connection_type')) {
                $query->where('connection_type', $request->input('connection_type'));
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            // Only active printers by default
            if ($request->input('active_only', 'true') === 'true') {
                $query->where('is_active', true);
            }

            $printers = $query->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $printers,
                'count' => $printers->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to list printers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to list printers: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific printer by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $storeId = env('STORE_ID');

            $printer = Printer::where('store_id', $storeId)
                ->where('id', $id)
                ->first();

            if (!$printer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Printer not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $printer,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get printer', [
                'printer_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get printer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new printer
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $storeId = env('STORE_ID');

            if (!$storeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'STORE_ID not configured',
                ], 500);
            }

            // Validate input
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'printer_type' => 'required|in:receipt,kitchen,barcode,label',
                'connection_type' => 'required|in:network,usb,bluetooth,serial',
                'ip_address' => 'nullable|ip',
                'port' => 'nullable|integer|min:1|max:65535',
                'device_path' => 'nullable|string|max:255',
                'is_active' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // For network printers, IP is required
            if ($request->input('connection_type') === 'network' && !$request->input('ip_address')) {
                return response()->json([
                    'success' => false,
                    'message' => 'IP address is required for network printers',
                ], 422);
            }

            // For USB/Serial printers, device_path is required
            if (in_array($request->input('connection_type'), ['usb', 'serial']) && !$request->input('device_path')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device path is required for USB/Serial printers',
                ], 422);
            }

            // Create printer
            $printer = Printer::create([
                'store_id' => $storeId,
                'name' => $request->input('name'),
                'printer_type' => $request->input('printer_type'),
                'connection_type' => $request->input('connection_type'),
                'ip_address' => $request->input('ip_address'),
                'port' => $request->input('port', 9100),
                'device_path' => $request->input('device_path'),
                'is_active' => $request->input('is_active', true),
                'status' => 'online', // Default to online, will be checked later
                'last_status_check' => now(),
            ]);

            Log::info('Printer created', [
                'printer_id' => $printer->id,
                'name' => $printer->name,
                'type' => $printer->printer_type,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Printer created successfully',
                'data' => $printer,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create printer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create printer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a printer
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $storeId = env('STORE_ID');

            $printer = Printer::where('store_id', $storeId)
                ->where('id', $id)
                ->first();

            if (!$printer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Printer not found',
                ], 404);
            }

            // Validate input
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'printer_type' => 'sometimes|in:receipt,kitchen,barcode,label',
                'connection_type' => 'sometimes|in:network,usb,bluetooth,serial',
                'ip_address' => 'nullable|ip',
                'port' => 'nullable|integer|min:1|max:65535',
                'device_path' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'status' => 'sometimes|in:online,offline,error',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Update fields
            $updateData = $request->only([
                'name', 'printer_type', 'connection_type',
                'ip_address', 'port', 'device_path',
                'is_active', 'status'
            ]);

            $printer->update($updateData);

            Log::info('Printer updated', [
                'printer_id' => $printer->id,
                'changes' => $updateData,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Printer updated successfully',
                'data' => $printer,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update printer', [
                'printer_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update printer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a printer
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $storeId = env('STORE_ID');

            $printer = Printer::where('store_id', $storeId)
                ->where('id', $id)
                ->first();

            if (!$printer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Printer not found',
                ], 404);
            }

            // Check if there are pending print jobs
            $pendingJobs = PrintQueue::where('printer_id', $id)
                ->whereIn('status', ['pending', 'printing', 'retrying'])
                ->count();

            if ($pendingJobs > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete printer with {$pendingJobs} pending print job(s)",
                ], 409);
            }

            $printerName = $printer->name;
            $printer->delete();

            Log::info('Printer deleted', [
                'printer_id' => $id,
                'name' => $printerName,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Printer deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete printer', [
                'printer_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete printer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check printer status/connectivity
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkStatus($id)
    {
        try {
            $storeId = env('STORE_ID');

            $printer = Printer::where('store_id', $storeId)
                ->where('id', $id)
                ->first();

            if (!$printer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Printer not found',
                ], 404);
            }

            // Check connectivity based on connection type
            $status = $this->checkPrinterConnectivity($printer);

            // Update printer status
            $printer->update([
                'status' => $status['status'],
                'last_status_check' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'printer' => $printer,
                    'connectivity' => $status,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to check printer status', [
                'printer_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check printer status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit a print job to queue
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function print(Request $request)
    {
        try {
            $storeId = env('STORE_ID');

            // Validate input
            $validator = Validator::make($request->all(), [
                'printer_id' => 'required|integer|exists:printers,id',
                'job_type' => 'required|in:receipt,kitchen,barcode,label',
                'content' => 'required|array',
                'priority' => 'sometimes|integer|in:0,1,2',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $printer = Printer::where('store_id', $storeId)
                ->where('id', $request->input('printer_id'))
                ->first();

            if (!$printer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Printer not found',
                ], 404);
            }

            if (!$printer->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Printer is not active',
                ], 400);
            }

            // Create print job in queue
            $printJob = PrintQueue::create([
                'printer_id' => $printer->id,
                'job_type' => $request->input('job_type'),
                'content' => json_encode($request->input('content')),
                'status' => 'pending',
                'priority' => $request->input('priority', 1),
                'retry_count' => 0,
                'max_retries' => 5,
            ]);

            Log::info('Print job queued', [
                'job_id' => $printJob->id,
                'printer_id' => $printer->id,
                'job_type' => $printJob->job_type,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Print job queued successfully',
                'data' => [
                    'job_id' => $printJob->id,
                    'status' => $printJob->status,
                    'estimated_print_time' => now()->addSeconds(5)->toIso8601String(),
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to queue print job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to queue print job: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get print queue status
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function queueStatus(Request $request)
    {
        try {
            $storeId = env('STORE_ID');

            $query = PrintQueue::join('printers', 'print_queue.printer_id', '=', 'printers.id')
                ->where('printers.store_id', $storeId);

            // Filter by printer
            if ($request->has('printer_id')) {
                $query->where('print_queue.printer_id', $request->input('printer_id'));
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('print_queue.status', $request->input('status'));
            }

            $jobs = $query->select('print_queue.*')
                ->orderBy('print_queue.priority', 'desc')
                ->orderBy('print_queue.created_at', 'asc')
                ->limit(50)
                ->get();

            // Get statistics
            $stats = PrintQueue::join('printers', 'print_queue.printer_id', '=', 'printers.id')
                ->where('printers.store_id', $storeId)
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = "printing" THEN 1 ELSE 0 END) as printing,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = "retrying" THEN 1 ELSE 0 END) as retrying
                ')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'jobs' => $jobs,
                    'statistics' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get queue status', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get queue status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check printer connectivity based on connection type
     *
     * @param Printer $printer
     * @return array
     */
    protected function checkPrinterConnectivity(Printer $printer): array
    {
        $result = [
            'status' => 'offline',
            'message' => '',
            'response_time_ms' => null,
        ];

        try {
            $startTime = microtime(true);

            switch ($printer->connection_type) {
                case 'network':
                    // Try to ping the printer IP
                    $ip = $printer->ip_address;
                    $port = $printer->port ?? 9100;

                    // Simple socket check
                    $socket = @fsockopen($ip, $port, $errno, $errstr, 2);

                    if ($socket) {
                        fclose($socket);
                        $result['status'] = 'online';
                        $result['message'] = 'Printer is reachable';
                    } else {
                        $result['message'] = "Cannot connect to {$ip}:{$port}";
                    }
                    break;

                case 'usb':
                case 'serial':
                    // Check if device path exists
                    if ($printer->device_path && file_exists($printer->device_path)) {
                        $result['status'] = 'online';
                        $result['message'] = 'Device is connected';
                    } else {
                        $result['message'] = "Device not found: {$printer->device_path}";
                    }
                    break;

                case 'bluetooth':
                    // Bluetooth check would require OS-specific commands
                    $result['status'] = 'online'; // Assume online for now
                    $result['message'] = 'Bluetooth check not implemented yet';
                    break;

                default:
                    $result['message'] = 'Unknown connection type';
            }

            $endTime = microtime(true);
            $result['response_time_ms'] = round(($endTime - $startTime) * 1000, 2);

        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Print on browser - returns HTML for window.print()
     * This method returns renderable HTML that can be printed directly from browser
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function printOnBrowser(Request $request)
    {
        try {
            $storeId = env('STORE_ID');

            // Validate input
            $validator = Validator::make($request->all(), [
                'job_type' => 'required|in:receipt,kitchen',
                'content' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $jobType = $request->input('job_type');
            $content = $request->input('content');

            // Render appropriate template
            $html = match ($jobType) {
                'receipt' => view('print.receipt', [
                    'store' => $content['store'] ?? [
                        'name' => env('STORE_NAME', 'DeltaPOS Store'),
                        'address' => env('STORE_ADDRESS', ''),
                        'phone' => env('STORE_PHONE', ''),
                    ],
                    'order' => $content['order'] ?? [],
                ])->render(),
                'kitchen' => view('print.kitchen', [
                    'order' => $content['order'] ?? [],
                ])->render(),
                default => throw new \Exception("Unsupported job type: {$jobType}"),
            };

            return response()->json([
                'success' => true,
                'message' => 'Print content ready',
                'data' => [
                    'html' => $html,
                    'job_type' => $jobType,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Browser print failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to prepare print content: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Print real browser - returns PDF base64 for opening in new window
     * This method generates a PDF that can be opened and printed from browser
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function printRealBrowser(Request $request)
    {
        try {
            $storeId = env('STORE_ID');

            // Validate input
            $validator = Validator::make($request->all(), [
                'job_type' => 'required|in:receipt,kitchen,barcode',
                'content' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $jobType = $request->input('job_type');
            $content = $request->input('content');

            // Generate PDF using PrintWorkerService
            $printWorker = app(\App\Services\PrintWorkerService::class);

            $pdfBase64 = match ($jobType) {
                'receipt' => $printWorker->generateReceiptPDFPublic($content),
                'kitchen' => $printWorker->generateKitchenTicketPDFPublic($content),
                'barcode' => $printWorker->generateBarcodePDFPublic($content),
                default => throw new \Exception("Unsupported job type: {$jobType}"),
            };

            return response()->json([
                'success' => true,
                'message' => 'PDF generated successfully',
                'data' => [
                    'pdf_base64' => $pdfBase64,
                    'job_type' => $jobType,
                    'mime_type' => 'application/pdf',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Real browser print failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF: ' . $e->getMessage(),
            ], 500);
        }
    }
}
