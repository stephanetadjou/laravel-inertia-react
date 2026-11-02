<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cours et séances de groupe (module « seances_groupe »). Un cours régulier
 * (activité du catalogue, lieu = établissement, coach = intervenant, jour,
 * heure, places) produit ses séances datées ; on y inscrit des clients ou
 * personnes suivies, et on fait l'appel (présent / absent / excusé).
 * Une séance annulée garde son motif ; un rattrapage pointe vers la séance
 * qu'il remplace (makeup_of_id). Dates en texte AAAA-MM-JJ (comparaisons
 * portables MySQL / SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('weekday');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->unsignedSmallInteger('capacity')->default(20);
            $table->string('starts_on', 10);
            $table->string('ends_on', 10)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('session_date', 10);
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('status', 20)->default('scheduled');
            $table->string('cancel_reason', 30)->nullable();
            $table->string('cancel_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('makeup_of_id')->nullable()->constrained('class_sessions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_class_id', 'session_date', 'start_time']);
            $table->index(['organization_id', 'session_date']);
        });

        Schema::create('class_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('enrolled_on', 10);
            $table->string('ended_on', 10)->nullable();
            $table->timestamps();

            $table->unique(['group_class_id', 'customer_id']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['class_session_id', 'customer_id']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('class_enrollments');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('group_classes');
    }
};
