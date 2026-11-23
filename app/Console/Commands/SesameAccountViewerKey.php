<?php

namespace App\Console\Commands;

use App\Models\SesameAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SesameAccountViewerKey extends Command
{
    protected $signature = 'sesame:account:viewer-key {name : Existing simulated account name}';

    protected $description = 'Issue a new viewer key that the client signs message-viewer links with, print it once, and invalidate the old one.';

    public function handle(): int
    {
        $account = SesameAccount::where('name', $this->argument('name'))->first();
        if ($account === null) {
            $this->error('No such simulated account.');

            return self::FAILURE;
        }
        $key = Str::random(64);
        $account->update(['viewer_key' => $key]);

        $this->line(str_pad('account_id', 12).$account->id);
        $this->line(str_pad('viewer_key', 12).$key);
        $this->line(str_pad('viewer_url', 12).rtrim((string) config('sesame.public_url'), '/').'/sesame/v1/accounts/'.$account->id.'/messages/{message_id}?expires={unix}&signature={hmac}');
        $this->warn('The key is shown once. signature = hex HMAC-SHA256(viewer_key, "{account_id}\n{message_id}\n{expires}"). Links signed with the old key stop working.');

        return self::SUCCESS;
    }
}
