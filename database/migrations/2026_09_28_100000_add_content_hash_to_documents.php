<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dấu vân tay SHA-256 của ảnh (sau khi bỏ metadata, trước khi mã hoá) — nhận ra ảnh tải lại y hệt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->string('content_hash', 64)->nullable()->after('encrypted_path');
            $t->index(['patient_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropIndex(['patient_id', 'content_hash']);
            $t->dropColumn('content_hash');
        });
    }
};
