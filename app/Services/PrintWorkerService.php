<?php

namespace App\Services;

use App\Models\PrintQueue;
use App\Models\Printer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PrintWorkerService - Processes print queue and sends jobs to DeltaPosWeb_Printer service
 */
class PrintWorkerService
{
    protected $printerServiceUrl;

    public function __construct()
    {
        // DeltaPosWeb_Printer service URL (default: localhost:3001)
        $this->printerServiceUrl = env('PRINTER_SERVICE_URL', 'http://localhost:3001');
    }

    /**
     * Process pending print jobs from queue
     * Called by worker every 2 seconds
     *
     * @param int $batchSize Number of jobs to process per batch
     * @return array Processing results
     */
    public function processQueue(int $batchSize = 10): array
    {
        // Get pending jobs, ordered by priority and age
        $jobs = PrintQueue::pending()
            ->where(function($query) {
                $query->whereNull('next_retry_at')
                      ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit($batchSize)
            ->get();

        if ($jobs->isEmpty()) {
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        Log::info("Processing {$jobs->count()} print jobs");

        $results = ['processed' => 0, 'success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($jobs as $job) {
            try {
                $this->processPrintJob($job);
                $results['processed']++;
                $results['success']++;
            } catch (\Exception $e) {
                $results['processed']++;
                $results['failed']++;
                $results['errors'][] = [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ];

                Log::error('Print job failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Process single print job
     *
     * @param PrintQueue $job
     * @return void
     * @throws \Exception
     */
    protected function processPrintJob(PrintQueue $job): void
    {
        $startTime = microtime(true);

        // Mark job as printing
        $job->update(['status' => 'printing']);

        try {
            // Get printer details
            $printer = $job->printer;

            if (!$printer) {
                throw new \Exception("Printer not found for job #{$job->id}");
            }

            if (!$printer->is_active) {
                throw new \Exception("Printer '{$printer->name}' is not active");
            }

            // Decode content
            $content = is_string($job->content) ? json_decode($job->content, true) : $job->content;

            // Send to DeltaPosWeb_Printer service based on job type
            $result = match ($job->job_type) {
                'receipt' => $this->sendReceiptToPrinter($printer, $content),
                'kitchen' => $this->sendKitchenTicketToPrinter($printer, $content),
                'barcode' => $this->sendBarcodeToPrinter($printer, $content),
                'label' => $this->sendLabelToPrinter($printer, $content),
                default => throw new \Exception("Unknown job type: {$job->job_type}"),
            };

            // Calculate processing time
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            // Mark job as completed
            $job->markCompleted();

            Log::info('Print job completed successfully', [
                'job_id' => $job->id,
                'printer' => $printer->name,
                'type' => $job->job_type,
                'processing_time_ms' => $processingTime,
            ]);

        } catch (\Exception $e) {
            // Handle failure with retry logic
            $this->handleJobFailure($job, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send receipt to printer via DeltaPosWeb_Printer service
     *
     * @param Printer $printer
     * @param array $content
     * @return bool
     */
    protected function sendReceiptToPrinter(Printer $printer, array $content): bool
    {
        // Generate PDF from content (you can use DomPDF, Snappy, etc.)
        $pdfBase64 = $this->generateReceiptPDF($content);

        // Send to printer service
        $response = Http::timeout(10)->post("{$this->printerServiceUrl}/api/print/receipt", [
            'printer_ip' => $printer->ip_address,
            'port' => $printer->port ?? 9100,
            'pdf_base64' => $pdfBase64,
            'cashdraw' => $content['open_cashdraw'] ?? false,
        ]);

        if (!$response->successful()) {
            throw new \Exception("Printer service error: " . $response->body());
        }

        return true;
    }

    /**
     * Send kitchen ticket to printer
     *
     * @param Printer $printer
     * @param array $content
     * @return bool
     */
    protected function sendKitchenTicketToPrinter(Printer $printer, array $content): bool
    {
        // Generate kitchen ticket PDF
        $pdfBase64 = $this->generateKitchenTicketPDF($content);

        // Send to printer service
        $response = Http::timeout(10)->post("{$this->printerServiceUrl}/api/print/kitchen", [
            'printer_ip' => $printer->ip_address,
            'port' => $printer->port ?? 9100,
            'pdf_base64' => $pdfBase64,
            'cashdraw' => false,
        ]);

        if (!$response->successful()) {
            throw new \Exception("Printer service error: " . $response->body());
        }

        return true;
    }

    /**
     * Send barcode label to printer
     *
     * @param Printer $printer
     * @param array $content
     * @return bool
     */
    protected function sendBarcodeToPrinter(Printer $printer, array $content): bool
    {
        // Generate barcode label PDF
        $pdfBase64 = $this->generateBarcodePDF($content);

        // Send to printer service
        $response = Http::timeout(10)->post("{$this->printerServiceUrl}/api/print/barcode", [
            'printer_ip' => $printer->ip_address,
            'port' => $printer->port ?? 9100,
            'pdf_base64' => $pdfBase64,
        ]);

        if (!$response->successful()) {
            throw new \Exception("Printer service error: " . $response->body());
        }

        return true;
    }

    /**
     * Send label to printer
     *
     * @param Printer $printer
     * @param array $content
     * @return bool
     */
    protected function sendLabelToPrinter(Printer $printer, array $content): bool
    {
        // For now, treat same as barcode
        return $this->sendBarcodeToPrinter($printer, $content);
    }

    /**
     * Handle job failure with retry logic
     *
     * @param PrintQueue $job
     * @param string $error
     * @return void
     */
    protected function handleJobFailure(PrintQueue $job, string $error): void
    {
        if ($job->retry_count < $job->max_retries) {
            // Retry with exponential backoff
            $job->incrementRetry($error);

            Log::warning('Print job will be retried', [
                'job_id' => $job->id,
                'retry_count' => $job->retry_count,
                'max_retries' => $job->max_retries,
                'next_retry_at' => $job->next_retry_at,
                'error' => $error,
            ]);
        } else {
            // Max retries exceeded, mark as failed
            $job->markFailed($error);

            Log::error('Print job failed after max retries', [
                'job_id' => $job->id,
                'retry_count' => $job->retry_count,
                'error' => $error,
            ]);
        }
    }

    /**
     * Generate receipt PDF from content
     *
     * @param array $content
     * @return string Base64 encoded PDF
     */
    protected function generateReceiptPDF(array $content): string
    {
        try {
            // Prepare data for receipt template
            $data = [
                'store' => $content['store'] ?? [
                    'name' => env('STORE_NAME', 'DeltaPOS Store'),
                    'address' => env('STORE_ADDRESS', ''),
                    'phone' => env('STORE_PHONE', ''),
                ],
                'order' => $content['order'] ?? [],
            ];

            // Generate PDF from Blade template
            $html = view('print.receipt', $data)->render();
            $pdf = Pdf::loadHTML($html)
                ->setPaper([0, 0, 227, 1000], 'portrait') // 80mm width
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', false);

            return base64_encode($pdf->output());
        } catch (\Exception $e) {
            Log::error('Failed to generate receipt PDF', ['error' => $e->getMessage()]);
            throw new \Exception("PDF generation failed: " . $e->getMessage());
        }
    }

    /**
     * Generate kitchen ticket PDF
     *
     * @param array $content
     * @return string Base64 encoded PDF
     */
    protected function generateKitchenTicketPDF(array $content): string
    {
        try {
            // Prepare data for kitchen ticket template
            $data = [
                'order' => $content['order'] ?? [],
            ];

            // Generate PDF from Blade template
            $html = view('print.kitchen', $data)->render();
            $pdf = Pdf::loadHTML($html)
                ->setPaper([0, 0, 227, 1000], 'portrait') // 80mm width
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', false);

            return base64_encode($pdf->output());
        } catch (\Exception $e) {
            Log::error('Failed to generate kitchen ticket PDF', ['error' => $e->getMessage()]);
            throw new \Exception("PDF generation failed: " . $e->getMessage());
        }
    }

    /**
     * Generate barcode label PDF
     *
     * @param array $content
     * @return string Base64 encoded PDF
     */
    protected function generateBarcodePDF(array $content): string
    {
        try {
            // For barcode labels, we'll create a simple HTML layout
            // In production, you might want to use a barcode library like milon/barcode

            $barcode = $content['barcode'] ?? '000000';
            $productName = $content['product_name'] ?? 'Product';
            $price = $content['price'] ?? 0;

            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; text-align: center; padding: 10px; width: 50mm; }
                    .barcode { font-family: monospace; font-size: 16px; letter-spacing: 2px; margin: 10px 0; }
                    .product-name { font-size: 12px; font-weight: bold; margin: 5px 0; }
                    .price { font-size: 14px; color: #d00; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='product-name'>{$productName}</div>
                <div class='barcode'>||| {$barcode} |||</div>
                <div class='price'>" . number_format($price, 0, ',', '.') . "đ</div>
            </body>
            </html>
            ";

            $pdf = Pdf::loadHTML($html)
                ->setPaper([0, 0, 142, 200], 'portrait') // 50mm x 70mm label
                ->setOption('isHtml5ParserEnabled', true);

            return base64_encode($pdf->output());
        } catch (\Exception $e) {
            Log::error('Failed to generate barcode PDF', ['error' => $e->getMessage()]);
            throw new \Exception("PDF generation failed: " . $e->getMessage());
        }
    }

    /**
     * Check if printer service is available
     *
     * @return bool
     */
    public function isPrinterServiceAvailable(): bool
    {
        try {
            $response = Http::timeout(3)->get("{$this->printerServiceUrl}/health");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get queue statistics
     *
     * @return array
     */
    public function getQueueStats(): array
    {
        return [
            'pending' => PrintQueue::pending()->count(),
            'printing' => PrintQueue::where('status', 'printing')->count(),
            'retrying' => PrintQueue::where('status', 'retrying')->count(),
            'failed' => PrintQueue::failed()->count(),
            'completed_today' => PrintQueue::where('status', 'completed')
                ->whereDate('completed_at', today())
                ->count(),
            'printer_service_available' => $this->isPrinterServiceAvailable(),
        ];
    }

    /**
     * Public method to generate receipt PDF (for browser printing)
     *
     * @param array $content
     * @return string Base64 encoded PDF
     */
    public function generateReceiptPDFPublic(array $content): string
    {
        return $this->generateReceiptPDF($content);
    }

    /**
     * Public method to generate kitchen ticket PDF (for browser printing)
     *
     * @param array $content
     * @return string Base64 encoded PDF
     */
    public function generateKitchenTicketPDFPublic(array $content): string
    {
        return $this->generateKitchenTicketPDF($content);
    }

    /**
     * Public method to generate barcode PDF (for browser printing)
     *
     * @param array $content
     * @return string Base64 encoded PDF
     */
    public function generateBarcodePDFPublic(array $content): string
    {
        return $this->generateBarcodePDF($content);
    }
}
