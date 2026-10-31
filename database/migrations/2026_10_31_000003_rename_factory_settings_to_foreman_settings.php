<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('factory_settings', 'foreman_settings');
    }

    public function down(): void
    {
        Schema::rename('foreman_settings', 'factory_settings');
    }
};
