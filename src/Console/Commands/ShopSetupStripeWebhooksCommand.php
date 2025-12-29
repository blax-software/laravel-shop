<?php

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;
use Stripe\Stripe;
use Stripe\WebhookEndpoint;

class ShopSetupStripeWebhooksCommand extends Command
{
    protected $signature = 'shop:setup-stripe-webhooks 
                            {--url= : The webhook URL (defaults to APP_URL/api/shop/stripe/webhook)}
                            {--list : List existing webhooks instead of creating}
                            {--delete= : Delete a webhook by ID}
                            {--update= : Update an existing webhook by ID}';

    protected $description = 'Setup Stripe webhook endpoints for the shop package';

    /**
     * The webhook events that the shop package needs to receive
     */
    protected array $requiredEvents = [
        // Checkout Session Events
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',

        // Charge Events
        'charge.succeeded',
        'charge.failed',
        'charge.refunded',
        'charge.dispute.created',
        'charge.dispute.closed',

        // Payment Intent Events
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',

        // Refund Events
        'refund.created',
        'refund.updated',

        // Invoice Events (for subscriptions)
        'invoice.payment_succeeded',
        'invoice.payment_failed',
    ];

    public function handle(): int
    {
        if (!config('shop.stripe.enabled')) {
            $this->error('Stripe is not enabled. Please set STRIPE_ENABLED=true in your .env file.');
            return Command::FAILURE;
        }

        $stripeSecret = config('services.stripe.secret');
        if (!$stripeSecret) {
            $this->error('Stripe secret key is not configured. Please set STRIPE_SECRET in your .env file.');
            return Command::FAILURE;
        }

        Stripe::setApiKey($stripeSecret);

        // Handle different operations
        if ($this->option('list')) {
            return $this->listWebhooks();
        }

        if ($webhookId = $this->option('delete')) {
            return $this->deleteWebhook($webhookId);
        }

        if ($webhookId = $this->option('update')) {
            return $this->updateWebhook($webhookId);
        }

        return $this->createWebhook();
    }

    /**
     * List all existing webhook endpoints
     */
    protected function listWebhooks(): int
    {
        $this->info('Fetching existing Stripe webhooks...');

        try {
            $webhooks = WebhookEndpoint::all(['limit' => 100]);

            if (empty($webhooks->data)) {
                $this->warn('No webhook endpoints found.');
                return Command::SUCCESS;
            }

            $rows = [];
            foreach ($webhooks->data as $webhook) {
                $rows[] = [
                    $webhook->id,
                    $webhook->url,
                    $webhook->status,
                    count($webhook->enabled_events) . ' events',
                    $webhook->livemode ? 'Live' : 'Test',
                ];
            }

            $this->table(
                ['ID', 'URL', 'Status', 'Events', 'Mode'],
                $rows
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to list webhooks: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Delete a webhook endpoint
     */
    protected function deleteWebhook(string $webhookId): int
    {
        $this->info("Deleting webhook: {$webhookId}");

        try {
            $webhook = WebhookEndpoint::retrieve($webhookId);
            $webhook->delete();

            $this->info('✓ Webhook deleted successfully.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to delete webhook: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Update an existing webhook endpoint with the required events
     */
    protected function updateWebhook(string $webhookId): int
    {
        $this->info("Updating webhook: {$webhookId}");

        try {
            $webhook = WebhookEndpoint::retrieve($webhookId);
            $webhook->update($webhookId, [
                'enabled_events' => $this->requiredEvents,
            ]);

            $this->info('✓ Webhook updated successfully with ' . count($this->requiredEvents) . ' events.');
            $this->displayEvents();

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to update webhook: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Create a new webhook endpoint
     */
    protected function createWebhook(): int
    {
        $url = $this->option('url') ?? $this->getDefaultWebhookUrl();

        $this->info('Creating Stripe webhook endpoint...');
        $this->line("URL: {$url}");
        $this->newLine();

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('Invalid webhook URL. Please provide a valid URL.');
            return Command::FAILURE;
        }

        if (str_starts_with($url, 'http://') && !str_contains($url, 'localhost')) {
            $this->warn('Warning: Stripe recommends using HTTPS for webhook endpoints in production.');
            if (!$this->confirm('Do you want to continue with HTTP?')) {
                return Command::FAILURE;
            }
        }

        // Check for existing webhooks with same URL
        try {
            $existingWebhooks = WebhookEndpoint::all(['limit' => 100]);
            foreach ($existingWebhooks->data as $webhook) {
                if ($webhook->url === $url) {
                    $this->warn("A webhook with this URL already exists (ID: {$webhook->id})");
                    if ($this->confirm('Do you want to update it instead?')) {
                        return $this->updateWebhook($webhook->id);
                    }
                    if (!$this->confirm('Do you want to create a new one anyway?')) {
                        return Command::SUCCESS;
                    }
                }
            }
        } catch (\Exception $e) {
            // Continue if we can't check existing webhooks
        }

        // Display events to be registered
        $this->info('Events to be registered:');
        $this->displayEvents();
        $this->newLine();

        if (!$this->confirm('Do you want to create this webhook endpoint?')) {
            $this->info('Aborted.');
            return Command::SUCCESS;
        }

        try {
            $webhook = WebhookEndpoint::create([
                'url' => $url,
                'enabled_events' => $this->requiredEvents,
                'description' => 'Laravel Shop package webhook endpoint',
            ]);

            $this->newLine();
            $this->info('✓ Webhook endpoint created successfully!');
            $this->newLine();

            $this->table(
                ['Property', 'Value'],
                [
                    ['Webhook ID', $webhook->id],
                    ['URL', $webhook->url],
                    ['Status', $webhook->status],
                    ['Mode', $webhook->livemode ? 'Live' : 'Test'],
                    ['Events', count($webhook->enabled_events)],
                ]
            );

            $this->newLine();
            $this->warn('⚠ IMPORTANT: Copy the webhook signing secret below and add it to your .env file:');
            $this->newLine();
            $this->line("STRIPE_WEBHOOK_SECRET={$webhook->secret}");
            $this->newLine();

            $this->info('Add this to your config/shop.php or config/services.php:');
            $this->line("'stripe' => [");
            $this->line("    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),");
            $this->line("],");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create webhook: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get the default webhook URL based on APP_URL
     */
    protected function getDefaultWebhookUrl(): string
    {
        $appUrl = rtrim(config('app.url'), '/');
        $routePrefix = config('shop.routes.prefix', 'api/shop');

        return "{$appUrl}/{$routePrefix}/stripe/webhook";
    }

    /**
     * Display the list of events in a formatted way
     */
    protected function displayEvents(): void
    {
        $groups = [
            'Checkout Session' => array_filter($this->requiredEvents, fn($e) => str_starts_with($e, 'checkout.session.')),
            'Charge' => array_filter($this->requiredEvents, fn($e) => str_starts_with($e, 'charge.')),
            'Payment Intent' => array_filter($this->requiredEvents, fn($e) => str_starts_with($e, 'payment_intent.')),
            'Refund' => array_filter($this->requiredEvents, fn($e) => str_starts_with($e, 'refund.')),
            'Invoice' => array_filter($this->requiredEvents, fn($e) => str_starts_with($e, 'invoice.')),
        ];

        foreach ($groups as $group => $events) {
            $this->line("  <comment>{$group}:</comment>");
            foreach ($events as $event) {
                $this->line("    • {$event}");
            }
        }
    }
}
