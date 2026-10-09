<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('companies', fn(Blueprint $t)=>$t->unsignedInteger('access_version')->default(1)); }
    public function down(): void { Schema::table('companies', fn(Blueprint $t)=>$t->dropColumn('access_version')); }
};
