<?php

namespace Gismatul\SupportChat\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'support-chat:install {--force : Overwrite the published config file}';

    protected $description = 'Publish the support chat config, run its migrations and print the remaining setup steps';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'support-chat-config', '--force' => (bool) $this->option('force')]);
        $this->components->info('Published config/support-chat.php');

        if ($this->confirm('Run the database migrations now?', true)) {
            $this->call('migrate');
        }

        $secret = Str::random(48);

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  1. Create a bot with @BotFather, add it to your support group and disable its privacy mode');
        $this->line('     (/setprivacy -> Disable) so it can read operator replies.');
        $this->line('  2. Add to your .env:');
        $this->newLine();
        $this->line('     SUPPORT_CHAT_TELEGRAM_BOT_TOKEN=123456:ABC...');
        $this->line('     SUPPORT_CHAT_TELEGRAM_CHAT_ID=-100...');
        $this->line("     SUPPORT_CHAT_TELEGRAM_WEBHOOK_SECRET={$secret}");
        $this->newLine();
        $this->line('  3. Register the webhook (your app must be reachable over HTTPS):');
        $this->line('     php artisan support-chat:telegram-webhook');
        $this->line('  4. Add the widget before </body>:  <x-support-chat::widget />');
        $this->line('     or, on another frontend:  <script src="'.url(config('support-chat.routes.api.prefix').'/assets/support-chat.js').'"');
        $this->line('                                       data-config-url="'.url(config('support-chat.routes.api.prefix').'/config').'" defer></script>');
        $this->line('  5. Make sure the scheduler runs (php artisan schedule:run every minute).');
        $this->line('  6. Grant admin access: SupportChat::authorizeUsing(fn ($user, $ability) => ...) or install gismatul/nova-support-chat.');

        return self::SUCCESS;
    }
}
