<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('transport_stops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transport_route_id');
            $table->string('name');
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
            $table->foreign('transport_route_id')->references('id')->on('transport_routes')->onDelete('cascade');
        });

        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_no');
            $table->unsignedInteger('capacity');
            $table->unsignedBigInteger('transport_route_id')->nullable();
            $table->timestamps();
            $table->foreign('transport_route_id')->references('id')->on('transport_routes')->onDelete('set null');
        });

        Schema::create('transport_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_record_id');
            $table->unsignedBigInteger('transport_vehicle_id');
            $table->unsignedBigInteger('transport_stop_id');
            $table->timestamps();
            // A student has at most one active assignment; reassign replaces.
            $table->unique('student_record_id');
            $table->foreign('transport_vehicle_id')->references('id')->on('transport_vehicles')->onDelete('cascade');
            $table->foreign('transport_stop_id')->references('id')->on('transport_stops')->onDelete('cascade');
        });

        Schema::create('transport_fees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transport_assignment_id');
            $table->string('month', 7); // 'Y-m', e.g. 2026-10
            $table->decimal('amount', 10, 2);
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['transport_assignment_id', 'month']);
            $table->foreign('transport_assignment_id')->references('id')->on('transport_assignments')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_fees');
        Schema::dropIfExists('transport_assignments');
        Schema::dropIfExists('transport_vehicles');
        Schema::dropIfExists('transport_stops');
        Schema::dropIfExists('transport_routes');
    }
};
