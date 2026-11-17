<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centre de formation (module « formation ») et ressources réservables
 * (module « ressources »).
 *
 * - trainings : formations proposées (heures de leçons individuelles et
 *   séances collectives prévues, durée conseillée, examens à passer, formule
 *   de paiement associée) ;
 * - learner_trainings : parcours d'un apprenant dans une formation (heures
 *   prévues, heures achetées en plus, formateur référent, statut) ;
 * - learner_exams : examens (type, date, tentative, résultat) ;
 * - bookable_resources : véhicules, salles, matériel réservés avec un
 *   rendez-vous (appointments.resource_id) ;
 * - appointments.learner_training_id : leçon comptée dans le parcours ;
 * - group_classes.training_id : cours collectif (ex. code) compté dans la
 *   formation.
 *
 * Dates en texte AAAA-MM-JJ (comparaisons portables MySQL / SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('hours_planned')->default(0);
            $table->unsignedSmallInteger('sessions_planned')->default(0);
            $table->unsignedSmallInteger('duration_weeks')->nullable();
            $table->json('exams')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('learner_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('started_on', 10);
            $table->string('target_on', 10)->nullable();
            $table->unsignedSmallInteger('hours_planned')->default(0);
            $table->unsignedSmallInteger('extra_hours')->default(0);
            $table->unsignedSmallInteger('sessions_planned')->default(0);
            $table->string('status', 20)->default('active');
            $table->string('ended_on', 10)->nullable();
            $table->string('end_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('learner_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('exam_on', 10);
            $table->string('exam_time', 5)->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('result', 20)->default('scheduled');
            $table->string('score', 20)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'exam_on']);
        });

        Schema::create('bookable_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('kind', 20)->default('vehicule');
            $table->string('reference', 60)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('learner_training_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('bookable_resources')->nullOnDelete();
            $table->index(['resource_id', 'starts_at']);
        });

        Schema::table('group_classes', function (Blueprint $table) {
            $table->foreignId('training_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('group_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('training_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['resource_id', 'starts_at']);
            $table->dropConstrainedForeignId('resource_id');
            $table->dropConstrainedForeignId('learner_training_id');
        });

        Schema::dropIfExists('bookable_resources');
        Schema::dropIfExists('learner_exams');
        Schema::dropIfExists('learner_trainings');
        Schema::dropIfExists('trainings');
    }
};
