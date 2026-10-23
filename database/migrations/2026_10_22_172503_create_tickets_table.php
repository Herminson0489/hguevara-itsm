
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla de tickets de soporte.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('requester_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('ticket_number')->unique();
            $table->string('title');
            $table->text('description');

            $table->string('category')->default('other');
            $table->string('priority')->default('medium');
            $table->string('status')->default('open');

            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'priority']);
            $table->index(['company_id', 'assigned_to']);
        });
    }

    /**
     * Revierte la creación de la tabla.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
