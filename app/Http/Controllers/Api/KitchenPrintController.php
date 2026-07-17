<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\Store;
use App\Models\Printer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KitchenPrintController extends Controller
{
    public function printAll(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return $this->error('Table not found', 404);
        }

        $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
        app()->setLocale($language);
        $payload = $this->tablePrintPayload($table);

        $storeId = $table->store_id;
        $store = $storeId ? Store::find($storeId) : null;
        $timeZone = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');
        $settingPrintKitchen = $store ? $store->setting_print_kitchen : null;
        if ($settingPrintKitchen && is_string($settingPrintKitchen)) {
            $settingPrintKitchen = json_decode($settingPrintKitchen, true);
        }

        // ── 1. Group all items by printer ──────────
        $products = $payload['products'] ?? [];
        $arrPrint = [];
        foreach ($products as $item) {
            $printId = $item['print_id'] ?? 'default';
            $arrPrint[$printId][] = $item;
        }

        $printers = Printer::where('store_id', $storeId)->get();
        $printerHost = $store ? $store->printer_host : '';

        // ── 2. Build real[] PDF base64 per printer ───────────────────────────
        $real = [];
        $browserView = [];

        foreach ($arrPrint as $key => $itemPrint) {
            if ($key === 'default' || $key == 0) {
                $defaultPrinter = $printers->where('default', 1)->where('printer_type', 'kitchen')->first();
            } else {
                $defaultPrinter = $printers->where('id', $key)->where('printer_type', 'kitchen')->first();
            }

            $paperSize = $defaultPrinter ? $defaultPrinter->paper_size : 80;
            $tplName = 'kitchen.cook_template_print_all_' . $paperSize;
            if (!view()->exists($tplName)) {
                $tplName = 'kitchen.cook_template_print_all_80';
            }

            $paymentGroup = [
                'user' => [
                    'name' => ($table->payment && $table->payment->user) ? $table->payment->user->name : '',
                ],
                'tablename'    => $table->tablename ?? $table->name ?? '',
                'payment_code' => $table->payment
                    ? ($table->payment->payment_code ?: 'EDGE-' . $table->payment->id)
                    : 'EDGE-TEMP',
                'products'     => $itemPrint,
            ];

            // Generate PDF → base64
            $pdfContent = $this->generateKitchenPDF($paymentGroup, $storeId, $tplName, $paperSize);
            $base64Pdf  = base64_encode($pdfContent);

            $real[$key] = [
                'status'      => true,
                'message'     => 'print_success',
                'status_code' => 200,
                'data'        => [
                    'ip_address'   => $defaultPrinter ? $defaultPrinter->ip_address : '',
                    'printer_url'  => $printerHost,
                    'data'         => $base64Pdf,         
                    'printer_type' => $defaultPrinter ? $defaultPrinter->printer_type : 'kitchen',
                    'paper_size'   => $paperSize,
                ],
            ];

            
            $groupPayload = $this->tablePrintPayload($table, $itemPrint);
            $browserView[$key] = view($tplName, [
                'data'                  => $groupPayload,
                'payment'               => $groupPayload['payment'] ?? [],
                'setting_print_kitchen' => $settingPrintKitchen,
                'bill_setting'          => [],
                'timeZone'              => $timeZone,
            ])->render();
        }

        
        $defaultTpl = 'kitchen.cook_template_print_all_80';
        $payload['browser'] = [
            'view' => [
                'default' => view($defaultTpl, [
                    'payment'               => $payload['payment'] ?? $payload,
                    'setting_print_kitchen' => $settingPrintKitchen,
                    'data'                  => $payload,
                    'bill_setting'          => [],
                    'timeZone'              => $timeZone,
                ])->render(),
                ...$browserView,         
            ],
        ];

        
        $payload['real'] = $real;
        $payload['setting_print_kitchen'] = $settingPrintKitchen;

        // Mark all items as printed after successful generation
        // try { $this->listPrintableItems($table, true); }
        // catch (\Throwable $th) { Log::warning('printAll markPrinted failed', ['error' => $th->getMessage()]); }

        return $this->success($payload);
    }
    

    public function printNextWeb(Request $request)
    {
        $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
        app()->setLocale($language);
        $table = $this->findTable($request);
        if (!$table) {
            return $this->error('Table not found', 404);
        }

        return DB::transaction(function () use ($table) {
            $products = $this->listPrintableItems($table, true);
            if (empty($products)) {
                return $this->error('No items to print', 404);
            }

            return $this->success($this->tablePrintPayload($table, $products));
        });
    }

    public function printOnBrowser(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return response()->json(['status' => false, 'status_code' => 404, 'message' => 'Table not found'], 404);
        }

        try {
            $products = $this->listPrintableItems($table, true);
            if (empty($products)) {
                return response()->json(['status' => false, 'status_code' => 404, 'message' => 'No items to print', 'error_code' => 'no_items'], 404);
            }

            $payload = $this->tablePrintPayload($table, $products);
            $store = Store::find($table->store_id);
            $timeZone = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');
            $paperSize = $request->input('paper_size', '80');
            $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
            app()->setLocale($language);

            // Render kitchen template HTML
            $tplName = 'kitchen.cook_template_print_' . $paperSize;
            if (!view()->exists($tplName)) {
                $tplName = 'kitchen.cook_template_print_80';
            }
            $html = view($tplName, [
                'data' => $payload,
                'payment' => $payload['payment'] ?? [],
                'setting_print_kitchen' => $payload['setting_print_kitchen'] ?? null,
                'bill_setting' => [],
                'timeZone' => $timeZone,
            ])->render();

            return response()->json([
                'status' => true,
                'data' => [
                    'browser' => [
                        'view' => ['default' => $html],
                    ],
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge kitchen print on browser failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Print render failed'], 500);
        }
    }

    public function printRealBrowser(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return response()->json(['status' => false, 'status_code' => 404, 'message' => 'Table not found'], 404);
        }

        try {
            // Retrieve printable kitchen items. Printed state is updated after client-side print succeeds.
            $products = $this->listPrintableItems($table, true);
            if (empty($products)) {
                return response()->json([
                    'status' => false,
                    'status_code' => 404,
                    'message' => 'No items to print',
                    'error_code' => 'no_items'
                ], 404);
            }

            $store = Store::find($table->store_id);
            $timeZone = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');
            $storeId = $table->store_id;
            $language = $request->input('language', $request->input('isCheckLanguage', 'vi'));
            app()->setLocale($language);

            // Group items by printer
            $arrPrint = [];
            foreach ($products as $item) {
                $printId = $item['print_id'] ?? 'default';
                $arrPrint[$printId][] = $item;
            }

            $real = [];
            $browser = [];

            // Fetch printers configuration
            $printers = Printer::where('store_id', $storeId)->get();
            $printerHost = $store ? $store->printer_host : '';

            foreach ($arrPrint as $key => $itemPrint) {
                if ($key === 'default' || $key == 0) {
                    $defaultPrinter = $printers->where('default', 1)->where('printer_type', 'kitchen')->first();
                } else {
                    $defaultPrinter = $printers->where('id', $key)->where('printer_type', 'kitchen')->first();
                }

                $paperSize = $defaultPrinter ? $defaultPrinter->paper_size : 80;
                $tplName = 'kitchen.cook_template_print_' . $paperSize;
                if (!view()->exists($tplName)) {
                    $tplName = 'kitchen.cook_template_print_80';
                }

                // Compile payment data for grouping
                $paymentGroup = [
                    'user' => [
                        'name' => ($table->payment && $table->payment->user) ? $table->payment->user->name : '',
                    ],
                    'tablename' => $table->tablename ?? $table->name ?? '',
                    'payment_code' => $table->payment ? ($table->payment->payment_code ?: 'EDGE-' . $table->payment->id) : 'EDGE-TEMP',
                    'products' => $itemPrint,
                ];

                // Generate PDF locally
                $pdfContent = $this->generateKitchenPDF($paymentGroup, $storeId, $tplName, $paperSize);
                $base64Pdf = base64_encode($pdfContent);

                // Build real printing payload
                $real[$key] = [
                    'status' => true,
                    'message' => 'print_success',
                    'status_code' => 200,
                    'data' => [
                        'ip_address' => $defaultPrinter ? $defaultPrinter->ip_address : '',
                        'printer_url' => $printerHost,
                        'data' => $base64Pdf,
                        'printer_type' => $defaultPrinter ? $defaultPrinter->printer_type : 'kitchen',
                        'paper_size' => $paperSize,
                    ],
                ];

                // Build browser fallback info
                $browser[$key] = $paymentGroup;
            }

            // Compile browser view HTML templates for frontend fallback
            $view = [];
            foreach ($arrPrint as $key => $itemPrint) {
                if ($key === 'default' || $key == 0) {
                    $defaultPrinter = $printers->where('default', 1)->where('printer_type', 'kitchen')->first();
                } else {
                    $defaultPrinter = $printers->where('id', $key)->where('printer_type', 'kitchen')->first();
                }
                $paperSize = $defaultPrinter ? $defaultPrinter->paper_size : 80;
                $tplName = 'kitchen.cook_template_print_' . $paperSize;
                if (!view()->exists($tplName)) {
                    $tplName = 'kitchen.cook_template_print_80';
                }

                $payload = $this->tablePrintPayload($table, $itemPrint);
                $view[$key] = view($tplName, [
                    'data' => $payload,
                    'payment' => $payload['payment'] ?? [],
                    'setting_print_kitchen' => $payload['setting_print_kitchen'] ?? null,
                    'bill_setting' => [],
                    'timeZone' => $timeZone,
                ])->render();
            }
            $browser['view'] = $view;

            return response()->json([
                'status' => true,
                'message' => 'Get info success',
                'status_code' => 200,
                'data' => [
                    'real' => $real,
                    'browser' => $browser,
                    'setting_print_kitchen' => $store ? $store->setting_print_kitchen : null,
                ],
            ]);

        } catch (\Throwable $th) {
            Log::error('Edge kitchen printRealBrowser failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Print rendering or PDF generation failed: ' . $th->getMessage()], 500);
        }
    }

    public function updatePrintedQuantity(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return response()->json(['status' => false, 'status_code' => 404, 'message' => 'Table not found'], 404);
        }

        try {
            // Call listPrintableItems with markPrinted = true to save state to DB
            $products = $this->listPrintableItems($table, true);

            return response()->json([
                'status' => true,
                'message' => 'Update printed quantity success',
                'status_code' => 200,
            ]);
        } catch (\Throwable $th) {
            Log::error('Edge updatePrintedQuantity failed', ['error' => $th->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Update failed: ' . $th->getMessage()], 500);
        }
    }

    private function generateKitchenPDF($payload, $storeId, $templatePath, $paperSize = 80)
    {
        try {
            $contentWidth = $paperSize == 58 ? 164 : 227;
            $maxTries = 50;
            $tryCount = 0;
            $heightExtra = 0;
            $store = Store::find($storeId);
            $setting_print_kitchen = [];
            $timeZone = $store ? ($store->time_zone ?? config('app.timezone')) : config('app.timezone');

            if ($store && !empty($store->setting_print_kitchen)) {
                $setting_print_kitchen = is_string($store->setting_print_kitchen)
                    ? json_decode($store->setting_print_kitchen, true)
                    : $store->setting_print_kitchen;
            }

            do {
                $pdf = Pdf::loadView($templatePath, [
                    'payment' => $payload,
                    'setting_print_kitchen' => $setting_print_kitchen,
                    'data' => $payload,
                    'bill_setting' => [],
                    'timeZone' => $timeZone,
                ]);
                $contentHeight = $this->calculateKitchenContentHeight($payload, $heightExtra);
                $pdf->setPaper([0, 0, $contentWidth, $contentHeight]);
                $pdf->render();

                $pageCount = $pdf->getDomPDF()->getCanvas()->get_page_count();
                $heightExtra += 50;
                $tryCount++;
            } while ($pageCount > 1 && $tryCount < $maxTries);

            return $pdf->output();
        } catch (\Throwable $th) {
            Log::error('generateKitchenPDF failed', ['error' => $th->getMessage()]);
            throw $th;
        }
    }

    private function calculateKitchenContentHeight($payment, $heightExtra = 0)
    {
        try {
            $baseHeight = 150;
            $itemHeight = 40;
            $products = $payment['products'] ?? [];
            $countProduct = count($products);
            foreach ($products as $value) {
               if(!empty($value['combo_products'])) {
                    $combo = is_string($value['combo_products']) ? json_decode($value['combo_products'], true) : $value['combo_products'];
                    if (is_array($combo)) {
                        $countProduct += count($combo) - 1;
                    }
               }
            }
            $baseHeight += $heightExtra;
            return $baseHeight + ($countProduct * $itemHeight);
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function checkPrintedStatus(Request $request)
    {
        $table = $this->findTable($request);
        if (!$table) {
            return $this->error('Table not found', 404);
        }

        $notPrintedIds = [];

        if ($table->payment) {
            $details = $table->payment->details()
                ->whereColumn('printed_quantity', '<', 'quantity')
                ->whereNull('deleted_at')
                ->get();
            foreach ($details as $detail) {
                $notPrintedIds[] = [
                    'product_id' => $detail->product_id,
                    'product_key' => $detail->product_key,
                ];
            }
        }

        return response()->json([
            'status' => true,
            'status_code' => 200,
            'message' => 'Get info success',
            'data' => [
                'listProductId' => $notPrintedIds,
            ],
        ]);
    }

    private function findTable(Request $request): ?Table
    {
        $tableId = $request->input('table_id', $request->input('id')) ?: $request->input('table.id');
        if (!$tableId) {
            return null;
        }

        $query = Table::with(['payment', 'payment.user'])->whereKey($tableId);
        $storeId = (int) $request->input('store_id', config('app.store_id', 1));
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }

        return $query->first();
    }

    private function tablePrintPayload(Table $table, ?array $products = null): array
    {
        $payment = $table->payment;
        $user = $payment && $payment->user ? $payment->user->toArray() : null;
        $store = Store::find($table->store_id);
        $productsList = $products ?? $this->listItems($table);

        return [
            'id' => $table->id,
            'table_id' => $table->id,
            'tablename' => $table->tablename ?? $table->name ?? null,
            'number_of_people' => $table->number_of_people ?? 0,
            'products' => $productsList,
            'user' => $user,
            'payment_code' => $payment->payment_code ?? null,
            'payment' => $payment ? array_merge($payment->toArray(), [
                'tablename' => $table->tablename ?? $table->name ?? null,
                'products' => $productsList,
                'created_at' => optional($table->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => optional($table->updated_at)->format('Y-m-d H:i:s'),
            ]) : null,
            'created_at' => optional($table->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($table->updated_at)->format('Y-m-d H:i:s'),
            'setting_print_kitchen' => $store ? $store->setting_print_kitchen : null,
        ];
    }

    private function listItems(Table $table): array
    {
        if (!$table->listitem) {
            return [];
        }

        $decoded = json_decode($table->listitem, true) ?: [];

        return collect($decoded['item'] ?? $decoded ?? [])
            ->map(function ($item) {
                if (!isset($item['diff_quantity'])) {
                    $item['diff_quantity'] = $item['quantity'] ?? 0;
                }

                return $item;
            })
            ->values()
            ->all();
    }

    private function listPrintableItems(Table $table, bool $markPrinted = false): array
    {
        if (!$table->payment || !$table->listitem) {
            return [];
        }

        $decoded = json_decode($table->listitem, true) ?: [];
        $rawItems = $decoded['item'] ?? $decoded ?? [];

        $details = $table->payment->details()
            ->with(['product', 'product.print'])
            ->whereColumn('printed_quantity', '<', 'quantity')
            ->whereNull('deleted_at')
            ->orderBy('id', 'asc')
            ->get();

        Log::debug('KitchenPrint listPrintableItems', [
            'table_id' => $table->id,
            'payment_id' => $table->payment_id,
            'has_payment' => $table->payment ? 'yes' : 'no',
            'all_details_count' => $table->payment ? $table->payment->details()->withoutGlobalScope('Illuminate\Database\Eloquent\SoftDeletingScope')->count() : 0,
            'active_details_count' => $table->payment ? $table->payment->details()->count() : 0,
            'where_count' => $details->count(),
        ]);

        $items = [];
        foreach ($details as $detail) {
            $printCount = (int) $detail->quantity - (int) $detail->printed_quantity;
            if ($printCount <= 0) {
                continue;
            }

            $item = $this->findListItemForDetail($rawItems, $detail);
            if (!$item) {
                $item = [
                    'id' => $detail->product_id,
                    'product_id' => $detail->product_id,
                    'quantity' => $detail->quantity,
                    'price' => $detail->price,
                    'total' => $detail->total,
                    'note' => $detail->note,
                ];
            }

            $product = $detail->product;
            $printer = $product && $product->print ? $product->print : null;

            $item['print_id'] = $product ? ($product->print_id ?: 0) : 0;
            $item['printer_type'] = $printer ? $printer->printer_type : 'kitchen';
            $item['paper_size'] = $printer ? $printer->paper_size : 80;
            $item['diff_quantity'] = $printCount;
            $item['printed_quantity'] = (int) $detail->printed_quantity + $printCount;
            $item['print_status'] = ((int) $detail->printed_quantity + $printCount) >= (int) $detail->quantity;

            if (!isset($item['title'])) {
                $item['title'] = $product ? $product->name : '';
            }
            if (!isset($item['extra_product_list'])) {
                $item['extra_product_list'] = json_decode($detail->product_extra, true) ?: [];
            }
            if (!isset($item['combo_products'])) {
                $item['combo_products'] = [];
            }
            if (!isset($item['optional_products'])) {
                $item['optional_products'] = json_decode($detail->optional_products, true) ?: [];
            }

            $items[] = $item;

            if ($markPrinted) {
                $detail->increment('printed_quantity', $printCount);
            }
        }

        if ($markPrinted && !empty($items)) {
            $this->syncPrintedStateToTableListItem($table);
        }

        Log::debug('KitchenPrint listPrintableItems result', ['items_count' => count($items)]);

        return $items;
    }

    private function findListItemForDetail(array $rawItems, $detail): ?array
    {
        if (!empty($detail->product_key) && isset($rawItems[$detail->product_key]) && is_array($rawItems[$detail->product_key])) {
            return $rawItems[$detail->product_key];
        }

        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $item['product_id'] ?? $item['id'] ?? null;
            if ((string) $productId === (string) $detail->product_id) {
                return $item;
            }
        }

        return null;
    }

    private function syncPrintedStateToTableListItem(Table $table): void
    {
        $decoded = json_decode($table->listitem, true) ?: [];
        $rawItems = $decoded['item'] ?? $decoded ?? [];
        $details = $table->payment->details()->whereNull('deleted_at')->get();

        foreach ($details as $detail) {
            foreach ($rawItems as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $productId = $item['product_id'] ?? $item['id'] ?? null;
                $matchesKey = !empty($detail->product_key) && (string) $key === (string) $detail->product_key;
                $matchesProduct = (string) $productId === (string) $detail->product_id;

                if (!$matchesKey && !$matchesProduct) {
                    continue;
                }

                $rawItems[$key]['printed_quantity'] = (int) $detail->printed_quantity;
                $rawItems[$key]['diff_quantity'] = max((int) $detail->quantity - (int) $detail->printed_quantity, 0);
                $rawItems[$key]['print_status'] = (int) $detail->printed_quantity >= (int) $detail->quantity;
            }
        }

        if (isset($decoded['item'])) {
            $decoded['item'] = $rawItems;
            $table->listitem = json_encode($decoded);
        } else {
            $table->listitem = json_encode($rawItems);
        }

        $table->save();
    }

    private function success(array $data)
    {
        return response()->json([
            'status' => true,
            'status_code' => 200,
            'message' => 'Get info success',
            'data' => $data,
        ]);
    }

    private function error($message, int $statusCode)
    {
        return response()->json([
            'status' => false,
            'status_code' => $statusCode,
            'message' => $message,
        ], $statusCode);
    }
}
