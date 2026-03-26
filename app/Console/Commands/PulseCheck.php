<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Output\StreamOutput;

class PulseCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pulse:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {

            \ob_start();
            $stream = fopen("php://output", "w");
            Artisan::call('horizon:status', [], new StreamOutput($stream));
            $status = ob_get_clean();

            if (strpos($status, 'inactive') > -1) {
                $this->report();
            }

            \ob_start();
            $stream = fopen("php://output", "w");
            Artisan::call('horizon:supervisors', [], new StreamOutput($stream));
            $status = ob_get_clean();

            if (strpos($status, 'No supervisors') > -1) {
                $this->report();
            }

        } catch (\Exception $e) {
            $this->report();
        }
    }

    protected function report()
    {
        Log::critical("Horizon is down");

//        Mail::raw('Internetvergelijk.nl Horizon is down | supervisors not running', function($message) {
//            $message->to('contact@valso.nl')
//                ->from('server@internetvergelijk.nl', 'Internetvergelijk')
//                ->subject('Internetvergelijk.nl Horizon is down | supervisors not running');
//        });

        Artisan::call('horizon');
    }
}
