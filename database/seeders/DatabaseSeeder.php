<?php

namespace Database\Seeders;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Email;
use App\Models\Farm;
use App\Models\Invoice;
use App\Models\ReportCommentary;
use App\Models\ReportLine;
use App\Models\StockClass;
use App\Models\StockMovement;
use App\Models\StockRecord;
use App\Models\WeatherMonth;
use Illuminate\Database\Seeder;

/**
 * Loads everything from the fixture files in data/. See data/README.md for
 * what each file is and how the generated ones are produced.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCategories();
        $this->seedFarms();
        $this->seedBankTransactions();
        $this->seedEmails();
        $this->seedMonthlyReport();
        $this->seedInvoices();
        $this->seedStock();
    }

    /** Read a CSV fixture into an array of rows keyed by the header line. */
    private function csv(string $filename): array
    {
        $handle = fopen(base_path("data/$filename"), 'r');
        $header = fgetcsv($handle, escape: '\\');

        $rows = [];
        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);

        return $rows;
    }

    private function seedCategories(): void
    {
        foreach ($this->csv('categories.csv') as $row) {
            Category::create($row);
        }
    }

    private function seedFarms(): void
    {
        foreach ($this->csv('farms.csv') as $row) {
            Farm::create($row);
        }
    }

    private function seedBankTransactions(): void
    {
        // A few lines arrive pre-coded (category column filled in) - they are
        // the worked example of what a correctly coded line looks like.
        $categoryIds = Category::pluck('id', 'name');

        foreach ($this->csv('bank_transactions.csv') as $row) {
            BankTransaction::create([
                'transacted_on' => $row['date'],
                'description' => $row['description'],
                'amount' => $row['amount'],
                'category_id' => $row['category'] ? $categoryIds[$row['category']] : null,
            ]);
        }
    }

    private function seedEmails(): void
    {
        // Each email fixture is: header lines, blank line, body. An optional
        // "--- reply sent <datetime> ---" line marks an already-sent reply.
        foreach (glob(base_path('data/emails/*.txt')) as $path) {
            $text = file_get_contents($path);

            $reply = null;
            $repliedAt = null;
            if (preg_match('/^--- reply sent (.+) ---$/m', $text, $match, PREG_OFFSET_CAPTURE)) {
                $reply = trim(substr($text, $match[0][1] + strlen($match[0][0])));
                $repliedAt = $match[1][0];
                $text = substr($text, 0, $match[0][1]);
            }

            [$headers, $body] = explode("\n\n", $text, 2);
            preg_match('/^From: (.+) <(.+)>$/m', $headers, $from);
            preg_match('/^Received: (.+)$/m', $headers, $received);
            preg_match('/^Subject: (.+)$/m', $headers, $subject);

            Email::create([
                'from_name' => $from[1],
                'from_email' => $from[2],
                'subject' => $subject[1],
                'body' => trim($body),
                'received_at' => $received[1],
                'reply_body' => $reply,
                'replied_at' => $repliedAt,
            ]);
        }
    }

    private function seedMonthlyReport(): void
    {
        $farmIds = Farm::pluck('id', 'name');

        foreach ($this->csv('report_lines.csv') as $row) {
            ReportLine::create([
                'farm_id' => $farmIds[$row['farm']],
                'month' => $row['month'],
                'category' => $row['category'],
                'budget' => $row['budget'],
                'actual' => $row['actual'],
            ]);
        }

        // One commentary is already written - the worked example.
        foreach ($this->csv('report_commentary.csv') as $row) {
            ReportCommentary::create([
                'farm_id' => $farmIds[$row['farm']],
                'month' => $row['month'],
                'body' => $row['body'],
            ]);
        }

        // Regional climate summaries shown alongside the report.
        foreach ($this->csv('weather.csv') as $row) {
            WeatherMonth::create($row);
        }
    }

    private function seedInvoices(): void
    {
        foreach (glob(base_path('data/invoices/*.txt')) as $path) {
            Invoice::create([
                'filename' => basename($path),
                'raw_text' => file_get_contents($path),
            ]);
        }

        // The RD1 invoice is pre-entered as the worked example of a finished
        // entry. Everything else is left for the interns to key in.
        $rd1 = Invoice::where('filename', 'INV-01-rd1-farm-supplies.txt')->first();
        $rd1->update([
            'supplier' => 'RD1 Hamilton',
            'invoice_date' => '2026-05-18',
            'total' => 2341.98,
            'category_id' => Category::where('name', 'Feed')->value('id'),
            'entered_at' => now(),
        ]);
        $rd1->lines()->createMany([
            ['description' => 'Calf meal 20% protein, bulk 1.5 tonne', 'amount' => 1340.00],
            ['description' => 'Mineral pellets, 4 x 25kg', 'amount' => 612.50],
            ['description' => 'Freight - Riverbend Rd', 'amount' => 84.00],
        ]);
    }

    private function seedStock(): void
    {
        foreach ($this->csv('stock_classes.csv') as $row) {
            StockClass::create($row);
        }

        foreach ($this->csv('stock_records.csv') as $row) {
            StockRecord::create([
                'recorded_on' => $row['date'],
                'source' => $row['source'],
                'body' => $row['body'],
            ]);
        }

        // One movement is already keyed in (the docking tally) - the worked
        // example of turning a raw record into a movement.
        $classIds = StockClass::pluck('id', 'name');
        foreach ($this->csv('stock_movements.csv') as $row) {
            StockMovement::create([
                'stock_class_id' => $classIds[$row['stock_class']],
                'type' => $row['type'],
                'quantity' => $row['quantity'],
                'note' => $row['note'],
            ]);
        }
    }
}
