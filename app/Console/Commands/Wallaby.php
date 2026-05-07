<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class Wallaby extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallaby';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'display wallaby console';

    /**
     * Execute the following console commands.
     *
     * php artisan clear-compiled
     * php artisan optimize:clear
     * php artisan optimize
     * composer dump-autoload -o
     */
    public function handle()
    {
        $output = `


                                                         @@    @@
                                                         @@@@@@@              @@@@
                                                         @@@@@@@              @@@@
                                                @@@@@@@@@@@@@@@@              @@@@
        @@@@    @@@@@    @@@@@  @@@@@@@@@@    @@@@@@@@@@@@@@@@@@  @@@@@@@@@   @@@@ @@@@@@@  @@@@@     @@@@@
        @@@@@   @@@@@@   @@@@  @@@@@@@@@@@@  @@@@@@@@@@@@@@@@@@ @@@@@@@@@@@@  @@@@@@@@@@@@@@ @@@@@    @@@@@
         @@@@  @@@@@@@  @@@@@   @@@    @@@@  @@@@@@@@@@@@@@@@@@  @@@    @@@@@ @@@@@    @@@@@  @@@@   @@@@@
         @@@@@ @@@@@@@@ @@@@     @@@@@@@@@@  @@@@@@@@@@@@@@@@     @@@@@@@@@@@ @@@@      @@@@@ @@@@@  @@@@
          @@@@ @@@ @@@@ @@@@  @@@@@@@@@@@@@  @@@@@@@@@@@@@@@   @@@@@@@@@@@@@@ @@@@      @@@@@  @@@@ @@@@@
          @@@@@@@@  @@@@@@@   @@@@     @@@@  @@@@@@@@@@@@@@    @@@@     @@@@@ @@@@@    @@@@@    @@@@@@@@
           @@@@@@   @@@@@@@   @@@@@@@@@@@@@  @@@@@@@  @@@@     @@@@@@@@@@@@@@ @@@@@@@@@@@@@@    @@@@@@@
           @@@@@@    @@@@@     @@@@@@@@@@@@ @@@@ @@   @@@       @@@@@@@@@@@@@ @@@@ @@@@@@@       @@@@@@
                                          @@@@   @@@  @@@@                                       @@@@@
                                   @@@@@@@@      @@@@@@  @@@                                  @@@@@@@
                                                      @@@@@                                   @@@@@@

                        © WALLABY DESIGNS. ALL RIGHTS RESERVED. WALLABYDESIGNS.COM

	`;

        echo "<script>console.log($output);</script>";

    }
}
