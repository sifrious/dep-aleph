<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aleph_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('identity_key', 64)->unique();
            $table->string('source');
            $table->string('source_identifier');
            $table->string('resource_type')->default('source');
            $table->text('canonical_uri')->nullable();
            $table->string('language')->nullable();
            $table->json('metadata');
            $table->json('identifiers');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['source', 'source_identifier']);
        });

        Schema::create('aleph_authors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('identity_key', 64)->unique();
            $table->string('source');
            $table->string('source_identifier');
            $table->string('name')->nullable();
            $table->json('identifiers');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['source', 'source_identifier']);
        });

        Schema::create('aleph_books', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('identity_key', 64)->unique();
            $table->string('source');
            $table->string('source_identifier');
            $table->string('title')->nullable();
            $table->string('language')->nullable();
            $table->json('identifiers');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['source', 'source_identifier']);
        });

        Schema::create('aleph_book_authors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('identity_key', 64)->unique();
            $table->foreignUlid('book_id')->constrained('aleph_books')->cascadeOnDelete();
            $table->foreignUlid('author_id')->constrained('aleph_authors')->restrictOnDelete();
            $table->string('role');
            $table->unsignedInteger('position')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['book_id', 'author_id', 'role']);
        });

        Schema::create('aleph_editions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('identity_key', 64)->unique();
            $table->foreignUlid('book_id')->constrained('aleph_books')->restrictOnDelete();
            $table->string('source');
            $table->string('source_identifier');
            $table->string('title')->nullable();
            $table->string('language')->nullable();
            $table->string('publisher')->nullable();
            $table->string('published_at')->nullable();
            $table->json('identifiers');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['source', 'source_identifier']);
        });

        Schema::create('aleph_book_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('identity_key', 64)->unique();
            $table->foreignUlid('edition_id')->constrained('aleph_editions')->restrictOnDelete();
            $table->foreignUlid('resource_id')->constrained('aleph_resources')->restrictOnDelete();
            $table->foreignUlid('derived_from_file_id')->nullable();
            $table->string('mime_type');
            $table->string('format')->nullable();
            $table->string('encoding')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->json('hashes');
            $table->json('source_identifiers');
            $table->json('acquisition_metadata');
            $table->timestampTz('acquired_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        Schema::table('aleph_book_files', function (Blueprint $table): void {
            $table->foreign('derived_from_file_id')->references('id')->on('aleph_book_files')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aleph_book_files');
        Schema::dropIfExists('aleph_editions');
        Schema::dropIfExists('aleph_book_authors');
        Schema::dropIfExists('aleph_books');
        Schema::dropIfExists('aleph_authors');
        Schema::dropIfExists('aleph_resources');
    }
};
