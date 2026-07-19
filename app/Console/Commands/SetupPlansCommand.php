<?php

namespace App\Console\Commands;

use App\Models\Management\Plan;
use Illuminate\Console\Command;

class SetupPlansCommand extends Command
{
    protected $signature = 'plans:setup
        {--list : List existing plans and exit}
        {--link : Interactively link plans to LemonSqueezy product/variant IDs}
        {--force : Overwrite existing plan data with defaults}';

    protected $description = 'Bootstrap default plans and optionally link them to LemonSqueezy';

    /**
     * Default plan definitions.
     * quotas: traffic (bytes/mo), storage (bytes), aiCredit (USD spend/mo) — API requests are unlimited on every plan
     * aiCredit is the monthly OpenRouter spend cap in dollars for the space's key.
     * null quotas = unlimited tier. Adjust the dollar amounts to your real pricing.
     */
    private function defaultPlans(): array
    {
        $GB = 1024 * 1024 * 1024;
        $MB = 1024 * 1024;

        return [
            [
                'name' => ['en' => 'Free', 'de' => 'Kostenlos', 'default' => 'Free'],
                'description' => [
                    'en' => 'For personal projects and landing pages',
                    'de' => 'Für persönliche Projekte und Landing Pages',
                    'default' => 'For personal projects and landing pages',
                ],
                'features' => [
                    'en' => [
                        'Unlimited API requests',
                        '5 GB traffic (fair use)',
                        '500 MB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '5,000 AI tokens / month',
                    ],
                    'de' => [
                        'Unbegrenzte API-Anfragen',
                        '5 GB Datenvolumen (Fair Use)',
                        '500 MB Asset-Speicher',
                        'Unbegrenzte Blocks, Inhalte, Nutzer, Sprachen',
                        '5.000 KI-Token / Monat',
                    ],
                    'default' => [
                        'Unlimited API requests',
                        '5 GB traffic (fair use)',
                        '500 MB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '5,000 AI tokens / month',
                    ],
                ],
                'price' => '0.00',
                'period' => 'month',
                'quotas' => [
                    'traffic' => 5 * $GB,
                    'storage' => 500 * $MB,
                    'aiCredit' => 1.0,
                ],
                'is_free' => true,
                'sort_order' => 10,
            ],
            [
                'name' => ['en' => 'Essential', 'de' => 'Essential', 'default' => 'Essential'],
                'description' => [
                    'en' => 'For small teams',
                    'de' => 'Für kleine Teams',
                    'default' => 'For small teams',
                ],
                'features' => [
                    'en' => [
                        'Unlimited API requests',
                        '50 GB traffic (fair use)',
                        '5 GB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '100,000 AI tokens / month',
                    ],
                    'de' => [
                        'Unbegrenzte API-Anfragen',
                        '50 GB Datenvolumen (Fair Use)',
                        '5 GB Asset-Speicher',
                        'Unbegrenzte Blocks, Inhalte, Nutzer, Sprachen',
                        '100.000 KI-Token / Monat',
                    ],
                    'default' => [
                        'Unlimited API requests',
                        '50 GB traffic (fair use)',
                        '5 GB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '100,000 AI tokens / month',
                    ],
                ],
                'price' => '19.00',
                'yearly_price' => '190.00',
                'period' => 'month',
                'quotas' => [
                    'traffic' => 50 * $GB,
                    'storage' => 5 * $GB,
                    'aiCredit' => 5.0,
                ],
                'is_free' => false,
                'sort_order' => 20,
            ],
            [
                'name' => ['en' => 'Growth', 'de' => 'Growth', 'default' => 'Growth'],
                'description' => [
                    'en' => 'For growing businesses',
                    'de' => 'Für wachsende Unternehmen',
                    'default' => 'For growing businesses',
                ],
                'features' => [
                    'en' => [
                        'Unlimited API requests',
                        '250 GB traffic (fair use)',
                        '25 GB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '500,000 AI tokens / month',
                        'Email support',
                    ],
                    'de' => [
                        'Unbegrenzte API-Anfragen',
                        '250 GB Datenvolumen (Fair Use)',
                        '25 GB Asset-Speicher',
                        'Unbegrenzte Blocks, Inhalte, Nutzer, Sprachen',
                        '500.000 KI-Token / Monat',
                        'E-Mail-Support',
                    ],
                    'default' => [
                        'Unlimited API requests',
                        '250 GB traffic (fair use)',
                        '25 GB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '500,000 AI tokens / month',
                        'Email support',
                    ],
                ],
                'price' => '49.00',
                'yearly_price' => '490.00',
                'period' => 'month',
                'quotas' => [
                    'traffic' => 250 * $GB,
                    'storage' => 25 * $GB,
                    'aiCredit' => 15.0,
                ],
                'is_free' => false,
                'is_recommended' => true,
                'sort_order' => 30,
            ],
            [
                'name' => ['en' => 'Pro', 'de' => 'Pro', 'default' => 'Pro'],
                'description' => [
                    'en' => 'For professional teams',
                    'de' => 'Für professionelle Teams',
                    'default' => 'For professional teams',
                ],
                'features' => [
                    'en' => [
                        'Unlimited API requests',
                        '500 GB traffic (fair use)',
                        '50 GB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '1,500,000 AI tokens / month',
                        '24-hour technical support',
                    ],
                    'de' => [
                        'Unbegrenzte API-Anfragen',
                        '500 GB Datenvolumen (Fair Use)',
                        '50 GB Asset-Speicher',
                        'Unbegrenzte Blocks, Inhalte, Nutzer, Sprachen',
                        '1.500.000 KI-Token / Monat',
                        '24h technischer Support',
                    ],
                    'default' => [
                        'Unlimited API requests',
                        '500 GB traffic (fair use)',
                        '50 GB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '1,500,000 AI tokens / month',
                        '24-hour technical support',
                    ],
                ],
                'price' => '99.00',
                'yearly_price' => '990.00',
                'period' => 'month',
                'quotas' => [
                    'traffic' => 500 * $GB,
                    'storage' => 50 * $GB,
                    'aiCredit' => 40.0,
                ],
                'is_free' => false,
                'sort_order' => 40,
            ],
            [
                'name' => ['en' => 'Scale', 'de' => 'Scale', 'default' => 'Scale'],
                'description' => [
                    'en' => 'For large organizations',
                    'de' => 'Für große Unternehmen',
                    'default' => 'For large organizations',
                ],
                'features' => [
                    'en' => [
                        'Unlimited API requests',
                        '1,000 GB traffic (fair use)',
                        '100 GB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '10,000,000 AI tokens / month',
                        'Dedicated account manager',
                    ],
                    'de' => [
                        'Unbegrenzte API-Anfragen',
                        '1.000 GB Datenvolumen (Fair Use)',
                        '100 GB Asset-Speicher',
                        'Unbegrenzte Blocks, Inhalte, Nutzer, Sprachen',
                        '10.000.000 KI-Token / Monat',
                        'Dedizierter Account Manager',
                    ],
                    'default' => [
                        'Unlimited API requests',
                        '1,000 GB traffic (fair use)',
                        '100 GB asset storage',
                        'Unlimited blocks, content, users, languages',
                        '10,000,000 AI tokens / month',
                        'Dedicated account manager',
                    ],
                ],
                'price' => '249.00',
                'yearly_price' => '2490.00',
                'period' => 'month',
                'quotas' => [
                    'traffic' => 1000 * $GB,
                    'storage' => 100 * $GB,
                    'aiCredit' => 150.0,
                ],
                'is_free' => false,
                'sort_order' => 50,
            ],
            [
                'name' => ['en' => 'Enterprise', 'de' => 'Enterprise', 'default' => 'Enterprise'],
                'description' => [
                    'en' => 'Custom solution for large organisations',
                    'de' => 'Individuelle Lösung für große Unternehmen',
                    'default' => 'Custom solution for large organisations',
                ],
                'features' => [
                    'en' => [
                        'Unlimited API requests',
                        'Unlimited traffic',
                        'Unlimited asset storage',
                        'Unlimited blocks, content, users, languages',
                        'Unlimited AI tokens',
                        'SLA & dedicated support',
                        'Custom integrations',
                    ],
                    'de' => [
                        'Unbegrenzte API-Anfragen',
                        'Unbegrenztes Datenvolumen',
                        'Unbegrenzter Asset-Speicher',
                        'Unbegrenzte Blocks, Inhalte, Nutzer, Sprachen',
                        'Unbegrenzte KI-Token',
                        'SLA & dedizierter Support',
                        'Individuelle Integrationen',
                    ],
                    'default' => [
                        'Unlimited API requests',
                        'Unlimited traffic',
                        'Unlimited asset storage',
                        'Unlimited blocks, content, users, languages',
                        'Unlimited AI tokens',
                        'SLA & dedicated support',
                        'Custom integrations',
                    ],
                ],
                'price' => '0.00',
                'period' => 'month',
                'quotas' => null,
                'contact_url' => 'https://www.b10cks.com/contact',
                'is_free' => false,
                'sort_order' => 60,
            ],
        ];
    }

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listPlans();
        }

        $this->upsertDefaultPlans();

        if ($this->option('link')) {
            $this->linkToLemonSqueezy();
        }

        $this->newLine();
        $this->listPlans();

        return 0;
    }

    private function upsertDefaultPlans(): void
    {
        $force = $this->option('force');
        $defaults = $this->defaultPlans();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($defaults as $data) {
            $nameDefault = $data['name']['default'];

            // Match by sort_order (stable identifier) or by default name
            $plan = Plan::where('sort_order', $data['sort_order'])->first()
                ?? Plan::whereJsonContains('name->default', $nameDefault)->first();

            if ($plan && ! $force) {
                $this->line("  <fg=gray>skip</>  {$nameDefault} (already exists, use --force to overwrite)");
                $skipped++;

                continue;
            }

            // Preserve existing LS IDs when updating with --force
            $lsProductId = $plan?->ls_product_id;
            $lsVariantId = $plan?->ls_variant_id;

            $plan = Plan::updateOrCreate(
                ['sort_order' => $data['sort_order']],
                array_merge($data, [
                    'ls_product_id' => $lsProductId,
                    'ls_variant_id' => $lsVariantId,
                    'is_active' => true,
                ])
            );

            if ($plan->wasRecentlyCreated) {
                $this->line("  <fg=green>create</> {$nameDefault}");
                $created++;
            } else {
                $this->line("  <fg=yellow>update</> {$nameDefault}");
                $updated++;
            }
        }

        $this->newLine();
        $this->info("Done: {$created} created, {$updated} updated, {$skipped} skipped.");
    }

    private function linkToLemonSqueezy(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>Link plans to LemonSqueezy variants</>');
        $this->line('Leave blank to keep current value. Find IDs at <fg=gray>app.lemonsqueezy.com/products</>.');
        $this->newLine();

        $paidPlans = Plan::where('is_free', false)->orderBy('sort_order')->get();

        if ($paidPlans->isEmpty()) {
            $this->warn('No paid plans found.');

            return;
        }

        foreach ($paidPlans as $plan) {
            $name = $plan->name['default'] ?? $plan->name['en'] ?? 'Plan';
            $this->line("<fg=white;options=bold>{$name}</> (€{$plan->price}/{$plan->period})");

            $productId = $this->ask(
                "  Product ID [{$plan->ls_product_id}]",
                $plan->ls_product_id ?? ''
            );

            $variantId = $this->ask(
                "  Variant ID (monthly) [{$plan->ls_variant_id}]",
                $plan->ls_variant_id ?? ''
            );

            $yearlyVariantId = $this->ask(
                "  Variant ID (yearly) [{$plan->ls_variant_id_yearly}]",
                $plan->ls_variant_id_yearly ?? ''
            );

            $updates = [];
            if ($productId !== '' && $productId !== $plan->ls_product_id) {
                $updates['ls_product_id'] = $productId ?: null;
            }
            if ($variantId !== '' && $variantId !== $plan->ls_variant_id) {
                $updates['ls_variant_id'] = $variantId ?: null;
            }
            if ($yearlyVariantId !== '' && $yearlyVariantId !== $plan->ls_variant_id_yearly) {
                $updates['ls_variant_id_yearly'] = $yearlyVariantId ?: null;
            }

            if (! empty($updates)) {
                $plan->update($updates);
                $this->line('  <fg=green>saved</>');
            } else {
                $this->line('  <fg=gray>no changes</>');
            }

            $this->newLine();
        }
    }

    private function listPlans(): int
    {
        $plans = Plan::orderBy('sort_order')->get();

        if ($plans->isEmpty()) {
            $this->warn('No plans found. Run `php artisan plans:setup` to create defaults.');

            return 0;
        }

        $rows = $plans->map(fn (Plan $p) => [
            $p->id,
            $p->name['default'] ?? '-',
            $p->is_free ? 'free' : "€{$p->price}/{$p->period}",
            $p->is_active ? '<fg=green>active</>' : '<fg=gray>inactive</>',
            $p->ls_product_id ?? '<fg=gray>-</>',
            $p->ls_variant_id ?? '<fg=gray>-</>',
        ])->toArray();

        $this->table(
            ['ID', 'Name', 'Price', 'Status', 'LS Product ID', 'LS Variant ID'],
            $rows
        );

        return 0;
    }
}
