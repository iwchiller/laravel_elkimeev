<?php

namespace App\Jobs;

use App\Models\Sale;
use App\Models\Order;
use App\Models\Income;
use App\Models\Stock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessFetch implements ShouldQueue
{
    use Queueable;

    protected int $page_id;
    protected int $records_limit;
    protected array $parameters;
    private string $folder;

    /**
     * Create a new job instance.
     */
    public function __construct($folder = "sales", $page_id = 1, $records_limit = 500)
    {
        $this->folder = $folder;
        $this->page_id = $page_id;
        $this->records_limit = $records_limit;
        $this->parameters = $this->create_url($page_id);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        for ($n = 0; $n < 3; $n++) {
            $response = Http::get($_ENV['HTTP_API_URL'] . $this->folder, $this->parameters);
            if (!$response->ok()) {
                $response_status = $response->status();
                Log::error("responce status = " . $response_status);
                if ($response_status == 429) {
                    $response_header = $response->headers();
                    $sleep_seconds_str = array_first($response_header["Retry-After"]);
                    $sleep_seconds = intval($sleep_seconds_str);
                    Log::error("page_id = " . $this->page_id . " sleep seconds = " . $sleep_seconds);
                    sleep($sleep_seconds + 1);
                    continue;
                }
            } else {
                break;
            }
        }
        $records = $response->json('data');
        switch ($this->folder) {
            case "sales":
                Sale::upsert($records, uniqueBy: ['g_number']);
                break;
            case "orders":
                Order::upsert($records, uniqueBy: ['g_number']);
                break;
            case "incomes":
                Income::upsert($records, uniqueBy: ['income_id', 'number', 'date', 'last_change_date', 'supplier_article', 'tech_size', 'barcode', 'quantity', 'total_price', 'date_close', 'warehouse_name', 'nm_id']);
                break;
            case "stocks":
                Stock::upsert($records, uniqueBy: ["date", "last_change_date", "supplier_article", "tech_size", "barcode", "quantity", "is_supply", "is_realization", "quantity_full", "warehouse_name", "in_way_to_client", "in_way_from_client", "nm_id", "subject", "category", "brand", "sc_code", "price", "discount"]);
                break;
            default: break;
        }
    }

    private function create_url($page_id): array
    {
        return [
            "dateFrom" => ($this->folder === "stocks" ? date("Y-m-d") : "1970-01-01"),
            "dateTo" => date("Y-m-d"),
            "page" => $page_id,
            "key" => $_ENV['HTTP_API_KEY'],
            "limit" => $this->records_limit
        ];
    }

    public function get_max_page(): int
    {
        $response = Http::get($_ENV['HTTP_API_URL'] . $this->folder, $this->parameters);
        if (!$response->ok()) {
            return -1;
        }
        $meta = $response->json("meta");
        if (is_null($meta)) {
            return -1;
        }
        $last_page = $meta["last_page"];
        if (!is_null($last_page)) {
            return (int)$last_page;
        } else {
            return -1;
        }
    }
}
