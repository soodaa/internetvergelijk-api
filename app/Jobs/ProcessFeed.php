<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use App\Models\Package;
use App\Models\Feed;
use GuzzleHttp\Client;
use Illuminate\Http\Exceptions\HttpResponseException;
use Storage;


class ProcessFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $feed;
    protected $packages;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Feed $feed)
    {
        $this->feed = $feed;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->packages = $this->getFeed();

        if ($this->savePackages()) {
            $this->feed->fetched_at = Carbon::now();
            $this->feed->save();
        }
    }

    private function getFeed()
    {
        if(is_null($this->feed->file)) {
            $csvFile = file_get_contents($this->feed->link);
        } else {
            try {
                $csvFile = Storage::get('public/'.$this->feed->file);
            }
            catch (Illuminate\Contracts\Filesystem\FileNotFoundException $exception) {
                die("The file doesn't exist");
            }
        }
        $lines = preg_split('/\r\n|\r|\n/', $csvFile);
        $array = [];
        $count = 0;
        $headers = [];
        $atts = $this->feed->getAttributes();

        foreach ($lines as $line) {
            if(substr_count($line, ',') > substr_count($line, ';')) {
                $line = str_getcsv($line);
            }else {
                $line = str_getcsv($line, ';');
            }
            if (count($line) > 1) {
                if ($count == 0) {
                    $headers = $line;
                    $count++;
                    continue;
                }
                if (count($headers) > 0) {
                    $lineArr = [];
                    $i = 0;
                    foreach($headers as $header) {
                        foreach($atts as $index => $value) {
                            if($value === $header) {
                                if($index == 'package_name') {
                                    $lineArr['name'] = $line[$i];
                                } else {
                                    $lineArr[$index] = $line[$i];
                                }
                            }
                        }
                        $i++;
                    }
                    // Add feed supplier
                    $lineArr['supplier_id'] = $this->feed->supplier_id;

                    $array[] = $lineArr;
                    $count++;
                } else {
                    break;
                }
            }
        }

        return $array;
    }

    private function savePackages()
    {
        if (Package::insert($this->packages)) {
            return true;
        }

        return false;
    }
}
