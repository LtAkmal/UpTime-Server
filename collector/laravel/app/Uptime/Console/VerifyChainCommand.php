<?php

namespace Pterodactyl\Uptime\Console;

use Illuminate\Console\Command;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Services\ChainVerifier;

/**
 * Re-verifies stored event chains. Never modifies events. Without --record it is
 * read-only; with --record the result is cached on the node (public verification
 * state) and a new failure notifies administrators. Exit status 1 on any failure.
 */
class VerifyChainCommand extends Command
{
    protected $signature = 'uptime:verify-chain
        {--node= : Public id of one node}
        {--all : Verify every node}
        {--record : Store the result as the node\'s verification state}';

    protected $description = 'Verify signatures, sequences and hash links of uptime event chains.';

    public function handle(ChainVerifier $verifier): int
    {
        $query = UptimeNode::query()->orderBy('id');
        if ($id = $this->option('node')) {
            $query->where('public_id', $id);
        } elseif (!$this->option('all')) {
            $this->error('Use --node=<public-id> or --all.');

            return 2;
        }
        $nodes = $query->get();
        if ($nodes->isEmpty()) {
            $this->error('No matching node.');

            return 2;
        }

        $failed = false;
        foreach ($nodes as $node) {
            $result = $this->option('record') ? $verifier->record($node) : $verifier->verify($node);
            if ($result['valid']) {
                $this->line("<info>VALID</info>   {$node->public_id}: {$result['checked']} events verified (signatures, payload hashes, chain links, sequences, keys, timestamps).");
                continue;
            }
            $failed = true;
            $this->line("<error>INVALID</error> {$node->public_id}: first broken record is event #{$result['failed_event_id']} after {$result['checked']} valid events: {$result['reason']}");
        }

        return $failed ? 1 : 0;
    }
}
