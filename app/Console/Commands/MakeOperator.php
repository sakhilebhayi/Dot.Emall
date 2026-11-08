<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The way to create the first (and any) operator: an existing account, by e-mail, from the server. The operator desk can grant and
 * revoke the role afterwards, with a reason and an audit entry; this command writes none, because it is run by whoever holds the server.
 */
class MakeOperator extends Command
{
    protected $signature = 'emall:operator {email : The e-mail of an existing account} {--revoke : Remove operator access instead}';

    protected $description = 'Make an existing account a platform operator (or remove it with --revoke)';

    public function handle(): int
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $this->argument('email'))])->first();

        if ($user === null) {
            $this->error('No account has that e-mail address. The person signs in through InfoDot once first, which creates it.');

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_operator' => ! $this->option('revoke')])->save();
        $this->info($user->name.($this->option('revoke') ? ' is no longer an operator.' : ' is now an operator.'));

        return self::SUCCESS;
    }
}
