<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\BunkerStem;
use App\Models\CaptainReport;
use App\Models\CargoType;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Currency;
use App\Models\DaCostCategory;
use App\Models\Document;
use App\Models\Enquiry;
use App\Models\Estimation;
use App\Models\EstimationScenario;
use App\Models\ExchangeRate;
use App\Models\ExpenseCategory;
use App\Models\Fixture;
use App\Models\FuelType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LaytimeCalculation;
use App\Models\MilestoneType;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\OffHireEvent;
use App\Models\OffshoreActivity;
use App\Models\OffshoreActivityType;
use App\Models\OffshoreLocation;
use App\Models\OffshoreProject;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Port;
use App\Models\PortCall;
use App\Models\PortDa;
use App\Models\PortDistance;
use App\Models\RevenueCategory;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselConsumptionProfile;
use App\Models\VesselStatusHistory;
use App\Models\VesselType;
use App\Models\Voyage;
use App\Models\VoyageExpense;
use App\Models\VoyageMilestone;
use App\Models\VoyageRevenue;
use App\Policies\RolePolicy;
use App\Services\Ais\AisProviderInterface;
use App\Services\Ais\ManualAisProvider;
use App\Services\Ais\NullAisProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AIS provider chosen by config (BR-AIS-01 is open: only none/manual exist). Tests may swap it with app()->instance().
        $this->app->singleton(AisProviderInterface::class, fn () => match (config('offshore.ais.provider')) {
            'manual' => new ManualAisProvider,
            default => new NullAisProvider,
        });
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureAudit();
        $this->configurePasswords();
    }

    private function configureModels(): void
    {
        // Strict in dev/test: no lazy loading (N+1), no silently discarded attributes.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Short, stable aliases stored in polymorphic columns (never class names).
        Relation::enforceMorphMap([
            'users' => User::class,
            'documents' => Document::class,
            'roles' => Role::class,
            'vessels' => Vessel::class,
            'companies' => Company::class,
            'contacts' => Contact::class,
            'ports' => Port::class,
            'offshore-locations' => OffshoreLocation::class,
            // Audited models (activity_log.subject_type)
            'currencies' => Currency::class,
            'exchange-rates' => ExchangeRate::class,
            'company-bank-accounts' => CompanyBankAccount::class,
            'port-distances' => PortDistance::class,
            'vessel-consumption-profiles' => VesselConsumptionProfile::class,
            'vessel-status-history' => VesselStatusHistory::class,
            'vessel-types' => VesselType::class,
            'fuel-types' => FuelType::class,
            'cargo-types' => CargoType::class,
            'offshore-activity-types' => OffshoreActivityType::class,
            'milestone-types' => MilestoneType::class,
            'expense-categories' => ExpenseCategory::class,
            'revenue-categories' => RevenueCategory::class,
            'da-cost-categories' => DaCostCategory::class,
            'enquiries' => Enquiry::class,
            'estimations' => Estimation::class,
            'estimation-scenarios' => EstimationScenario::class,
            'offers' => Offer::class,
            'offer-revisions' => OfferRevision::class,
            'fixtures' => Fixture::class,
            'voyages' => Voyage::class,
            'contracts' => Contract::class,
            'contract-amendments' => ContractAmendment::class,
            'port-calls' => PortCall::class,
            'voyage-milestones' => VoyageMilestone::class,
            'off-hire-events' => OffHireEvent::class,
            'captain-reports' => CaptainReport::class,
            'offshore-projects' => OffshoreProject::class,
            'offshore-activities' => OffshoreActivity::class,
            'bunker-stems' => BunkerStem::class,
            'port-das' => PortDa::class,
            'laytime-calculations' => LaytimeCalculation::class,
            'tax-codes' => TaxCode::class,
            'voyage-revenues' => VoyageRevenue::class,
            'voyage-expenses' => VoyageExpense::class,
            'invoices' => Invoice::class,
            'invoice-lines' => InvoiceLine::class,
            'payables' => Payable::class,
            'payments' => Payment::class,
            'payment-allocations' => PaymentAllocation::class,
        ]);
    }

    private function configureAuthorization(): void
    {
        // Super Admin bypasses all permission checks. Policies that also
        // enforce record state must call a service guard, not rely on this.
        Gate::before(fn (User $user) => $user->hasRole(UserRole::SuperAdmin->value) ? true : null);

        // Spatie Role is outside App\\Models, so register its policy explicitly.
        Gate::policy(Role::class, RolePolicy::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(5)->by('pwd:'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('offshore.api_rate_limit'))->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }

    private function configureAudit(): void
    {
        // Enrich every audit entry with request metadata.
        Activity::saving(function (Activity $activity) {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                $activity->properties = $activity->properties->put('source', 'console');

                return;
            }

            $request = request();
            $activity->properties = $activity->properties->merge(array_filter([
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'request_id' => $request->attributes->get('request_id'),
            ]));
        });
    }

    private function configurePasswords(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers());

        ResetPassword::createUrlUsing(function (User $user, string $token) {
            return rtrim((string) config('offshore.frontend_url'), '/')
                .'/reset-password?token='.$token.'&email='.urlencode($user->email);
        });
    }
}
