<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->string('name')->primary();
            $table->string('state')->default('closed');
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('successes')->default(0);
            $table->unsignedInteger('in_flight')->default(0);
            $table->unsignedInteger('failed_at')->nullable();
            $table->unsignedInteger('opened_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('circuit-breaker.stores.database.table', 'circuit_breakers');
    }
};
