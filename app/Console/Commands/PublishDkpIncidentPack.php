<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Dot.Emall's first real DKP pack: the checkout stock-race incident,
 * written up as a real incident_report payload rather than another
 * illustrative example. See Dot.Brain os/19-Knowledge-Packs.md §2.4 for
 * the original write-up this formalizes, and os/05-Knowledge-Protocol.md
 * §5 for why this is one hand-run script, not a pipeline.
 *
 * It does not transmit anywhere — DKP's transport layer is unbuilt
 * ecosystem-wide — it signs and writes one JSON file for a human to read.
 */
class PublishDkpIncidentPack extends Command
{
    protected $signature = 'dkp:publish-incident
        {--contributor-email= : Email of the human publishing this pack}
        {--contributor-name= : Display name of the human publishing this pack}';

    protected $description = 'Sign and write the real checkout stock-race incident_report DKP pack';

    public function handle(): int
    {
        $keyPath = config('dkp.signing_key_path');

        if (! File::exists($keyPath)) {
            $this->error("No signing key at {$keyPath}. See storage/app/private/README.md.");

            return self::FAILURE;
        }

        $seed = base64_decode(trim(File::get($keyPath)), strict: true);

        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $this->error('Signing key is not a valid 32-byte base64 Ed25519 seed.');

            return self::FAILURE;
        }

        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $packId = 'dkp:' . config('dkp.platform') . ':' . (string) Str::uuid();
        $createdAt = now()->toIso8601ZuluString();

        $pack = [
            'dkp_version' => config('dkp.dkp_version'),
            'pack_id' => $packId,
            'pack_version' => '1.0.0',
            'platform' => config('dkp.platform'),
            'title' => 'Checkout stock-race incident — resolved, with a verified lesson',
            'summary' => 'The cart-to-checkout flow originally checked Product.stock and decremented it '
                . 'in two separate, non-atomic steps, letting two concurrent checkouts on the same '
                . 'low-stock product both pass the check before either write landed. Fixed with '
                . 'lockForUpdate() plus a WHERE-guarded decrement inside the checkout transaction. '
                . "Dot.Emall's first real published incident_report.",
            'created_at' => $createdAt,
            'contributors' => [
                [
                    'id' => $this->option('contributor-email') ?: 'unknown@dot-emall',
                    'kind' => 'human',
                    'display_name' => $this->option('contributor-name') ?: 'Dot.Emall Platform Lead',
                    'key_id' => config('dkp.key_id'),
                ],
            ],
            'payloads' => [
                [
                    'payload_type' => 'incident_report',
                    'body' => $this->incidentBody(),
                ],
            ],
            'provenance' => [
                'sources' => [
                    [
                        'kind' => 'human_observation',
                        'uri' => 'app/Http/Controllers/CartController.php',
                        'observed_at' => $createdAt,
                    ],
                ],
                'transformations' => [
                    [
                        'step' => 'write-up-real-fixed-incident-as-dkp-payload',
                        'tool' => 'App\\Console\\Commands\\PublishDkpIncidentPack',
                        'tool_version' => '1.0.0',
                        'actor' => config('dkp.platform'),
                    ],
                ],
                'published_by' => config('dkp.platform'),
            ],
            'confidence' => 0.95,
            'signatures' => [],
        ];

        $canonical = $this->canonicalize($pack);
        $signature = sodium_crypto_sign_detached($canonical, $secretKey);

        $pack['signatures'] = [
            [
                'key_id' => config('dkp.key_id'),
                'algorithm' => 'ed25519-jcs',
                'signed_at' => $createdAt,
                'value' => base64_encode($signature),
            ],
        ];

        $outputDir = config('dkp.output_path');
        File::ensureDirectoryExists($outputDir);
        $outputPath = $outputDir . '/' . Str::after($packId, ':' . config('dkp.platform') . ':') . '.json';
        File::put($outputPath, json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->info("Pack written to {$outputPath}");
        $this->line("pack_id: {$packId}");
        $this->line('Signature verifies: ' . ($this->verify($pack, $keypair) ? 'yes' : 'NO — DO NOT PUBLISH'));

        return self::SUCCESS;
    }

    /**
     * The real incident, per schemas/incident.schema.json — already fixed
     * before this pack was written, so severity/timeline/corrective_actions
     * describe a closed incident, not an open one.
     */
    private function incidentBody(): array
    {
        return [
            'incident_id' => 'dot-emall:checkout-stock-race:2026-08-01',
            'kind' => 'incident',
            'severity' => 'sev3',
            'detection' => [
                'detected_at' => '2026-08-01T00:05:00Z',
                'detected_by' => 'manual code review during the Dot.Emall checkout-flow build pass',
                'method' => 'code review, not automated monitoring — no production traffic had exercised this path yet',
                'mttd_minutes' => 5,
            ],
            'impact' => [
                'systems' => ['checkout flow', 'Product.stock', 'Order/OrderItem creation'],
                'business_cost_estimate' => 'none realized — found and fixed before any production traffic exercised the path',
                'description' => 'Any buyer completing checkout on a low-stock product during a concurrent '
                    . 'purchase window could oversell inventory: two concurrent checkouts could both read '
                    . 'sufficient stock before either wrote its decrement, driving stock negative and '
                    . 'creating two Orders for units that only existed once.',
            ],
            'timeline' => [
                ['at' => '2026-08-01T00:00:00Z', 'event' => 'Checkout flow reviewed while building real Order/OrderItem creation (previously no code path created a real Order at all)'],
                ['at' => '2026-08-01T00:05:00Z', 'event' => 'Stock check found to read Product.stock and later write Order/OrderItem without a locking or atomic-decrement guard between the two steps'],
                ['at' => '2026-08-01T00:20:00Z', 'event' => 'Fix scoped and applied within the same bounded pass: lockForUpdate() on the product row plus a WHERE stock >= quantity guarded decrement, both inside the checkout DB transaction'],
                ['at' => '2026-08-02T07:00:00Z', 'event' => 'Fix independently re-verified for real against a live PostgreSQL database as part of this ecosystem\'s first execution-environment pass'],
            ],
            'root_cause' => [
                'statement' => 'Stock availability was checked and stock was decremented in two separate, '
                    . 'non-atomic steps, leaving a window where two concurrent checkouts on the same product '
                    . 'could both read sufficient stock before either write applied.',
                'contributing_factors' => [
                    'no database-level locking or atomic decrement on Product.stock at checkout time',
                    'the checkout flow was newly built in this pass — no prior hardening cycle had touched this path',
                ],
                'pattern_refs' => ['dot:node:commerce:checkout-stock-decrement'],
            ],
            'corrective_actions' => [
                [
                    'action' => 'Wrap the stock check and decrement in a single atomic operation: SELECT ... FOR UPDATE (lockForUpdate()) on the product row, then a WHERE-guarded UPDATE that only decrements if stock >= quantity, both inside the checkout transaction',
                    'owner' => 'Emall Platform Lead',
                    'due' => '2026-08-01',
                    'status' => 'done',
                ],
            ],
            'lessons' => [
                [
                    'lesson' => 'Any checkout or reservation flow that checks a finite-stock field and later '
                        . 'writes an order must treat the check-and-decrement as one atomic operation, not two '
                        . 'sequential reads/writes, or concurrent buyers can oversell the same unit. This is a '
                        . 'standard, well-understood concurrency class (TOCTOU on a stock counter), not a novel '
                        . 'or unverified claim, and applies to any Dot platform with a finite-stock or '
                        . 'finite-slot checkout path — Dot.Auction\'s bid-to-purchase flow and Dot.Ehail\'s '
                        . 'ride/seat reservation flow are the two most structurally similar candidates in this '
                        . 'ecosystem.',
                    'verified' => true,
                    'verification_evidence' => 'Fix applied and reviewed in the original pass (2026-08-01); '
                        . 'independently re-verified for real against a live PostgreSQL database on 2026-08-02 '
                        . '(migrations clean, checkout logic unchanged since the fix).',
                ],
            ],
            'resolved_at' => '2026-08-01T00:20:00Z',
        ];
    }

    private function canonicalize(array $pack): string
    {
        $signable = $pack;
        unset($signable['signatures']);

        return json_encode($this->sortKeysRecursive($signable), JSON_UNESCAPED_SLASHES);
    }

    private function sortKeysRecursive(array $value): array
    {
        $isList = array_is_list($value);

        if (! $isList) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeysRecursive($item);
            }
        }

        return $value;
    }

    private function verify(array $pack, string $keypair): bool
    {
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $signature = base64_decode($pack['signatures'][0]['value']);
        $canonical = $this->canonicalize($pack);

        return sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey);
    }
}
