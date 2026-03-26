<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Feed;
use App\Jobs\ProcessFeed;
use Carbon\Carbon;

class ProcessFeeds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feeds:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get and process all feeds';

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
     * @return mixed
     */
    public function handle()
    {
        // Get all feeds
        $feeds = Feed::all();

        // Dispatch a job for each one
        foreach($feeds as $feed) {
            if (Carbon::now()->isBefore(Carbon::parse($feed->fetched_at)->subHours($feed->delay)) || is_null($feed->fetched_at)) {
                ProcessFeed::dispatch($feed);
            }
        }
    }
}
