<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 core infrastructure tables:
 * settings, user_notifications, document_types, documents, document_sequences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string|int|decimal|bool
            $table->string('group', 50)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Named user_notifications to avoid clashing with Laravel's Notifiable
        // `notifications` table/relation.
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('info'); // info|success|warning|error
            $table->string('category', 60)->index();     // e.g. invoice_overdue
            $table->string('title', 200);
            $table->text('message');
            $table->string('action_url', 500)->nullable();
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('dedupe_key', 191)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->unique(['user_id', 'dedupe_key']);
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('requires_expiry')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('documentable_type', 60);
            $table->unsignedBigInteger('documentable_id');
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('document_number', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('remarks')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['documentable_type', 'documentable_id']);
        });

        // Gap-free business numbering (INV-2026-00001 ...), used under row lock.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('sequence_key', 100)->unique();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('settings');
    }
};
