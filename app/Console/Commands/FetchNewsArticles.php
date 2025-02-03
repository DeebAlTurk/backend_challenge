<?php

namespace App\Console\Commands;

use App\Services\NewsAggregatorService;
use Illuminate\Console\Command;

class FetchNewsArticles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'news:fetch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will add new articles to the database based on the API we have';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $newsAggregator = new NewsAggregatorService();
        if ($newsAggregator->fetchAllNews()){
            $this->info("News articles updated successfully!");
            return Command::SUCCESS;
        }
        $this->error("News articles didnt update");
        return Command::FAILURE;
    }
}
