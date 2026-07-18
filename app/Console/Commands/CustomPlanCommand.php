<?php

namespace App\Console\Commands;

use App\Models\Management\Plan;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use Illuminate\Console\Command;

/**
 * Manage custom (non-public) plans for agencies and subsidized deals. Custom
 * plans never appear in the public plan list; they are offered only to spaces
 * they have been granted to, where they show up in the plan picker and can be
 * checked out like any other plan (via their own LemonSqueezy variants).
 *
 * Per-space quota tweaks on top of any plan are possible with `--override`,
 * which writes the subscription-level quota override.
 */
class CustomPlanCommand extends Command
{
    protected $signature = 'plans:custom
        {action : create | grant | revoke | list | override}
        {--name= : Plan name (create)}
        {--price= : Monthly price, e.g. 29.00 (create)}
        {--yearly-price= : Yearly price (create, optional)}
        {--quotas= : JSON quotas, e.g. \'{"requests":1000000,"traffic":536870912000,"storage":53687091200,"aiCredit":25}\' (create/override)}
        {--product-id= : LemonSqueezy product ID (create)}
        {--variant-id= : LemonSqueezy monthly variant ID (create)}
        {--yearly-variant-id= : LemonSqueezy yearly variant ID (create)}
        {--plan= : Plan ID (grant/revoke)}
        {--space= : Space ID or slug (grant/revoke/override)}';

    protected $description = 'Create and grant custom (agency/subsidized) plans';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'create' => $this->create(),
            'grant' => $this->grant(),
            'revoke' => $this->revoke(),
            'list' => $this->listCustom(),
            'override' => $this->override(),
            default => $this->invalidAction(),
        };
    }

    private function create(): int
    {
        $name = $this->option('name') ?? $this->ask('Plan name');
        $price = $this->option('price') ?? $this->ask('Monthly price (e.g. 29.00)', '0.00');
        $quotas = $this->parseQuotas($this->option('quotas'));

        if ($quotas === false) {
            return self::FAILURE;
        }

        $plan = Plan::create([
            'name' => ['en' => $name, 'default' => $name],
            'description' => null,
            'features' => null,
            'price' => $price,
            'yearly_price' => $this->option('yearly-price'),
            'period' => 'month',
            'quotas' => $quotas,
            'ls_product_id' => $this->option('product-id'),
            'ls_variant_id' => $this->option('variant-id'),
            'ls_variant_id_yearly' => $this->option('yearly-variant-id'),
            'is_free' => (float) $price === 0.0 && $this->option('variant-id') === null,
            'is_public' => false,
            'is_active' => true,
            'sort_order' => 1000,
        ]);

        $this->info("Created custom plan {$plan->id} ({$name}).");
        $this->line('Grant it to a space with: php artisan plans:custom grant --plan='.$plan->id.' --space=<id>');

        return self::SUCCESS;
    }

    private function grant(): int
    {
        [$plan, $space] = $this->resolvePlanAndSpace();

        if (! $plan || ! $space) {
            return self::FAILURE;
        }

        $plan->spaces()->syncWithoutDetaching([$space->id]);
        $this->info("Granted plan '{$plan->getTranslatedName()}' to space {$space->id} ({$space->name}).");

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        [$plan, $space] = $this->resolvePlanAndSpace();

        if (! $plan || ! $space) {
            return self::FAILURE;
        }

        $plan->spaces()->detach($space->id);
        $this->info("Revoked plan '{$plan->getTranslatedName()}' from space {$space->id}.");

        return self::SUCCESS;
    }

    private function listCustom(): int
    {
        $plans = Plan::where('is_public', false)->with('spaces:id,name')->orderBy('created_at')->get();

        if ($plans->isEmpty()) {
            $this->warn('No custom plans.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Price', 'Yearly', 'Active', 'Granted to'],
            $plans->map(fn (Plan $p) => [
                $p->id,
                $p->getTranslatedName(),
                "€{$p->price}",
                $p->yearly_price ? "€{$p->yearly_price}" : '-',
                $p->is_active ? 'yes' : 'no',
                $p->spaces->pluck('name')->implode(', ') ?: '-',
            ])->toArray()
        );

        return self::SUCCESS;
    }

    /**
     * Write (or clear) a subscription-level quota override for a space's
     * current subscription — the subsidized-deal knob on top of any plan.
     */
    private function override(): int
    {
        $space = $this->resolveSpace();

        if (! $space) {
            return self::FAILURE;
        }

        $subscription = $space->resolveCurrentSubscription();

        if (! $subscription instanceof Subscription || ! $subscription->isActive()) {
            $this->error('The space has no active subscription.');

            return self::FAILURE;
        }

        $raw = $this->option('quotas');

        if ($raw === null || $raw === 'null' || $raw === '') {
            $subscription->update(['quotas' => null]);
            $this->info('Cleared the quota override; plan defaults apply.');

            return self::SUCCESS;
        }

        $quotas = $this->parseQuotas($raw);

        if ($quotas === false) {
            return self::FAILURE;
        }

        $subscription->update(['quotas' => $quotas]);
        $this->info('Quota override saved: '.json_encode($quotas));

        return self::SUCCESS;
    }

    /** @return array{0: ?Plan, 1: ?Space} */
    private function resolvePlanAndSpace(): array
    {
        $plan = Plan::find($this->option('plan'));

        if (! $plan) {
            $this->error('Plan not found. Pass --plan=<id> (see plans:custom list).');
        }

        return [$plan, $this->resolveSpace()];
    }

    private function resolveSpace(): ?Space
    {
        $arg = $this->option('space');
        $space = $arg
            ? Space::where('id', $arg)->orWhere('slug', $arg)->first()
            : null;

        if (! $space) {
            $this->error('Space not found. Pass --space=<id or slug>.');
        }

        return $space;
    }

    private function parseQuotas(?string $raw): array|false|null
    {
        if ($raw === null) {
            return null;
        }

        $quotas = json_decode($raw, true);

        if (! \is_array($quotas)) {
            $this->error('Invalid --quotas JSON.');

            return false;
        }

        $allowed = ['requests', 'traffic', 'storage', 'aiCredit'];
        $unknown = array_diff(array_keys($quotas), $allowed);

        if ($unknown !== []) {
            $this->error('Unknown quota keys: '.implode(', ', $unknown).'. Allowed: '.implode(', ', $allowed));

            return false;
        }

        return $quotas;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action. Use: create | grant | revoke | list | override');

        return self::FAILURE;
    }
}
