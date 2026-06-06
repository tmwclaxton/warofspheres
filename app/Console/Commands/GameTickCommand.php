<?php

namespace App\Console\Commands;

use App\Games\GameConstants;
use App\Games\Services\GameManager;
use Illuminate\Console\Command;

class GameTickCommand extends Command
{
    protected $signature = 'game:tick {--daemon : Run continuously}';

    protected $description = 'Run the War of Spheres simulation tick loop';

    public function handle(GameManager $gameManager): int
    {
        $frameTime = 1 / GameConstants::TICK_RATE;

        do {
            $started = microtime(true);

            foreach ($gameManager->activeGameUuids() as $uuid) {
                $game = $gameManager->findByUuid($uuid);

                if ($game) {
                    $gameManager->tick($game);
                }
            }

            $elapsed = microtime(true) - $started;
            $sleep = $frameTime - $elapsed;

            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        } while ($this->option('daemon'));

        return self::SUCCESS;
    }
}
