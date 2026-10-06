<?php

namespace Pterodactyl\Uptime\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeEvent;
use Pterodactyl\Uptime\Protocol\UptimeFormula;

/**
 * Recomputes the derived outages from the signed events with the published formula
 * and compares them with the stored ones. --apply replaces the derived rows; events
 * are never touched.
 */
class RebuildOutagesCommand extends Command
{
    protected $signature = 'uptime:rebuild-outages {--node= : Public id of one node} {--apply : Replace the stored outages}';

    protected $description = 'Recompute uptime outages from the event chain and compare with the stored ones.';

    public function handle(): int
    {
        $nodes = UptimeNode::query()->when($this->option('node'), fn ($q, $id) => $q->where('public_id', $id))->get();
        $different = false;
        foreach ($nodes as $node) {
            $expected = [];
            $previous = null;
            foreach (UptimeEvent::query()->where('uptime_node_id', $node->id)->orderBy('id')->lazyById(1000) as $event) {
                if ($previous) {
                    foreach (UptimeFormula::gapOutages($previous->point(), $event->point(), $node->params()) as $o) {
                        $expected[] = ['start_ms' => $o['start'], 'end_ms' => $o['end'], 'cause' => $o['cause'], 'before_event_id' => $event->id];
                    }
                }
                $previous = $event;
            }
            $stored = DB::table('uptime_outages')->where('uptime_node_id', $node->id)->orderBy('before_event_id')->orderBy('start_ms')
                ->get(['start_ms', 'end_ms', 'cause', 'before_event_id'])
                ->map(fn ($o) => ['start_ms' => (int) $o->start_ms, 'end_ms' => (int) $o->end_ms, 'cause' => $o->cause, 'before_event_id' => (int) $o->before_event_id])->all();

            if ($stored === $expected) {
                $this->line("<info>MATCH</info>   {$node->public_id}: " . count($expected) . ' outages');
                continue;
            }
            $different = true;
            $this->line("<error>DIFFERS</error> {$node->public_id}: stored " . count($stored) . ', recomputed ' . count($expected));
            if ($this->option('apply')) {
                DB::transaction(function () use ($node, $expected) {
                    DB::table('uptime_outages')->where('uptime_node_id', $node->id)->delete();
                    foreach (array_chunk($expected, 500) as $chunk) {
                        DB::table('uptime_outages')->insert(array_map(fn ($o) => $o + ['uptime_node_id' => $node->id, 'created_at' => now()], $chunk));
                    }
                });
                $this->line('        replaced with the recomputed outages.');
            }
        }

        return $different && !$this->option('apply') ? 1 : 0;
    }
}
