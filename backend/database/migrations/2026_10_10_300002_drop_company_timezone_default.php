<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// No invented timezone: every company states its own. Existing rows keep their stored value.
return new class extends Migration {
    public function up(): void { DB::statement('ALTER TABLE companies ALTER COLUMN timezone DROP DEFAULT'); }
    public function down(): void { DB::statement("ALTER TABLE companies ALTER COLUMN timezone SET DEFAULT 'Asia/Dubai'"); }
};
