<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class GenerateStaticSite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'site:publish';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build the website to be viewed statically';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Artisan::call('export');
    }
}
