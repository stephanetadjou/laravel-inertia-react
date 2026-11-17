<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\ClassEnrollment;
use App\Models\ClassSession;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\Installment;
use App\Models\LearnerTraining;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Role;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\Training;
use App\Models\User;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use App\Services\GroupClasses;
use App\Services\Installments;
use App\Services\Learners;
use App\Services\PhoneNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-école fictive « Auto-école Horizon » (secteur Centre de formation) :
 * cas pilote du module « Suivi des apprenants ». Formations Permis B (code
 * en salle + 20 h de conduite, examens Code puis Conduite, forfait payé en
 * 3 fois) et Code de la route seul ; 3 moniteurs, 3 voitures et la salle de
 * code ; 40 élèves à tous les stades : en code, en conduite, examen prévu,
 * permis obtenu, élèves qui décrochent, échecs répétés, abandons, tranches
 * en retard. Données fictives, jamais en production.
 *
 * Mot de passe : variable DEMO_PASSWORD, sinon « Demo-AutoEcole-2026 ».
 */
class AutoEcoleDemoSeeder extends Seeder
{
    public const SLUG = 'demo-auto-ecole';

    public const DEFAULT_PASSWORD = 'Demo-AutoEcole-2026';

    private const FIRST = ['Merveille', 'Junior', 'Grâce', 'Cédric', 'Ornella', 'Fabrice', 'Prisca', 'Yannick', 'Dorcas', 'Lionel', 'Estelle', 'Brice', 'Sandra', 'Hervé', 'Nadège', 'Rodrigue', 'Laetitia', 'Arnaud',
        'Christelle', 'Ismaël', 'Ruth', 'Serge', 'Pélagie', 'Kevin', 'Gisèle', 'Patrick', 'Anaïs', 'Joël', 'Viviane', 'Steeve', 'Carine', 'Wilfried', 'Josiane', 'Fred', 'Murielle', 'Alain', 'Sonia', 'Guy', 'Diane', 'Thierry'];

    private const LAST = ['Mba', 'Obame', 'Nguema', 'Ondo', 'Mintsa', 'Ella', 'Moussavou', 'Ndong', 'Mbadinga', 'Ovono', 'Essono', 'Makaya', 'Koumba', 'Nzé', 'Mabika', 'Boussougou', 'Mouketou', 'Ekomie', 'Biyoghe', 'Engone'];

    /**
     * Profils des 40 élèves : [profil, nombre].
     * conduite : code réussi, en leçons de conduite ; code : en salle de code ;
     * permis : tout réussi ; decroche : plus de leçon depuis 3 à 5 semaines ;
     * echecs : 2 échecs à la conduite, pas de nouvelle date ; code_seul : formation Code ;
     * nouveau : inscrit cette semaine ; abandon ; pause.
     */
    private const PROFILES = [['conduite', 10], ['code', 8], ['permis', 5], ['decroche', 4], ['echecs', 3], ['code_seul', 4], ['nouveau', 3], ['abandon', 2], ['pause', 1]];

    /** Créneaux déjà pris par moniteur : « id|AAAA-MM-JJ HH ». @var array<string, true> */
    private array $taken = [];

    public function run(): void
    {
        mt_srand(2026);
        $password = env('DEMO_PASSWORD', self::DEFAULT_PASSWORD);
        $today = today();

        if ($previous = Organization::where('slug', self::SLUG)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $organization = Organization::create([
            'name' => 'Auto-école Horizon',
            'slug' => self::SLUG,
            'sector' => 'formation',
            'currency' => 'XAF',
            'timezone' => 'Africa/Libreville',
            'whatsapp_api_enabled' => false,
            'status' => Organization::STATUS_ACTIVE,
            'plan' => 'equipe',
            'report_settings' => ['enabled' => true, 'recipients' => ['directeur@demo-auto-ecole.test'], 'send_at' => '20:00', 'monthly_target' => 4000000],
        ]);
        $organization->setModules(['formation', 'rdv', 'seances_groupe', 'abonnements', 'tranches', 'catalogue', 'ressources', 'prospects', 'reponses_auto', 'rappels_rdv'], [
            'seances_groupe' => ['capacity' => 30, 'makeup_after' => 2, 'makeup_weekday' => 6],
            'abonnements' => ['period' => 'season', 'season_start' => $today->copy()->subMonths(8)->startOfMonth()->toDateString(), 'season_end' => $today->copy()->addMonths(10)->endOfMonth()->toDateString()],
            'tranches' => ['due_day' => 5, 'reminders' => [-2, 0, 7]],
            'formation' => ['dropout_days' => 14, 'behind_percent' => 30],
        ], reviewed: true);
        $organization->customer_label = 'apprenants';
        $organization->save();

        Role::seedDefaultsFor($organization);
        $role = fn (string $name) => Role::where('organization_id', $organization->id)->where('name', $name)->value('id');
        $brand = Brand::create(['organization_id' => $organization->id, 'name' => 'Auto-école Horizon']);
        $store = Store::create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'Horizon Louis', 'city' => 'Libreville']);

        $director = User::create(['name' => 'Bernadette Ondo (directrice)', 'email' => 'directeur@demo-auto-ecole.test', 'password' => $password, 'organization_id' => $organization->id, 'role_id' => $role('Super administrateur')]);
        Auth::setUser($director);

        // Catalogue : leçons et frais.
        $category = Category::create(['organization_id' => $organization->id, 'name' => 'Permis et code']);
        $lesson = CatalogItem::create(['organization_id' => $organization->id, 'type' => CatalogItem::TYPE_SERVICE, 'name' => 'Leçon de conduite', 'category_id' => $category->id, 'price' => 15000, 'duration_minutes' => 60, 'active' => true]);
        $codeItem = CatalogItem::create(['organization_id' => $organization->id, 'type' => CatalogItem::TYPE_SERVICE, 'name' => 'Séance de code', 'category_id' => $category->id, 'price' => 5000, 'duration_minutes' => 60, 'active' => true]);
        CatalogItem::create(['organization_id' => $organization->id, 'type' => CatalogItem::TYPE_PRODUCT, 'name' => 'Livre du code de la route', 'category_id' => $category->id, 'price' => 8000, 'active' => true]);

        // Moniteurs (avec compte) et véhicules.
        $instructors = [];
        foreach ([['Jean-Pierre Ndong', 'jp', '#0E7BD6', 'Peugeot 208', 'GA-482-AB'], ['Christelle Mba', 'christelle', '#00C896', 'Toyota Yaris', 'GA-913-CK'], ['Armand Obiang', 'armand', '#F59E0B', 'Renault Clio (boîte auto)', 'GA-205-DL']] as [$name, $slug, $color, $car, $plate]) {
            $user = User::create(['name' => "Moniteur {$name}", 'email' => "moniteur-{$slug}@demo-auto-ecole.test", 'password' => $password, 'organization_id' => $organization->id, 'role_id' => $role('Responsable de boutique'), 'store_id' => $store->id]);
            $instructors[] = [
                'staff' => StaffMember::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'user_id' => $user->id, 'name' => $name, 'color' => $color, 'active' => true, 'bookable_online' => true]),
                'car' => BookableResource::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'name' => $car, 'kind' => 'vehicule', 'reference' => $plate, 'active' => true]),
            ];
        }
        $room = BookableResource::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'name' => 'Salle de code', 'kind' => 'salle', 'active' => true]);
        $codeTeacher = $instructors[1]['staff'];

        // Formules : forfait permis payé en 3 fois, code seul.
        $fees = [['label' => 'Inscription', 'amount' => 25000], ['label' => 'Frais de dossier (examens)', 'amount' => 30000]];
        $permisPlan = Plan::create(['organization_id' => $organization->id, 'name' => 'Forfait Permis B — 20 h', 'catalog_item_id' => $lesson->id, 'price' => 350000, 'period' => 'season', 'fees' => $fees, 'options' => [], 'active' => true]);
        $codePlan = Plan::create(['organization_id' => $organization->id, 'name' => 'Forfait Code de la route', 'catalog_item_id' => $codeItem->id, 'price' => 90000, 'period' => 'season', 'fees' => [['label' => 'Inscription', 'amount' => 25000]], 'options' => [], 'active' => true]);

        $permis = Training::create(['organization_id' => $organization->id, 'name' => 'Permis B', 'description' => 'Code en salle (2 séances par semaine) puis 20 h de conduite.', 'hours_planned' => 20, 'sessions_planned' => 12, 'duration_weeks' => 16, 'exams' => ['Code', 'Conduite'], 'plan_id' => $permisPlan->id, 'active' => true]);
        $code = Training::create(['organization_id' => $organization->id, 'name' => 'Code de la route', 'description' => 'Préparation accélérée à l’examen du code, le samedi.', 'hours_planned' => 0, 'sessions_planned' => 6, 'duration_weeks' => 6, 'exams' => ['Code'], 'plan_id' => $codePlan->id, 'active' => true]);

        $classStart = $today->copy()->subWeeks(30)->toDateString();
        $codeClasses = [
            $this->groupClass($organization, $store, $codeItem, $codeTeacher, $permis, 'Code — lundi 18 h', 1, '18:00', '19:00', $classStart),
            $this->groupClass($organization, $store, $codeItem, $codeTeacher, $permis, 'Code — mercredi 18 h', 3, '18:00', '19:00', $classStart),
        ];
        $saturday = $this->groupClass($organization, $store, $codeItem, $codeTeacher, $code, 'Code accéléré — samedi 9 h', 6, '09:00', '11:00', $classStart);

        $learners = app(Learners::class);
        $people = [];
        $n = 0;

        CustomerLifecycle::batch(function () use ($store, $director, $learners, $permis, $code, $instructors, $today, &$people, &$n) {
            foreach (self::PROFILES as [$profile, $count]) {
                for ($k = 0; $k < $count; $k++, $n++) {
                    $name = self::FIRST[$n % count(self::FIRST)].' '.self::LAST[($n * 7) % count(self::LAST)];
                    $phone = PhoneNormalizer::normalize('0'.(['77', '66', '62', '74'][$n % 4]).sprintf('%06d', 210000 + $n * 53));
                    $customer = Contact::create(['store_id' => $store->id, 'name' => $name, 'phone_e164' => $phone, 'consent_status' => $n % 6 === 5 ? 'unknown' : 'opt_in', 'consent_source' => 'manuel'])->customer;
                    $instructor = $instructors[$n % 3];

                    $weeks = match ($profile) {
                        'conduite' => mt_rand(10, 16),
                        'code' => mt_rand(4, 8),
                        'permis' => mt_rand(18, 26),
                        'decroche' => mt_rand(7, 10),
                        'echecs' => mt_rand(16, 20),
                        'code_seul' => mt_rand(2, 6),
                        'nouveau' => 0,
                        'abandon' => mt_rand(10, 14),
                        'pause' => 9,
                    };
                    $start = $today->copy()->subWeeks($weeks)->subDays(mt_rand(0, 4));
                    $learner = $learners->enroll($director, [
                        'customer_id' => $customer->id,
                        'training_id' => $profile === 'code_seul' ? $code->id : $permis->id,
                        'store_id' => $store->id,
                        'started_on' => $start->toDateString(),
                        'staff_member_id' => $profile === 'code_seul' ? null : $instructor['staff']->id,
                        'extra_hours' => $profile === 'echecs' ? 6 : 0,
                        'with_plan' => true,
                        'installments' => $profile === 'code_seul' ? 1 : 3,
                    ]);
                    $people[] = ['learner' => $learner, 'profile' => $profile, 'start' => $start, 'instructor' => $instructor, 'customer' => $customer];
                }
            }
        });

        // Leçons, cours de code et examens selon le profil.
        $groupClasses = app(GroupClasses::class);
        $groupClasses->ensureSessions($organization->id, Carbon::parse($classStart), $today->copy()->addWeeks(3));
        $quiet = [];

        foreach ($people as $p) {
            /** @var LearnerTraining $l */
            $l = $p['learner'];
            $start = $p['start'];
            $codeDate = $start->copy()->addWeeks(6)->next(Carbon::TUESDAY);
            $classes = $p['profile'] === 'code_seul' ? [$saturday] : $codeClasses;

            [$codeUntil, $lessons, $lessonFrom, $lessonTo, $future, $exams, $status] = match ($p['profile']) {
                'conduite' => [$codeDate, mt_rand(10, 18), $start->copy()->addWeeks(4), $today->copy()->subDays(mt_rand(2, 6)), mt_rand(1, 2),
                    [['Code', $codeDate, 'passed'], ...(mt_rand(0, 2) === 0 ? [['Conduite', $today->copy()->addDays(mt_rand(6, 20)), 'scheduled']] : [])], 'active'],
                'code' => [null, mt_rand(0, 4), $start->copy()->addWeeks(3), $today->copy()->subDays(mt_rand(1, 5)), 1,
                    mt_rand(0, 1) ? [['Code', $today->copy()->addDays(mt_rand(3, 14)), 'scheduled']] : [], 'active'],
                'permis' => [$codeDate, mt_rand(20, 23), $start->copy()->addWeeks(4), $start->copy()->addWeeks(15), 0,
                    [['Code', $codeDate, 'passed'], ['Conduite', $start->copy()->addWeeks(16), 'passed']], 'active'],
                'decroche' => [$codeDate->min($today->copy()->subWeeks(4)), mt_rand(2, 6), $start->copy()->addWeeks(3), $today->copy()->subDays(mt_rand(18, 35)), 0,
                    [['Code', $codeDate->min($today->copy()->subWeeks(4)), 'passed']], 'active'],
                'echecs' => [$codeDate, mt_rand(22, 26), $start->copy()->addWeeks(4), $today->copy()->subDays(mt_rand(3, 8)), 0,
                    [['Code', $codeDate, 'passed'], ['Conduite', $today->copy()->subDays(40), 'failed'], ['Conduite', $today->copy()->subDays(12), 'failed']], 'active'],
                'code_seul' => [null, 0, $start, $start, 0, mt_rand(0, 1) ? [['Code', $today->copy()->addDays(mt_rand(4, 12)), 'scheduled']] : [], 'active'],
                'nouveau' => [null, mt_rand(0, 1), $start, $today->copy()->subDay(), 1, [], 'active'],
                'abandon' => [null, mt_rand(1, 4), $start->copy()->addWeeks(3), $start->copy()->addWeeks(6), 0, [], 'dropped'],
                'pause' => [$codeDate, 6, $start->copy()->addWeeks(4), $today->copy()->subWeeks(3), 0, [['Code', $codeDate, 'passed']], 'paused'],
            };

            // Cours de code : inscrit dès le début, sorti après l'examen du code.
            foreach ($classes as $class) {
                ClassEnrollment::create(['group_class_id' => $class->id, 'customer_id' => $l->customer_id, 'subscription_id' => $l->subscription_id,
                    'enrolled_on' => $start->toDateString(), 'ended_on' => $codeUntil?->toDateString() ?? ($status === 'dropped' ? $lessonTo->toDateString() : null)]);
            }
            if (in_array($p['profile'], ['decroche', 'pause'], true)) {
                $quiet[$l->customer_id] = $lessonTo->toDateString();
            }

            // Leçons de conduite passées (une absence de temps en temps) et à venir.
            $car = $p['instructor']['car'];
            $staff = $p['instructor']['staff'];
            for ($i = 0; $i < $lessons; $i++) {
                $day = $lessons === 1 ? $lessonTo->copy() : $lessonFrom->copy()->addDays((int) round($i * max(0, $lessonFrom->diffInDays($lessonTo)) / max(1, $lessons - 1)));
                $this->lesson($l, $staff, $car, $day->min($today->copy()->subDay()), $i % 9 === 4 ? 'no_show' : 'done');
            }
            for ($i = 0; $i < $future; $i++) {
                $this->lesson($l, $staff, $car, $today->copy()->addDays(1 + $i * 3 + mt_rand(0, 2)), $i === 0 ? 'confirmed' : 'planned');
            }

            foreach ($exams as [$type, $date, $result]) {
                $exam = $learners->scheduleExam($l, ['type' => $type, 'exam_on' => $date->toDateString(), 'exam_time' => $type === 'Code' ? '08:00' : '07:30', 'result' => $result]);
                if ($result !== 'scheduled') {
                    $exam->update(['score' => $type === 'Code' ? ($result === 'passed' ? mt_rand(35, 40).'/40' : mt_rand(25, 34).'/40') : null]);
                }
            }

            if ($status !== 'active') {
                $l->refresh()->update(['status' => $status, 'ended_on' => $status === 'dropped' ? $lessonTo->toDateString() : null,
                    'end_reason' => $status === 'dropped' ? ['Départ à l’étranger', 'Problème de financement'][mt_rand(0, 1)] : null]);
            }
        }

        // Appel des cours de code passés : ~85 % de présence, plus personne pour ceux qui décrochent.
        ClassSession::where('organization_id', $organization->id)
            ->where('session_date', '<', $today->toDateString())
            ->where('status', ClassSession::STATUS_SCHEDULED)
            ->with('groupClass')
            ->orderBy('session_date')
            ->get()
            ->each(function (ClassSession $session) use ($groupClasses, $quiet, $director) {
                $marks = $groupClasses->participants($session)->mapWithKeys(fn (Customer $c) => [$c->id => match (true) {
                    isset($quiet[$c->id]) && $session->session_date > $quiet[$c->id] => 'absent',
                    mt_rand(1, 100) <= 85 => 'present',
                    mt_rand(0, 1) === 1 => 'excused',
                    default => 'absent',
                }])->all();
                if ($marks !== []) {
                    $groupClasses->mark($session, $marks, $director);
                }
            });

        // Salle de code réservée pour la prochaine séance d'examen blanc.
        Appointment::create(['store_id' => $store->id, 'customer_id' => $people[0]['customer']->id, 'created_by' => $director->id, 'starts_at' => $today->copy()->next(Carbon::THURSDAY)->setTime(17, 0),
            'duration_minutes' => 120, 'service' => 'Examen blanc du code', 'status' => 'planned', 'staff_member_id' => $codeTeacher->id, 'resource_id' => $room->id, 'kind' => 'rdv', 'learner_training_id' => $people[0]['learner']->id]);

        // Paiements : tranches échues réglées, sauf pour un élève sur six.
        $installments = app(Installments::class);
        CustomerLifecycle::batch(function () use ($organization, $installments, $director, $today) {
            Installment::whereHas('schedule', fn ($q) => $q->where('organization_id', $organization->id))
                ->where('due_on', '<', $today->toDateString())
                ->with('schedule')
                ->orderBy('due_on')
                ->get()
                ->each(function (Installment $installment) use ($installments, $director) {
                    if ($installment->schedule->customer_id % 6 === 0 && str_contains((string) $installment->label, 'tranche')) {
                        return;
                    }
                    $paidAt = $installment->due_on->copy()->addDays(mt_rand(0, 5))->setTime(mt_rand(8, 18), mt_rand(0, 59));
                    $installments->pay($installment, $director, ['cash', 'airtel_money', 'moov_money'][mt_rand(0, 2)], $paidAt->min(now()), refreshScores: false);
                });
        });

        app(CustomerLifecycle::class)->refreshOrganization($organization);
        app(CustomerScoring::class)->refreshOrganization($organization);
        Auth::forgetUser();
    }

    private function groupClass(Organization $organization, Store $store, CatalogItem $item, StaffMember $teacher, Training $training, string $name, int $weekday, string $start, string $end, string $from): GroupClass
    {
        return GroupClass::create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'catalog_item_id' => $item->id, 'staff_member_id' => $teacher->id, 'training_id' => $training->id,
            'name' => $name, 'weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'capacity' => 30, 'starts_on' => $from, 'active' => true,
        ]);
    }

    /** Leçon d'une heure au premier créneau libre du moniteur ce jour-là (8 h – 17 h, hors dimanche). */
    private function lesson(LearnerTraining $learner, StaffMember $staff, BookableResource $car, Carbon $day, string $status): void
    {
        $day = $day->copy();
        if ($day->isSunday()) {
            $day->subDay();
        }

        foreach ([8, 9, 10, 11, 14, 15, 16, 17, 7, 12, 13] as $hour) {
            $key = $staff->id.'|'.$day->toDateString().' '.$hour;
            if (! isset($this->taken[$key])) {
                $this->taken[$key] = true;
                Appointment::create([
                    'store_id' => $learner->store_id, 'customer_id' => $learner->customer_id, 'created_by' => Auth::id(),
                    'starts_at' => $day->copy()->setTime($hour, 0), 'duration_minutes' => 60, 'service' => 'Leçon de conduite', 'status' => $status,
                    'staff_member_id' => $staff->id, 'resource_id' => $car->id, 'kind' => 'rdv', 'learner_training_id' => $learner->id,
                ]);

                return;
            }
        }
    }
}
