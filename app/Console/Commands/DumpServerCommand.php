<?php

namespace App\Console\Commands;

use App\Services\DumpFormatter;
use Illuminate\Console\Command;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Server\DumpServer;

/**
 * The Nexus dump receiver. Connected projects (VAR_DUMPER_FORMAT=server in
 * their .env) send every dump()/dd() payload here over TCP; we decode with
 * var-dumper's own DumpServer and emit one JSON object per stdout line.
 *
 * Runs as a NativePHP ChildProcess (never inside the single-threaded
 * `artisan serve`); the Electron bridge forwards each line to the Dumps panel.
 */
class DumpServerCommand extends Command
{
    protected $signature = 'nexus:dump-server {--host=127.0.0.1:9912}';

    protected $description = 'Listen for dump() payloads from connected projects, emitting JSON lines';

    public function handle(DumpFormatter $formatter): int
    {
        $host = (string) $this->option('host');
        $server = new DumpServer($host);

        try {
            $server->start();
        } catch (\RuntimeException $e) {
            // Usually "address already in use" — surface it to the panel.
            $this->emit(['type' => 'error', 'message' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->emit(['type' => 'ready', 'host' => $host]);

        $server->listen(function (Data $data, array $context) use ($formatter) {
            try {
                $this->emit($formatter->format($data, $context));
            } catch (\Throwable) {
                // One malformed payload must not kill the listener.
            }
        });

        return self::SUCCESS;
    }

    /** One JSON object per line; flushed so the Electron bridge sees it immediately. */
    private function emit(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload)."\n");
        fflush(STDOUT);
    }
}
