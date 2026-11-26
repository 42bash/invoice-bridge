<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_documents', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 16); // invoice | credit
            $table->string('in_id', 32);
            $table->string('number')->nullable();
            $table->date('issue_date')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_siren', 14)->nullable();
            $table->string('processing_rule', 16)->nullable();
            $table->unsignedSmallInteger('type_code')->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedTinyInteger('in_status_id')->nullable();
            $table->boolean('requested')->default(false);
            $table->boolean('deposit')->default(false);
            $table->string('state', 24)->default('discovered')->index();
            $table->unsignedBigInteger('superpdp_id')->nullable()->unique();
            $table->string('external_id', 64)->unique();
            $table->string('last_status_code', 64)->nullable();
            $table->string('last_status_text')->nullable();
            $table->timestamp('last_status_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('directory_status', 24)->nullable();
            $table->json('files')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('payment_reported_at')->nullable();
            $table->json('in_fields')->nullable();
            $table->timestamp('in_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'in_id']);
        });

        Schema::create('inbound_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('superpdp_id')->unique();
            $table->string('seller_name')->nullable();
            $table->string('seller_siren', 14)->nullable()->index();
            $table->string('seller_vat', 32)->nullable();
            $table->string('number')->nullable();
            $table->unsignedSmallInteger('type_code')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('total_without_vat', 14, 2)->nullable();
            $table->decimal('total_vat', 14, 2)->nullable();
            $table->decimal('total_with_vat', 14, 2)->nullable();
            $table->decimal('amount_due', 14, 2)->nullable();
            $table->string('fingerprint', 64)->nullable()->index();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('inbound_documents')->nullOnDelete();
            $table->string('state', 24)->default('received')->index();
            $table->string('in_vendor_id', 32)->nullable();
            $table->string('in_expense_id', 32)->nullable();
            $table->string('last_status_code', 64)->nullable();
            $table->string('last_status_text')->nullable();
            $table->timestamp('last_status_at')->nullable();
            $table->json('errors')->nullable();
            $table->json('files')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('superpdp_event_id')->unique();
            $table->unsignedBigInteger('superpdp_invoice_id')->index();
            $table->string('status_code', 64);
            $table->string('status_text')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('event_created_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exchange_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16)->default('info');
            $table->string('channel', 24)->index();
            $table->string('action', 64);
            $table->text('message');
            $table->string('document_type', 16)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['document_type', 'document_id']);
        });

        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
        Schema::dropIfExists('exchange_logs');
        Schema::dropIfExists('invoice_events');
        Schema::dropIfExists('inbound_documents');
        Schema::dropIfExists('outbound_documents');
    }
};
