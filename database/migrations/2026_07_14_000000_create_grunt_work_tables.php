<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// All the app's tables in one place so the whole schema is easy to read.
return new class extends Migration
{
    public function up(): void
    {
        // The 12-account plain-English chart of accounts.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // Dairy, Sheep & Beef, Arable
            $table->string('owner_name');
        });

        // Page 1: Bank Coding. category_id stays null until someone codes the line.
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transacted_on');
            $table->string('description');
            $table->decimal('amount', 12, 2); // deposits positive, payments negative
            $table->foreignId('category_id')->nullable()->constrained();
            $table->timestamps();
        });

        // Page 2: Inbox. reply_body/replied_at stay null until a reply is sent.
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('from_name');
            $table->string('from_email');
            $table->string('subject');
            $table->text('body');
            $table->dateTime('received_at');
            $table->text('reply_body')->nullable();
            $table->dateTime('replied_at')->nullable();
        });

        // Page 3: Monthly Report. One row per farm/month/category.
        Schema::create('report_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained();
            $table->date('month'); // first day of the month
            $table->string('category');
            $table->decimal('budget', 12, 2);
            $table->decimal('actual', 12, 2);
        });

        // Page 3: regional climate summary, one row per month. Same region
        // for all three farms.
        Schema::create('weather_months', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->decimal('rainfall_mm', 6, 1);
            $table->decimal('rainfall_pct_normal', 5, 1);
            $table->decimal('mean_temp_c', 4, 1);
            $table->decimal('soil_moisture_deficit_mm', 6, 1);
            $table->text('notes');
        });

        // Page 3: the commentary box, one per farm per month.
        Schema::create('report_commentaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained();
            $table->date('month');
            $table->text('body');
            $table->timestamps();
            $table->unique(['farm_id', 'month']);
        });

        // Page 4: Invoice Entry. raw_text is the scanned invoice; the other
        // columns stay null until someone keys the invoice in.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->text('raw_text');
            $table->string('supplier')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('gst_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->foreignId('category_id')->nullable()->constrained();
            $table->dateTime('entered_at')->nullable();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('unit_price', 12, 5)->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        // Page 5: Stock Reconciliation. Counts are for the stock year
        // 1 July 2025 - 30 June 2026. closing_count is what the farmer
        // recorded in the tally book, not what the movements add up to.
        Schema::create('stock_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('opening_count');
            $table->integer('closing_count');
        });

        // The raw paper trail: diary entries, sale dockets, text messages.
        Schema::create('stock_records', function (Blueprint $table) {
            $table->id();
            $table->date('recorded_on');
            $table->string('source'); // Diary, Sale docket, Text message
            $table->text('body');
        });

        // Movements keyed in by the adviser from the raw records.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_class_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // birth, purchase, death, sale
            $table->integer('quantity');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_records');
        Schema::dropIfExists('stock_classes');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('report_commentaries');
        Schema::dropIfExists('weather_months');
        Schema::dropIfExists('report_lines');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('farms');
        Schema::dropIfExists('categories');
    }
};
